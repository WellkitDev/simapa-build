<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\Author;
use App\Models\Journal;
use App\Models\Scope;
use App\Models\Title;
use App\Models\TitleLog;
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

    /**
     * Judul berstatus 'disetujui' hanya boleh diedit oleh himpunan role yang sama
     * dengan canEditInfo di show() — 'production' memegang title.edit di matriks akses
     * tapi tidak boleh menyentuh judul yang sudah disetujui.
     */
    private function canEditApproved(): bool
    {
        return Auth::user()->hasAnyRole(['superadmin', 'manager', 'admin']);
    }

    public function index(Request $request)
    {
        $showInactive = $request->boolean('inactive');

        $query = Title::with(['creator', 'scope', 'assignedMarketing', 'orderDetails.titleProgress'])
            ->withCount('orderDetails as orders_count')
            ->withCount(['orderDetails as authors_count' => function ($q) {
                $q->join('tb_author_orders', 'tb_author_orders.order_detail_id', '=', 'tb_order_details.id');
            }])
            // Dipakai Title::deleteBlockReason() di kolom Aksi: tanpa agregasi ini
            // tiap baris menembak tiga query sendiri (ratusan untuk satu halaman).
            ->withExists(['bookIsbn', 'archive'])
            ->when(! $showInactive, fn ($q) => $q->active())
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
            'showInactive' => $showInactive,
            'canEditApproved' => $this->canEditApproved(),
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
        $title = Title::with(['chapters.authors', 'creator', 'approver', 'scope', 'assignedMarketing', 'orderDetails.order.user', 'orderDetails.titleProgress', 'orderDetails.authors', 'journalOptions.journal', 'logs.changedBy', 'bookIsbn', 'docMarks', 'docChecklist'])->findOrFail($id);
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
            'docRequirements' => \App\Models\DocRequirement::active()->orderBy('position')->get()->groupBy('category'),
            'docMarks'        => $title->docMarks->keyBy('doc_requirement_id'),
            'docChecklist'    => $title->docChecklist,
            // Berkas pemenuh item otomatis, dikunci per requirement id supaya view tak
            // perlu tahu slot mana yang menjadi sumbernya.
            'docAutoFiles'    => \App\Models\DocRequirement::active()->whereNotNull('auto_source')->get()
                ->mapWithKeys(fn ($req) => [
                    $req->id => app(\App\Services\DocChecklistService::class)->autoFile($title, $req),
                ]),
            // Tautan ke Detail Naskah judul ini (halaman tempat berkas naskah diunggah).
            'naskahDetailId'  => $title->orderDetails->first()?->id,
            'docProgress'     => [
                'penerbit' => app(\App\Services\DocChecklistService::class)->progress($title, 'penerbit'),
                'hki'      => app(\App\Services\DocChecklistService::class)->progress($title, 'hki'),
            ],
            'canMarkDocs'     => Auth::user()->hasAnyRole(['superadmin', 'admin']),
            'canManageDocReq' => Auth::user()->hasRole('superadmin'),
        ]);
    }

    public function edit(int $id)
    {
        abort_unless($this->canManage(), 403);
        $title = Title::with('chapters')->findOrFail($id);
        abort_unless($title->isEditable(), 403);
        abort_if($title->isApproved() && ! $this->canEditApproved(), 403);

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
        abort_if($title->isApproved() && ! $this->canEditApproved(), 403);
        $data = $this->validateData($request);
        $this->service->update($title, $data, $request->input('chapters', []), Auth::user());

        return redirect()->route('title.show', $title->id)->with('success', 'Judul diperbarui.');
    }

    public function destroy(int $id)
    {
        abort_unless($this->canManage(), 403);
        $title = Title::findOrFail($id);

        // Aturan lama ("hanya draf milik sendiri") diganti aturan pemakaian: judul yang
        // sudah terpakai tidak dihapus tapi dinonaktifkan.
        $reason = $title->deleteBlockReason();
        if ($reason !== null) {
            return back()->with('error',
                'Judul tidak bisa dihapus — ' . $reason . '. Gunakan Nonaktifkan bila judul ini sudah tidak dipakai lagi.');
        }

        TitleLog::create([
            'title_id'   => $title->id,
            'event'      => 'deleted',
            'note'       => 'Judul dihapus (belum terpakai order/ISBN/arsip).',
            'changed_by' => Auth::id(),
            'created_at' => now(),
        ]);

        $title->delete();

        return redirect()->route('title.index')->with('success', 'Judul dihapus.');
    }

    /** Judul nonaktif hilang dari dropdown order & daftar direktori default — laporan tetap utuh. */
    public function deactivate(int $id)
    {
        abort_unless($this->canManage(), 403);
        $title = Title::findOrFail($id);

        if (! $title->isActive()) {
            return back()->with('error', 'Judul ini sudah nonaktif.');
        }

        $title->update(['deactivated_at' => now(), 'deactivated_by' => Auth::id()]);

        TitleLog::create([
            'title_id'   => $title->id,
            'event'      => 'deactivated',
            'note'       => 'Judul dinonaktifkan.',
            'changed_by' => Auth::id(),
            'created_at' => now(),
        ]);

        return back()->with('success', 'Judul dinonaktifkan.');
    }

    public function activate(int $id)
    {
        abort_unless($this->isApprover(), 403); // manager | superadmin
        $title = Title::findOrFail($id);

        if ($title->isActive()) {
            return back()->with('error', 'Judul ini sudah aktif.');
        }

        $title->update(['deactivated_at' => null, 'deactivated_by' => null]);

        TitleLog::create([
            'title_id'   => $title->id,
            'event'      => 'activated',
            'note'       => 'Judul diaktifkan kembali.',
            'changed_by' => Auth::id(),
            'created_at' => now(),
        ]);

        return back()->with('success', 'Judul diaktifkan kembali.');
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
            'link_terbit'                   => 'nullable|url|max:500',
            '_redirect'                     => 'nullable|string|max:255',
            'journal_options'                  => 'nullable|array',
            'journal_options.*.journal_id'     => 'nullable|integer|exists:tb_journals,id',
            'journal_options.*.nama_jurnal'    => 'nullable|string|max:255',
            'journal_options.*.link'           => 'nullable|string|max:255',
            'journal_options.*.apc'            => 'nullable|string|max:255',
            'journal_options_dikirim'          => 'nullable|boolean',
        ]);

        $this->service->updateInfo(
            $title,
            $data,
            $request->input('journal_options', []),
            Auth::user(),
            // Kehadiran kunci `journal_options` SUDAH pernyataan wewenang atas opsi.
            // Penandanya hanya diperlukan untuk kasus yang tak bisa dibedakan: formulir
            // lengkap yang dikirim setelah SELURUH opsinya dihapus tiba tanpa kunci itu,
            // persis seperti pengirim sebagian yang memang tak berurusan dengan opsi.
            $request->has('journal_options') || $request->boolean('journal_options_dikirim')
        );

        // Form ini dipakai dari halaman judul DAN dari layar naskah. Tanpa ini,
        // menyimpan dari layar naskah melempar orang ke halaman judul dan konteks
        // kerjanya hilang. Hanya menerima alamat di dalam aplikasi sendiri — supaya
        // form ini tak bisa dipakai sebagai batu loncatan ke situs luar.
        //
        // Pembandingnya SENGAJA `url('/') . '/'`, bukan `url('/')` telanjang: url('/')
        // pulang tanpa garis miring penutup ("https://simapa.avidpedia.com"), jadi
        // pencocokan awalan telanjang juga meloloskan host yang cuma berawalan sama
        // ("https://simapa.avidpedia.com.situs-jahat.test") — domain yang bisa
        // didaftarkan siapa saja. Garis miring itu yang mengunci batas host-nya.
        $kembali = (string) $request->input('_redirect', '');
        if ($kembali !== '' && str_starts_with($kembali, rtrim(url('/'), '/') . '/')) {
            return redirect()->to($kembali)->with('success', 'Informasi publikasi diperbarui.');
        }

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
