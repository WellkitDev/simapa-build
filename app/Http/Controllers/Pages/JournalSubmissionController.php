<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\Journal;
use App\Models\JournalSubmission;
use App\Services\GoogleDriveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class JournalSubmissionController extends Controller
{
    public function __construct(private GoogleDriveService $drive) {}

    private function canManage(): bool
    {
        return Auth::user()->hasAnyRole(['superadmin', 'manager', 'admin']);
    }

    public function store(Request $request, int $journal)
    {
        abort_unless($this->canManage(), 403);
        $j = Journal::findOrFail($journal);
        $data = $this->prepare($request);
        $data['journal_id'] = $j->id;
        $data['created_by'] = Auth::id();
        if (($data['ojs_password'] ?? '') === '') {
            unset($data['ojs_password']);
        }
        JournalSubmission::create($data);

        return redirect()->route('journal.show', $j->id)->with('success', 'Submission artikel ditambahkan.');
    }

    public function update(Request $request, int $id)
    {
        abort_unless($this->canManage(), 403);
        $sub = JournalSubmission::findOrFail($id);
        $data = $this->prepare($request);
        if (($data['ojs_password'] ?? '') === '') {
            unset($data['ojs_password']); // kosong → pertahankan lama
        }
        // prepare() hanya menaruh loa_url/bukti_bayar_url bila ada unggahan baru,
        // jadi url lama tak tertimpa saat tak ada file baru.
        $sub->update($data);

        return redirect()->route('journal.show', $sub->journal_id)->with('success', 'Submission diperbarui.');
    }

    public function destroy(int $id)
    {
        abort_unless($this->canManage(), 403);
        $sub = JournalSubmission::findOrFail($id);
        $jid = $sub->journal_id;
        $sub->delete();

        return redirect()->route('journal.show', $jid)->with('success', 'Submission dihapus.');
    }

    /** Validasi + normalisasi: hapus key file, ganti dengan *_url bila ada unggahan baru. */
    private function prepare(Request $request): array
    {
        $data = $request->validate([
            'title_id'     => 'nullable|integer|exists:tb_titles,id',
            'tgl_submit'   => 'nullable|date',
            'tgl_terbit'   => 'nullable|date',
            'ojs_akun'     => 'nullable|string|max:255',
            'ojs_password' => 'nullable|string|max:255',
            'link_publish' => 'nullable|string|max:255',
            'status'       => 'required|in:submitted,loa,published',
            'catatan'      => 'nullable|string',
            'loa'          => 'nullable|file|max:5120',
            'bukti_bayar'  => 'nullable|file|max:5120',
        ]);

        if ($request->hasFile('loa')) {
            $data['loa_url'] = $this->drive->uploadFile($request->file('loa'), null, true)['url'] ?? null;
        }
        if ($request->hasFile('bukti_bayar')) {
            $data['bukti_bayar_url'] = $this->drive->uploadFile($request->file('bukti_bayar'), null, true)['url'] ?? null;
        }
        unset($data['loa'], $data['bukti_bayar']);

        return $data;
    }
}
