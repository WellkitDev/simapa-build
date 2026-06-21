<?php

namespace App\Http\Controllers\Pages;

use App\Models\Tagihan;
use App\Models\TagihanLog;
use App\Support\TagihanPdfData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Services\Notifier;

class TagihanController extends Controller
{
    public function index()
    {
        $tagihan = Tagihan::query()
            ->when($this->marketingOnly(), fn ($q) => $q->where('created_by', Auth::id()))
            ->latest()
            ->get();

        return view('payments.tagihan.index', compact('tagihan'));
    }

    public function create()
    {
        return view('payments.tagihan.create', ['tagihan' => null]);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);

        $id = DB::transaction(function () use ($data) {
            $tagihan = Tagihan::create([
                ...$data,
                'tagihan_no' => $this->generateNo(),
                'created_by' => Auth::id(),
                'status'     => 'diajukan',
            ]);
            $this->log($tagihan, '', 'diajukan', 'Tagihan dibuat.');
            return $tagihan->id;
        });

        app(Notifier::class)->tagihanSubmitted(Tagihan::find($id), Auth::user());
        return redirect()->route('tagihan.show', $id)->with('success', 'Tagihan dibuat & diajukan.');
    }

    public function show(int $id)
    {
        $tagihan = $this->scopedQuery()->with(['creator', 'approver', 'logs.changedBy'])->findOrFail($id);
        return view('payments.tagihan.show', compact('tagihan'));
    }

    public function edit(int $id)
    {
        $tagihan = $this->scopedQuery()->findOrFail($id);
        abort_unless($tagihan->isEditable() && $this->canManage($tagihan), 403);
        return view('payments.tagihan.create', compact('tagihan'));
    }

    public function update(Request $request, int $id)
    {
        $tagihan = $this->scopedQuery()->findOrFail($id);
        abort_unless($tagihan->isEditable() && $this->canManage($tagihan), 403);

        $data = $this->validateData($request);
        $resubmit = false;
        DB::transaction(function () use ($tagihan, $data, &$resubmit) {
            $resubmit = $tagihan->status === 'ditolak';
            $tagihan->update([...$data, 'status' => $resubmit ? 'diajukan' : $tagihan->status]);
            if ($resubmit) {
                $this->log($tagihan, 'ditolak', 'diajukan', 'Diajukan ulang setelah revisi.');
            }
        });

        if ($resubmit) {
            app(Notifier::class)->tagihanSubmitted($tagihan, Auth::user());
        }
        return redirect()->route('tagihan.show', $tagihan->id)->with('success', 'Tagihan diperbarui.');
    }

    public function approve(int $id)
    {
        abort_unless(Auth::user()->hasRole('superadmin'), 403);
        $tagihan = Tagihan::findOrFail($id);
        abort_unless($tagihan->status === 'diajukan', 422, 'Tagihan tidak dalam status diajukan.');
        $from = $tagihan->status;

        DB::transaction(function () use ($tagihan, $from) {
            $tagihan->update(['status' => 'disetujui', 'approved_by' => Auth::id(), 'approved_at' => now()]);
            $this->log($tagihan, $from, 'disetujui', 'Tagihan disetujui.');
        });

        app(Notifier::class)->tagihanApproved($tagihan, Auth::user());
        return back()->with('success', 'Tagihan disetujui.');
    }

    public function reject(Request $request, int $id)
    {
        abort_unless(Auth::user()->hasRole('superadmin'), 403);
        $request->validate(['note' => 'required|string']);
        $tagihan = Tagihan::findOrFail($id);
        abort_unless($tagihan->status === 'diajukan', 422, 'Tagihan tidak dalam status diajukan.');
        $from = $tagihan->status;

        DB::transaction(function () use ($tagihan, $from, $request) {
            $tagihan->update(['status' => 'ditolak', 'reject_note' => $request->note]);
            $this->log($tagihan, $from, 'ditolak', $request->note);
        });

        app(Notifier::class)->tagihanRejected($tagihan, Auth::user());
        return back()->with('warning', 'Tagihan ditolak.');
    }

    public function cancel(int $id)
    {
        $tagihan = $this->scopedQuery()->findOrFail($id);
        abort_unless($this->canManage($tagihan), 403);
        abort_unless(in_array($tagihan->status, ['diajukan', 'disetujui']), 422, 'Tagihan tidak bisa dibatalkan pada status ini.');
        $from = $tagihan->status;

        DB::transaction(function () use ($tagihan, $from) {
            $tagihan->update(['status' => 'dibatalkan']);
            $this->log($tagihan, $from, 'dibatalkan', 'Tagihan dibatalkan.');
        });

        return back()->with('warning', 'Tagihan dibatalkan.');
    }

    public function pdf(int $id)
    {
        $tagihan = $this->scopedQuery()->findOrFail($id);
        abort_unless($tagihan->isDownloadable(), 403, 'Tagihan belum disetujui.');

        $data = TagihanPdfData::for($tagihan);
        return Pdf::loadView('payments.tagihan.tagihan_pdf', $data)
            ->stream('Tagihan_' . $tagihan->tagihan_no . '.pdf');
    }

    public function buatOrder(int $id)
    {
        $tagihan = Tagihan::where('id', $id)->where('created_by', Auth::id())->firstOrFail();
        if (! $tagihan->canConvert()) {
            return back()->with('error', 'Tagihan harus disetujui dan belum jadi order.');
        }
        $route = $tagihan->type === 'jurnal' ? 'order.journal.create' : 'order.book.create';
        return redirect()->route($route, ['from_tagihan' => $tagihan->id]);
    }

    private function marketingOnly(): bool
    {
        return Auth::user()->hasRole('marketing') && ! Auth::user()->hasAnyRole(['manager', 'superadmin']);
    }

    private function scopedQuery()
    {
        return Tagihan::query()->when($this->marketingOnly(), fn ($q) => $q->where('created_by', Auth::id()));
    }

    private function canManage(Tagihan $tagihan): bool
    {
        return Auth::user()->hasAnyRole(['manager', 'superadmin']) || $tagihan->created_by === Auth::id();
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'client_name'        => 'required|string|max:255',
            'client_email'       => 'nullable|email',
            'client_phone'       => 'nullable|string|max:50',
            'client_institution' => 'nullable|string|max:255',
            'title'              => 'required|string|max:255',
            'type'               => 'required|in:buku,jurnal',
            'author_names'       => 'nullable|string',
            'description'        => 'nullable|string',
            'amount'             => 'required|integer|min:0',
            'due_at'             => 'nullable|date',
            'note'               => 'nullable|string',
        ]);
    }

    private function generateNo(): string
    {
        $ym   = date('Ym');
        $last = Tagihan::where('tagihan_no', 'like', "TAG-{$ym}-%")->lockForUpdate()->latest('id')->first();
        $seq  = $last ? intval(substr($last->tagihan_no, -4)) + 1 : 1;
        return "TAG-{$ym}-" . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }

    private function log(Tagihan $tagihan, string $from, string $to, ?string $note): void
    {
        TagihanLog::create([
            'tagihan_id'  => $tagihan->id,
            'from_status' => $from,
            'to_status'   => $to,
            'changed_by'  => Auth::id(),
            'note'        => $note,
        ]);
    }
}
