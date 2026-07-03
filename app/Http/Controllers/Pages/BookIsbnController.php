<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\BookIsbn;
use App\Models\Title;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BookIsbnController extends Controller
{
    public function index()
    {
        $books = Title::where('jenis', 'buku')
            ->with(['orderDetails.titleProgress', 'bookIsbn'])
            ->latest()->get()
            ->filter->isbnEligible()
            ->values();

        return view('isbn.index', [
            'books'     => $books,
            'canManage' => Auth::user()->hasAnyRole(['superadmin', 'manager', 'admin', 'production']),
        ]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'status'         => 'required|in:pendaftaran,ber_isbn,cetak',
            // Tiap status mewajibkan nomor yang sesuai (pendaftaran→no_pendaftaran, ber_isbn→no_isbn, cetak→no_buku_cetak).
            'no_pendaftaran' => 'nullable|required_if:status,pendaftaran|string|max:100',
            'no_isbn'        => 'nullable|required_if:status,ber_isbn|string|max:100',
            'no_buku_cetak'  => 'nullable|required_if:status,cetak|string|max:100',
            'penerbit'       => 'nullable|string|max:150',
            'tgl_daftar'     => 'nullable|date',
            'tgl_isbn'       => 'nullable|date',
            'tgl_terbit'     => 'nullable|date',
            'catatan'        => 'nullable|string',
        ], [
            'no_pendaftaran.required_if' => 'No. Pendaftaran wajib diisi untuk status Pendaftaran.',
            'no_isbn.required_if'        => 'No. ISBN wajib diisi untuk status Ber-ISBN.',
            'no_buku_cetak.required_if'  => 'No. Buku Cetak wajib diisi untuk status Cetak/Terbit.',
        ]);
        foreach (['tgl_daftar', 'tgl_isbn', 'tgl_terbit'] as $d) {
            $data[$d] = ($data[$d] ?? '') ?: null;
        }
        return $data;
    }

    public function store(Request $request)
    {
        $title = Title::findOrFail($request->input('title_id'));
        abort_unless($title->jenis === 'buku' && $title->isbnEligible(), 403);
        if ($title->bookIsbn()->exists()) {
            return back()->with('error', 'Buku sudah punya registrasi ISBN.');
        }
        $data = $this->validated($request);
        $data['title_id']   = $title->id;
        $data['created_by'] = Auth::id();
        $isbn = BookIsbn::create($data);
        $this->syncManuscript($isbn);

        return redirect()->route('title.show', $title->id)->with('success', 'Registrasi ISBN disimpan.');
    }

    public function update(Request $request, int $id)
    {
        $isbn = BookIsbn::findOrFail($id);
        $isbn->update($this->validated($request));
        $this->syncManuscript($isbn);

        return redirect()->route('title.show', $isbn->title_id)->with('success', 'Registrasi ISBN diperbarui.');
    }

    /** Sinkron tahap manuskrip buku dari status ISBN (maju-saja): Ber-ISBN→cetak, Cetak/Terbit→terbit. */
    private function syncManuscript(BookIsbn $isbn): void
    {
        $map = ['ber_isbn' => 'cetak', 'cetak' => 'terbit'];
        if (! isset($map[$isbn->status])) {
            return; // Pendaftaran: tak memindahkan
        }
        $title = $isbn->title()->first();
        if ($title) {
            app(\App\Services\ChapterManuscriptService::class)->advanceBookToStage($title, $map[$isbn->status], Auth::user());
        }
    }

    public function destroy(int $id)
    {
        $isbn = BookIsbn::findOrFail($id);
        $titleId = $isbn->title_id;
        $isbn->delete();

        return redirect()->route('title.show', $titleId)->with('success', 'Registrasi ISBN dihapus.');
    }
}
