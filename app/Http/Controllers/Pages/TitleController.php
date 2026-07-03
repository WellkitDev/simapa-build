<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\Author;
use App\Models\Journal;
use App\Models\Scope;
use App\Models\Title;
use App\Models\User;
use App\Services\TitleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class TitleController extends Controller
{
    public function __construct(private TitleService $service) {}

    private function canManage(): bool
    {
        return Auth::user()->hasAnyRole(['superadmin', 'manager', 'admin', 'production']);
    }

    private function isApprover(): bool
    {
        return Auth::user()->hasAnyRole(['superadmin', 'manager']);
    }

    public function index()
    {
        $query = Title::with(['creator', 'scope', 'assignedMarketing', 'orderDetails.titleProgress'])
            ->withCount('orderDetails as orders_count')
            ->withCount(['orderDetails as authors_count' => function ($q) {
                $q->join('tb_author_orders', 'tb_author_orders.order_detail_id', '=', 'tb_order_details.id');
            }])
            ->latest();
        if (! $this->canManage()) {
            // marketing: hanya disetujui, dan hanya yang tak di-assign (semua) atau di-assign ke dirinya
            $query->where('status', 'disetujui')
                ->where(function ($q) {
                    $q->whereNull('assigned_to')->orWhere('assigned_to', Auth::id());
                });
        }

        return view('titles.index', [
            'titles' => $query->get(),
            'canManage' => $this->canManage(),
            'isApprover' => $this->isApprover(),
        ]);
    }

    public function create()
    {
        abort_unless($this->canManage(), 403);
        return view('titles.form', [
            'title' => new Title(['jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => 'draft']),
            'scopes' => Scope::orderBy('scope')->get(),
            'marketers' => User::role('marketing')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->canManage(), 403);
        $data = $this->validateData($request);
        $this->service->create($data, $request->input('chapters', []), Auth::user());

        return redirect()->route('title.index')->with('success', 'Judul dibuat (draf).');
    }

    public function show(int $id)
    {
        $title = Title::with(['chapters.authors', 'creator', 'approver', 'scope', 'assignedMarketing', 'orderDetails.order.user', 'orderDetails.titleProgress', 'orderDetails.authors', 'journalOptions.journal', 'logs.changedBy', 'bookIsbn'])->findOrFail($id);
        abort_if(! $this->canManage() && ! $title->isApproved(), 403);
        // marketing tak boleh membuka judul yang di-assign ke marketing lain
        abort_if(! $this->canManage() && $title->assigned_to && $title->assigned_to !== Auth::id(), 403);

        // Pre-fill author bab dari author order (bab kosong saja) untuk buku pra-fitur — idempotent.
        if ($title->jenis === 'buku') {
            app(\App\Services\ChapterAuthorService::class)->seedFromOrders($title);
            $title->load('chapters.authors');
        }

        // Author dari order (level judul) — sumber kebenaran; artikel tampil otomatis dari sini.
        $orderAuthors = $title->orderDetails->flatMap->authors->unique('id')->values();

        $ordersCount = $title->orderDetails->count();
        $authorsCount = \App\Models\OrderDetail::where('title_id', $title->id)
            ->withCount('authors')->get()->sum('authors_count');

        return view('titles.show', [
            'title' => $title,
            'canManage' => $this->canManage(),
            'isApprover' => $this->isApprover(),
            'ordersCount' => $ordersCount,
            'authorsCount' => $authorsCount,
            'canViewInfo' => Auth::user()->hasAnyRole(['superadmin', 'manager', 'admin', 'production']),
            'canEditInfo' => Auth::user()->hasAnyRole(['superadmin', 'manager', 'admin']),
            'canOpenBoard' => Auth::user()->hasAnyRole(['superadmin', 'manager', 'production']),
            'journals' => Journal::orderBy('nama')->get(),
            'allAuthors' => Author::orderBy('name')->get(),
            'orderAuthors' => $orderAuthors,
            'canManageIsbn' => Auth::user()->hasAnyRole(['superadmin', 'manager', 'admin', 'production']),
        ]);
    }

    public function edit(int $id)
    {
        abort_unless($this->canManage(), 403);
        $title = Title::with('chapters')->findOrFail($id);
        abort_unless($title->isEditable(), 403);

        return view('titles.form', [
            'title' => $title,
            'scopes' => Scope::orderBy('scope')->get(),
            'marketers' => User::role('marketing')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, int $id)
    {
        abort_unless($this->canManage(), 403);
        $title = Title::findOrFail($id);
        abort_unless($title->isEditable(), 403);
        $data = $this->validateData($request);
        $this->service->update($title, $data, $request->input('chapters', []));

        return redirect()->route('title.show', $title->id)->with('success', 'Judul diperbarui.');
    }

    public function destroy(int $id)
    {
        $title = Title::findOrFail($id);
        $ownDraft = $title->created_by === Auth::id() && $title->status === 'draft';
        abort_unless($this->canManage() && ($ownDraft || Auth::user()->hasRole('superadmin')), 403);
        $title->delete();

        return redirect()->route('title.index')->with('success', 'Judul dihapus.');
    }

    public function submit(int $id)
    {
        abort_unless($this->canManage(), 403);
        $this->service->submit(Title::findOrFail($id), Auth::user());

        return back()->with('success', 'Judul diajukan.');
    }

    public function approve(int $id)
    {
        abort_unless($this->isApprover(), 403);
        $this->service->approve(Title::findOrFail($id), Auth::user());

        return back()->with('success', 'Judul disetujui.');
    }

    public function reject(Request $request, int $id)
    {
        abort_unless($this->isApprover(), 403);
        $data = $request->validate(['reject_note' => 'required|string']);
        $this->service->reject(Title::findOrFail($id), Auth::user(), $data['reject_note']);

        return back()->with('success', 'Judul ditolak.');
    }

    public function updateInfo(Request $request, int $id)
    {
        abort_unless(Auth::user()->hasAnyRole(['superadmin', 'manager', 'admin']), 403);
        $title = Title::findOrFail($id);

        $data = $request->validate([
            'code'                          => ['nullable', 'string', 'max:16', Rule::unique('tb_titles', 'code')->ignore($title->id)],
            'target_terbit'                 => 'nullable|date',
            'jurnal_target'                 => 'nullable|string|max:255',
            'jurnal_link'                   => 'nullable|string|max:255',
            'template_link'                 => 'nullable|string|max:255',
            'apc_info'                      => 'nullable|string|max:255',
            'catatan_publikasi'             => 'nullable|string',
            'journal_options'                  => 'nullable|array',
            'journal_options.*.journal_id'     => 'nullable|integer|exists:tb_journals,id',
            'journal_options.*.nama_jurnal'    => 'nullable|string|max:255',
            'journal_options.*.link'           => 'nullable|string|max:255',
            'journal_options.*.apc'            => 'nullable|string|max:255',
        ]);

        $this->service->updateInfo($title, $data, $request->input('journal_options', []), Auth::user());

        return redirect()->route('title.show', $title->id)->with('success', 'Informasi publikasi diperbarui.');
    }

    public function updateChapterAuthors(Request $request, int $id)
    {
        abort_unless(Auth::user()->hasAnyRole(['superadmin', 'manager', 'admin']), 403);
        $title = Title::findOrFail($id);

        $request->validate([
            'chapter_authors'     => 'nullable|array',
            'chapter_authors.*'   => 'nullable|array',
            'chapter_authors.*.*' => 'nullable|string',
        ]);

        app(\App\Services\ChapterAuthorService::class)->syncChapterAuthors($title, $request->input('chapter_authors', []));

        return redirect()->route('title.show', $title->id)->with('success', 'Author bab diperbarui.');
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'title'            => 'required|string|max:255',
            'jenis'            => 'required|in:artikel,buku',
            'indeksasi'        => 'nullable|string|max:64',
            'tipe_naskah'      => 'required|in:mandiri,kolaborasi',
            'scope_id'         => 'nullable|string|max:255',
            'assigned_to'      => 'nullable|integer|exists:users,id',
            'chapters'         => 'nullable|array',
            'chapters.*.judul' => 'nullable|string|max:255',
        ]);
    }
}
