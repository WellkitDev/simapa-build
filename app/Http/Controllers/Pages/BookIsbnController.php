<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\BookIsbn;
use App\Models\ManuscriptFile;
use App\Models\Title;
use App\Services\ManuscriptFileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class BookIsbnController extends Controller
{
    public function index()
    {
        $books = Title::where('jenis', 'buku')
            ->with(['orderDetails.titleProgress', 'bookIsbn'])
            ->latest()->get()
            ->filter->isbnEligible()
            ->values();

        // Berkas seluruh baris diambil dalam SATU query, lalu dipetakan per judul+slot.
        // orderBy('version') menaik membuat keyBy() menyisakan versi tertinggi.
        $berkas = ManuscriptFile::whereIn('title_id', $books->pluck('id'))
            ->whereNull('title_chapter_id')
            ->whereIn('slot', ManuscriptFile::slotsIsbn())
            ->orderBy('version')
            ->get()
            ->keyBy(fn (ManuscriptFile $f) => $f->title_id . ':' . $f->slot);

        return view('isbn.index', [
            'books'     => $books,
            'berkas'    => $berkas,
            'canManage' => Auth::user()->hasAnyRole(['superadmin', 'manager', 'admin', 'production']),
        ]);
    }

    private function validated(Request $request): array
    {
        // Status Cetak/Terbit = buku sudah terbit dan datanya dipakai marketing untuk
        // melayani klien, jadi seluruh kolom wajib kecuali Catatan. Status lain tetap
        // memakai aturan lama: masing-masing hanya mewajibkan nomornya sendiri.
        $cetak = $request->input('status') === 'cetak';

        $data = $request->validate(array_merge([
            'status'         => 'required|in:pendaftaran,ber_isbn,cetak',
            'no_pendaftaran' => $cetak ? 'required|string|max:100' : 'nullable|required_if:status,pendaftaran|string|max:100',
            'no_isbn'        => $cetak ? 'required|string|max:100' : 'nullable|required_if:status,ber_isbn|string|max:100',
            'no_buku_cetak'  => $cetak ? 'required|string|max:100' : 'nullable|string|max:100',
            'penerbit'       => $cetak ? 'required|string|max:150' : 'nullable|string|max:150',
            'tgl_daftar'     => $cetak ? 'required|date' : 'nullable|date',
            'tgl_isbn'       => $cetak ? 'required|date' : 'nullable|date',
            'tgl_terbit'     => $cetak ? 'required|date' : 'nullable|date',
            'link_terbit'    => $cetak ? 'required|url|max:500' : 'nullable|url|max:500',
            'catatan'        => 'nullable|string',
        ], ManuscriptFile::rulesIsbn()), [
            'no_pendaftaran.required_if' => 'No. Pendaftaran wajib diisi untuk status Pendaftaran.',
            'no_isbn.required_if'        => 'No. ISBN wajib diisi untuk status Ber-ISBN.',
            'no_pendaftaran.required'    => 'No. Pendaftaran wajib diisi untuk status Cetak/Terbit.',
            'no_isbn.required'           => 'No. ISBN wajib diisi untuk status Cetak/Terbit.',
            'no_buku_cetak.required'     => 'No. Buku Cetak wajib diisi untuk status Cetak/Terbit.',
            'penerbit.required'          => 'Penerbit wajib diisi untuk status Cetak/Terbit.',
            'tgl_daftar.required'        => 'Tgl Daftar wajib diisi untuk status Cetak/Terbit.',
            'tgl_isbn.required'          => 'Tgl ISBN wajib diisi untuk status Cetak/Terbit.',
            'tgl_terbit.required'        => 'Tgl Terbit wajib diisi untuk status Cetak/Terbit.',
            'link_terbit.required'       => 'Link terbit wajib diisi untuk status Cetak/Terbit.',
            'link_terbit.url'            => 'Link terbit harus berupa alamat web lengkap (diawali https://).',
        // Pesan unggahan diturunkan dari BERKAS_ISBN supaya slot baru tak pernah
        // kembali memakai pesan bawaan Inggris yang tak menyebut sebab.
        ] + ManuscriptFile::pesanIsbn());

        // Berkas ditangani terpisah lewat ManuscriptFileService — jangan sampai ikut
        // masuk ke BookIsbn::create()/update() sebagai kolom.
        foreach (ManuscriptFile::slotsIsbn() as $slot) {
            unset($data[$slot]);
        }

        foreach (['tgl_daftar', 'tgl_isbn', 'tgl_terbit'] as $d) {
            $data[$d] = ($data[$d] ?? '') ?: null;
        }

        return $data;
    }

    /**
     * Berkas wajib saat Cetak/Terbit, TAPI yang sudah pernah diunggah dihitung terisi —
     * menyimpan ulang tak boleh memaksa memilih berkas yang sama sekali lagi. Slot mana
     * yang wajib dibaca dari ManuscriptFile::BERKAS_ISBN, bukan didaftar ulang di sini.
     */
    private function assertBerkasLengkap(Request $request, ?BookIsbn $isbn): void
    {
        if ($request->input('status') !== 'cetak') {
            return;
        }

        $galat = [];
        foreach (ManuscriptFile::BERKAS_ISBN as $slot => $berkas) {
            if (! $berkas['wajibCetak']) {
                continue;
            }
            if ($request->hasFile($slot) || ($isbn && $isbn->berkas($slot))) {
                continue;
            }
            $galat[$slot] = $berkas['label'] . ' wajib diunggah untuk status Cetak/Terbit.';
        }

        if ($galat !== []) {
            throw ValidationException::withMessages($galat);
        }
    }

    public function store(Request $request)
    {
        $title = Title::findOrFail($request->input('title_id'));
        abort_unless($title->jenis === 'buku' && $title->isbnEligible(), 403);
        if ($title->bookIsbn()->exists()) {
            return back()->with('error', 'Buku sudah punya registrasi ISBN.');
        }
        $data = $this->validated($request);
        $this->assertBerkasLengkap($request, null);
        // Berkas naik DULU: unggahan yang gagal tak boleh meninggalkan registrasi
        // Cetak/Terbit tanpa berkas — keadaan yang dilarang assertBerkasLengkap().
        $this->simpanBerkas($request, $title);

        $data['title_id']   = $title->id;
        $data['created_by'] = Auth::id();
        $isbn = BookIsbn::create($data);
        $this->syncManuscript($isbn);

        return redirect()->route('title.show', $title->id)->with('success', 'Registrasi ISBN disimpan.');
    }

    public function update(Request $request, int $id)
    {
        $isbn  = BookIsbn::findOrFail($id);
        $title = $isbn->title()->first();
        $data  = $this->validated($request);
        $this->assertBerkasLengkap($request, $isbn);
        if ($title) {
            $this->simpanBerkas($request, $title);
        }
        $isbn->update($data);
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

    /**
     * Simpan berkas ISBN yang ikut terkirim bersama formulir. Slot yang tidak diisi
     * dibiarkan apa adanya — versi lama tetap berlaku, tidak terhapus.
     *
     * Menerima Title, bukan BookIsbn, supaya bisa dijalankan SEBELUM record ditulis.
     * Sengaja tanpa transaksi DB: menahan transaksi terbuka selama panggilan jaringan
     * lambat justru menahan kunci tabel. Baris ManuscriptFile yatim yang mungkin
     * tertinggal tak berbahaya — berkas memang berversi dan menumpuk secara alami.
     */
    private function simpanBerkas(Request $request, Title $title): void
    {
        $svc = app(ManuscriptFileService::class);
        foreach (ManuscriptFile::slotsIsbn() as $slot) {
            if ($request->hasFile($slot)) {
                $svc->upload($title, null, $slot, $request->file($slot), Auth::user());
            }
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
