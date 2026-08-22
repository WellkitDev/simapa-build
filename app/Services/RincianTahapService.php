<?php

namespace App\Services;

use App\Models\ManuscriptFile;
use App\Models\ManuscriptRevision;
use App\Models\Title;
use App\Models\TitleProgress;
use Illuminate\Support\Collection;

/**
 * Merakit "apa yang terjadi di tiap tahap" untuk panel rincian di stepper.
 *
 * Datanya sudah ada sejak lama, tapi tersebar di lima sumber yang menyusunnya menurut
 * JENIS (berkas di kartu berkas, jurnal di kartu jurnal, perpindahan di riwayat) —
 * bukan menurut TAHAP. Untuk menjawab "berkas mana yang dikirim waktu Submit?" orang
 * harus mencocokkan tanggal sendiri antar kartu. Kelas ini yang mencocokkannya.
 *
 * HANYA-BACA. Panelnya tak pernah mengubah apa pun: mengubah tahap yang sudah lewat
 * tetap lewat Koreksi, satu-satunya pintu resmi. Lihat
 * docs/superpowers/specs/2026-08-22-stepper-rincian-tahap-design.md §3.
 */
class RincianTahapService
{
    /**
     * Slot berkas yang merupakan KELUARAN sebuah tahap.
     *
     * Slot yang tak ada di sini sengaja tak muncul di panel mana pun — `revisi_minta`
     * dan `revisi_hasil` misalnya, keduanya milik putaran dan ditampilkan lewat
     * putarannya, bukan sebagai berkas tahap.
     */
    private const BERKAS_TAHAP = [
        'pembuatan'    => ['masuk'],
        'editing'      => ['hasil_editing'],
        'layout'       => ['hasil_layout'],
        'proofreading' => ['hasil_proofread'],
        'isbn'         => ['cover', 'ebook', 'barcode_isbn', 'sertifikat_hki'],
        'loa'          => ['loa'],
        'terbit'       => ['final'],
        'publish'      => ['final'],
    ];

    /**
     * @return array<string, array{
     *     dijalani: bool, berjalan: bool,
     *     kunjungan: list<array{masuk: ?\Carbon\Carbon, keluar: ?\Carbon\Carbon, hari: int,
     *                           oleh: ?string, catatan: ?string, koreksi: bool}>,
     *     berkas: Collection, data: array<string, string>, tautan: ?array{label: string, url: string}
     * }>
     */
    public function untuk(TitleProgress $progress): array
    {
        $title  = $progress->orderDetail?->titleRef;
        $stages = $progress->getStages();

        $logs   = $progress->logs()->with('changedBy')->orderBy('created_at')->orderBy('id')->get();
        $berkas = $title ? $this->berkasJudul($title) : collect();

        /*
         | Submission jurnal, ISBN, dan putaran diambil SEKALI untuk seluruh tahap.
         |
         | Versi pertama menanyakannya di dalam perulangan tahap — dan karena data()
         | serta tautan() sama-sama membutuhkannya, satu halaman buku menembakkan 16
         | query tb_journal_submissions dan 9 query tb_book_isbns untuk menjawab
         | pertanyaan yang jawabannya sama persis setiap kali.
         */
        $sub     = $title?->journalSubmissions()->with('journal')->orderByDesc('id')->first();
        $isbn    = $title?->bookIsbn()->first();
        $putaran = $title
            ? ManuscriptRevision::where('title_id', $title->id)->orderBy('round')->get()->groupBy('stage')
            : collect();

        $out = [];
        foreach ($stages as $stage) {
            $kunjungan = $this->kunjungan($progress, $logs, $stage);

            $out[$stage] = [
                'dijalani'  => $kunjungan !== [],
                'berjalan'  => $progress->status === $stage,
                'kunjungan' => $kunjungan,
                'berkas'    => $berkas->whereIn('slot', self::BERKAS_TAHAP[$stage] ?? [])->values(),
                'data'      => $title ? $this->data($stage, $sub, $isbn, $putaran) : [],
                'tautan'    => $title ? $this->tautan($title, $stage, $sub) : null,
            ];
        }

        return $out;
    }

