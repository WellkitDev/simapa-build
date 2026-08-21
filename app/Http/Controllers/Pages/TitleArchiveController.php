<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\Title;
use App\Models\TitleArchive;
use App\Models\User;
use App\Services\TitleArchivalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class TitleArchiveController extends Controller
{
    public function __construct(private TitleArchivalService $service) {}

    private function canManage(): bool { return Auth::user()->hasAnyRole(['superadmin', 'manager', 'admin', 'production']); }
    private function canApprove(): bool { return Auth::user()->hasAnyRole(['superadmin', 'manager']); }

    public function index()
    {
        abort_unless($this->canManage(), 403);
        $approved = Title::whereHas('archive', fn ($q) => $q->where('status', 'disetujui'))
            ->with('archive.approver')->latest()->get();
        $pending = $this->canApprove()
            ? Title::whereHas('archive', fn ($q) => $q->where('status', 'diajukan'))->with('archive.submitter')->latest()->get()
            : collect();

        return view('archive.index', [
            // Pintu masuk untuk judul yang BELUM punya baris arsip sama sekali —
            // tanpa ini halaman detail arsip tak tertaut dari mana pun (lihat
            // Title::siapDiarsipkan()).
            'siap'       => Title::siapDiarsipkan(),
            'approved'   => $approved,
            'pending'    => $pending,
            'canApprove' => $this->canApprove(),
        ]);
    }

    public function show(int $id)
    {
        abort_unless($this->canManage(), 403);
        $title = Title::with([
            'chapters', 'scope', 'bookIsbn', 'archive.approver', 'archive.submitter',
            'archiveArtifacts.pic',
            'orderDetails.order.user', 'orderDetails.order.invoices', 'orderDetails.order.payments', 'orderDetails.order.details', 'orderDetails.titleProgress',
        ])->findOrFail($id);

        return view('archive.show', [
            'title'           => $title,
            'artifacts'       => $this->service->defaultArtifacts($title),
            'riwayat'         => $this->service->riwayatLengkap($title),
            'customArtifacts' => $title->archiveArtifacts->where('is_custom', true)->values(),
            'eligible'        => $title->archiveEligible(),
            'isPaidOff'       => $title->isPaidOff(),
            'isFinal'         => $title->manuscriptIsFinal(),
            // Sisa tagihan dipisah dari isPaidOff() dengan sengaja: yang pertama fakta
            // uang, yang kedua izin gerbang (yang bisa dibuka jalan pintas invoice).
            'sisaTagihan'     => $title->sisaTagihan(),
            'jumlahDitarik'   => $title->jumlahDitarik(),
            'canManage'       => $this->canManage(),
            'canApprove'      => $this->canApprove(),
            'staff'           => User::whereHas('roles', fn ($q) => $q->whereIn('name', ['superadmin', 'manager', 'admin', 'production', 'marketing']))->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function pdf(int $id)
    {
        abort_unless($this->canManage(), 403);
        $title = Title::with([
            'chapters', 'scope', 'bookIsbn', 'archive.approver', 'archive.submitter',
            'archiveArtifacts.pic',
            'orderDetails.order.user', 'orderDetails.order.invoices', 'orderDetails.order.payments', 'orderDetails.order.details', 'orderDetails.titleProgress',
        ])->findOrFail($id);
        abort_unless(optional($title->archive)->status === 'disetujui', 403);

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('archive.pdf', [
            'title'     => $title,
            'artifacts' => $this->service->defaultArtifacts($title),
            'riwayat'   => $this->service->riwayatLengkap($title),
            'custom'    => $title->archiveArtifacts->where('is_custom', true)->values(),
            'isPaidOff' => $title->isPaidOff(),
            'isFinal'   => $title->manuscriptIsFinal(),
        ])->stream('Arsip_' . ($title->code ?: $title->id) . '.pdf');
    }

    public function saveArtifacts(Request $request, int $id)
    {
        abort_unless($this->canManage(), 403);
        $title = Title::findOrFail($id);
        $fixed = (array) $request->input('fixed', []);
        foreach (TitleArchive::artifactsFor($title->jenis) as $key => $def) {
            if ($def['type'] === 'file' && $request->hasFile("fixed.$key.file")) {
                $fixed[$key]['file'] = $request->file("fixed.$key.file");
            }
        }
        $this->service->saveArtifacts($title, $fixed, (array) $request->input('custom', []), Auth::user());

        return redirect()->route('archive.show', $id)->with('success', 'Artefak penyelesaian disimpan.');
    }

    public function submit(int $id)
    {
        abort_unless($this->canManage(), 403);
        $title = Title::findOrFail($id);
        try {
            $this->service->submit($title, Auth::user());
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first() ?? 'Belum bisa diarsipkan.');
        }
        return redirect()->route('archive.show', $id)->with('success', 'Judul diajukan ke arsip.');
    }

    public function approve(Request $request, int $id)
    {
        abort_unless($this->canApprove(), 403);
        $data = $request->validate(['approval_note' => 'nullable|string']);
        $this->service->approve(Title::findOrFail($id), Auth::user(), $data['approval_note'] ?? null);

        return redirect()->route('archive.show', $id)->with('success', 'Judul disetujui masuk arsip.');
    }

    public function reject(Request $request, int $id)
    {
        abort_unless($this->canApprove(), 403);
        $data = $request->validate(['reject_note' => 'required|string']);
        $this->service->reject(Title::findOrFail($id), Auth::user(), $data['reject_note']);

        return redirect()->route('archive.show', $id)->with('success', 'Pengajuan arsip ditolak.');
    }
}