    /**
     * Tiap kali naskah MASUK tahap ini, satu baris.
     *
     * Sengaja banyak baris, bukan satu: sejak mundur LoA→Revisi ada, sebuah tahap bisa
     * dijalani lebih dari sekali — dan menampilkan hanya yang terakhir justru membuang
     * riwayat yang paling menarik.
     *
     * Baris paling awal boleh tanpa log masuk: naskah lahir langsung di
     * `menunggu_proses` tanpa ada yang memindahkannya ke sana.
     *
     * @param  Collection<int,\App\Models\TitleProgressLog>  $logs
     * @return list<array<string,mixed>>
     */
    private function kunjungan(TitleProgress $progress, Collection $logs, string $stage): array
    {
        $masuk = $logs->where('to_value', $stage)->values();

        // Tahap awal tanpa jejak masuk: pakai started_at bila naskah memang pernah di sana.
        if ($masuk->isEmpty()) {
            $pernahKeluar = $logs->firstWhere('from_value', $stage);
            if (! $pernahKeluar && $progress->status !== $stage) {
                return [];
            }
        }

        $titikMasuk = $masuk->isEmpty()
            ? collect([null])                       // satu kunjungan, waktunya dari started_at
            : $masuk;

        $out = [];
        foreach ($titikMasuk as $i => $log) {
            $mulai = $log?->created_at ?? $progress->started_at ?? $progress->created_at;

            // Log keluar = perpindahan PERTAMA dari tahap ini yang terjadi sesudah masuk.
            $keluar = $logs->first(fn ($l) => $l->from_value === $stage
                && $mulai !== null && $l->created_at >= $mulai);

            $selesai = $keluar?->created_at;
            $penanda = $keluar ?? $log;

            $out[] = [
                'masuk'   => $mulai,
                'keluar'  => $selesai,
                'hari'    => $mulai ? (int) $mulai->diffInDays($selesai ?? now()) : 0,
                'oleh'    => $penanda?->changedBy?->name,
                'catatan' => $penanda?->note,
                'koreksi' => (bool) ($penanda?->is_correction ?? false),
            ];

            // Kunjungan berikutnya hanya dicari sesudah yang ini berakhir; tanpa itu
            // dua kunjungan akan menunjuk log keluar yang sama.
            if ($selesai !== null) {
                $logs = $logs->filter(fn ($l) => $l->created_at > $selesai)->values();
            }
        }

        return $out;
    }

    /** Berkas naskah + berkas ISBN judul ini, versi terbaru saja per slot. */
    private function berkasJudul(Title $title): Collection
    {
        return ManuscriptFile::where('title_id', $title->id)
            ->whereNull('title_chapter_id')
            ->orderByDesc('version')->orderByDesc('id')
            ->get()
            ->unique('slot')
            ->values();
    }

    /**
     * Data tahap-khusus.
     *
     * SANDI OJS SENGAJA TIDAK ADA DI SINI. Halaman /naskah/{id} terbuka untuk semua
     * role — kartu Aksi disembunyikan dari marketing, halamannya sendiri tidak.
     * Direktori Jurnal punya gerbang izinnya sendiri, dan panel ini tak boleh jadi
     * pintu belakang yang membocorkan kredensial ke orang yang tak berhak membukanya
     * di sana. Lihat spec K7.
     *
     * @return array<string,string>
     */
    private function data(string $stage, $sub, $isbn, $putaran): array
    {
        return match ($stage) {
            'submit' => array_filter([
                'Jurnal tujuan'  => $sub?->journal?->nama,
                'Tanggal submit' => $sub?->tgl_submit?->translatedFormat('j M Y'),
                'Akun OJS'       => $sub?->ojs_akun,
            ]),
            'loa' => array_filter([
                'Link artikel terbit' => $sub?->link_publish,
                'Tanggal terbit'      => $sub?->tgl_terbit?->translatedFormat('j M Y'),
            ]),
            'isbn' => array_filter([
                'Nomor ISBN' => $isbn?->no_isbn,
                'Penerbit'   => $isbn?->penerbit,
            ]),
            'revisi', 'pembuatan' => $this->dataPutaran($putaran->get($stage)),
            default => [],
        };
    }

    /** @return array<string,string> */
    private function dataPutaran($putaran): array
    {
        if (! $putaran || $putaran->isEmpty()) {
            return [];
        }

        return [
            'Putaran perbaikan' => $putaran->count() . ' putaran — '
                . $putaran->map(fn ($p) => "ke-{$p->round}: " . str($p->request_note)->limit(60))
                    ->implode(' · '),
        ];
    }

    /**
     * Ke mana orang pergi bila datanya perlu diubah. Panel ini tak menyalin formulir
     * pemiliknya — empat salinan aturan validasi akan bercabang diam-diam.
     *
     * @return array{label: string, url: string}|null
     */
    private function tautan(Title $title, string $stage, $sub): ?array
    {
        return match (true) {
            in_array($stage, ['submit', 'loa'], true) && $sub?->journal_id !== null => [
                'label' => 'Ubah di Direktori Jurnal',
                'url'   => route('journal.show', $sub->journal_id),
            ],
            $stage === 'isbn' => [
                'label' => 'Ubah di Direktori Judul',
                'url'   => route('title.show', $title->id),
            ],
            default => null,
        };
    }
}
