<?php

namespace App\Services;

use App\Models\JournalSubmission;
use App\Models\Title;
use App\Models\TitleArchive;
use App\Models\TitleArchiveArtifact;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class TitleArchivalService
{
    public function __construct(private GoogleDriveService $drive) {}

    /** Daftar artefak baku (dengan prefill dari data existing) untuk render form. */
    public function defaultArtifacts(Title $title): array
    {
        $existing   = $title->archiveArtifacts->keyBy('key');
        $submission = JournalSubmission::where('title_id', $title->id)->latest()->first();
        $berkas     = $this->berkasTerbaru($title);

        /*
         | Artefak yang datanya sudah diisi di modul lain tidak perlu diketik ulang.
         | Sumbernya dicatat di $sumber supaya UI bisa menyebut "dari Direktori ISBN" —
         | tanpa itu orang tak tahu harus mengubahnya di mana, lalu mengetik ulang di
         | sini dan dua tempat diam-diam berbeda isi.
         |
         | `publish_link` lewat linkTerbit(), BUKAN cabang jenis buatan sendiri: link
         | yang diisi lewat form Informasi Publikasi tersimpan di kolom judul, dan arsip
         | tak boleh berkata "belum diisi" untuk data yang jelas ada.
         */
        $prefill = [
            'isbn'            => optional($title->bookIsbn)->no_isbn,
            'publish_link'    => $title->linkTerbit(),
            'barcode_file'    => $berkas['barcode_isbn']   ?? null,
            'hki_file'        => $berkas['sertifikat_hki'] ?? null,
            'final_book_file' => $berkas['ebook']          ?? null,
            'loa'             => optional($submission)->loa_url ?: ($berkas['loa'] ?? null),
            'final_naskah'    => $berkas['final'] ?? null,
            'apc_bukti'       => optional($submission)->bukti_bayar_url,
        ];

        $sumber = [
            'isbn'            => 'Direktori ISBN',
            'publish_link'    => trim((string) $title->link_terbit) !== ''
                                    ? 'Informasi Publikasi'
                                    : ($title->jenis === 'buku' ? 'Direktori ISBN' : 'Direktori Jurnal'),
            'barcode_file'    => 'Berkas ISBN',
            'hki_file'        => 'Berkas ISBN',
            'final_book_file' => 'Berkas ISBN',
            'loa'             => optional($submission)->loa_url ? 'Direktori Jurnal' : 'Detail Naskah',
            'final_naskah'    => 'Detail Naskah',
            'apc_bukti'       => 'Direktori Jurnal',
        ];

        $out = [];
        foreach (TitleArchive::artifactsFor($title->jenis) as $key => $def) {
            $row = $existing->get($key);
            $out[] = [
                'key'         => $key,
                'label'       => $def['label'],
                'type'        => $def['type'],
                'value'       => $row->value ?? ($prefill[$key] ?? null),
                'file_name'   => $row->file_name ?? null,
                'pic_user_id' => $row->pic_user_id ?? null,
                'pic_name'    => $row ? optional($row->pic)->name : null,
                'note'        => $row->note ?? null,
                // Nilai tersimpan manual menang atas prefill, jadi `dari_luar` hanya
                // benar bila TIDAK ada baris tersimpan dan prefill-nya yang mengisi.
                'dari_luar'   => $row === null && ($prefill[$key] ?? null) !== null,
                'sumber'      => $sumber[$key] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Siapa mengerjakan apa, dan seluruh jejak perubahannya.
     *
     * Menggantikan kolom PIC per-artefak yang dulu diisi manual: siapa bertanggung jawab
     * sudah tercatat di naskahnya (PJ dan pelaksana), dan siapa mengubah apa sudah
     * tercatat di riwayatnya. Meminta orang mengetiknya ulang per artefak hanya
     * menghasilkan salinan yang bisa berbeda dari sumbernya.
     *
     * Order yang ditarik IKUT ditampilkan, diberi penanda — arsip adalah laporan
     * sejarah, dan penulis yang mundur di tengah jalan bagian dari sejarah itu.
     *
     * @return array{orang: array<int,array<string,mixed>>, riwayat: \Illuminate\Support\Collection}
     */
    public function riwayatLengkap(Title $title): array
    {
        $details = $title->orderDetails()->with([
            'order', 'titleProgress.pj', 'titleProgress.pelaksana',
            'titleProgress.logs.changedBy',
        ])->get();

        $orang = $details->map(function ($d) {
            $p = $d->titleProgress;

            return [
                'kode'       => $d->order?->code_order ?? '—',
                'pj'         => $p?->pj?->name ?? '—',
                'pelaksana'  => $p?->pelaksana?->name ?? '—',
                'tahap'      => $p?->stageLabelId() ?? '—',
                'diarsipkan' => $p?->archived_at,
                'ditarik'    => $p?->withdrawn_at !== null,
            ];
        })->all();

        // Riwayat naskah (per order) digabung dengan riwayat judul, lalu diurutkan
        // sebagai satu garis waktu — laporan arsip dibaca dari A ke Z, bukan per tabel.
        $riwayat = $details
            ->flatMap(fn ($d) => ($d->titleProgress?->logs ?? collect())->map(fn ($l) => [
                'waktu'  => $l->created_at,
                'sumber' => $d->order?->code_order ?? 'Naskah',
                'aksi'   => $l->eventLabel(),
                'dari'   => $l->from_value,
                'ke'     => $l->to_value,
                'oleh'   => $l->changedBy?->name ?? '—',
                'note'   => $l->note,
            ]))
            ->concat($title->logs()->with('changedBy')->get()->map(fn ($l) => [
                'waktu'  => $l->created_at,
                'sumber' => 'Judul',
                'aksi'   => \Illuminate\Support\Str::title(str_replace('_', ' ', (string) $l->event)),
                'dari'   => null,
                'ke'     => null,
                'oleh'   => $l->changedBy?->name ?? '—',
                'note'   => $l->note,
            ]))
            ->sortBy('waktu')
            ->values();

        return ['orang' => $orang, 'riwayat' => $riwayat];
    }

    /**
     * URL Drive versi terbaru per slot, untuk SELURUH slot sekaligus.
     *
     * Satu query — BookIsbn::berkas() sudah memperingatkan jangan dipanggil di dalam
     * perulangan, dan di sini ada sampai lima slot yang dicari.
     *
     * Hanya berkas berstatus 'selesai' yang dihitung: yang masih 'antre' belum punya
     * `drive_url`, dan menampilkannya sebagai artefak lengkap adalah klaim palsu.
     *
     * @return array<string,string> slot => drive_url
     */
    private function berkasTerbaru(Title $title): array
    {
        return \App\Models\ManuscriptFile::where('title_id', $title->id)
            ->whereNull('title_chapter_id')
            ->where('status', 'selesai')
            ->orderBy('slot')
            ->orderByDesc('version')
            ->get(['slot', 'drive_url'])
            ->groupBy('slot')
            ->map(fn ($rows) => (string) $rows->first()->drive_url)
            ->filter(fn (string $url) => $url !== '')
            ->all();
    }

    public function saveArtifacts(Title $title, array $fixed, array $custom, User $actor): void
    {
        foreach (TitleArchive::artifactsFor($title->jenis) as $key => $def) {
            $item = $fixed[$key] ?? [];
            // `pic_user_id` sengaja TIDAK ditulis lagi: penanggung jawab kini dibaca
            // dari naskahnya lewat riwayatLengkap(), bukan diketik ulang per artefak.
            $attrs = [
                'label'     => $def['label'],
                'type'      => $def['type'],
                'note'      => $item['note'] ?? null,
                'is_custom' => false,
            ];
            if ($def['type'] === 'file') {
                if (! empty($item['file'])) {
                    $folder = app(DriveJudulFolderService::class)
                        ->folderKategori($title, DriveJudulFolderService::ARSIP);
                    $attrs['value']     = $this->drive->uploadFile($item['file'], $folder, false)['url'] ?? null;
                    $attrs['file_name'] = $item['file']->getClientOriginalName();
                }
                // tanpa file baru: value/file_name tak diikutkan → dipertahankan
            } else {
                $attrs['value'] = ($item['value'] ?? '') ?: null;
            }
            TitleArchiveArtifact::updateOrCreate(['title_id' => $title->id, 'key' => $key], $attrs);
        }

        // "Lainnya" (custom): ganti seluruh set.
        TitleArchiveArtifact::where('title_id', $title->id)->where('is_custom', true)->delete();
        $pos = 0;
        foreach ($custom as $c) {
            $label = trim((string) ($c['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            TitleArchiveArtifact::create([
                'title_id'    => $title->id,
                'key'         => null,
                'label'       => $label,
                'type'        => in_array($c['type'] ?? '', ['link', 'text'], true) ? $c['type'] : 'text',
                'value'       => ($c['value'] ?? '') ?: null,
                'note'        => $c['note'] ?? null,
                'is_custom'   => true,
                'position'    => $pos++,
            ]);
        }
    }

    public function submit(Title $title, User $actor): TitleArchive
    {
        if (! $title->archiveEligible()) {
            throw ValidationException::withMessages(['archive' => 'Belum bisa diarsipkan: pastikan pembayaran lunas dan manuskrip final (terbit/publish).']);
        }
        $archive = TitleArchive::updateOrCreate(
            ['title_id' => $title->id],
            ['status' => 'diajukan', 'submitted_by' => $actor->id, 'submitted_at' => now()]
        );
        app(Notifier::class)->titleArchiveSubmitted($archive, $actor);
        return $archive;
    }

    /**
     * Menyetujui arsip — DAN inilah satu-satunya tempat `archived_at` disetel.
     *
     * Sampai 2026-08-23 kolom itu disetel begitu tahap jadi final, sehingga naskah
     * lenyap dari papan Pelacakan pada detik ia terbit — sebelum ada yang mengajukan
     * arsipnya. Di produksi 24 naskah menghilang begitu, dan tb_title_archives kosong:
     * modul arsipnya praktis tak pernah dipakai karena pintu masuknya tak terlihat.
     *
     * Kini naskah terbit bertahan di papan sampai persetujuan ini turun.
     */
    public function approve(Title $title, User $actor, ?string $note): TitleArchive
    {
        $archive = TitleArchive::updateOrCreate(
            ['title_id' => $title->id],
            ['status' => 'disetujui', 'approved_by' => $actor->id, 'approved_at' => now(), 'approval_note' => $note]
        );

        $this->tandaiTerarsip($title, $actor);

        return $archive;
    }

    public function reject(Title $title, User $actor, string $note): TitleArchive
    {
        return TitleArchive::updateOrCreate(
            ['title_id' => $title->id],
            ['status' => 'ditolak', 'reject_note' => $note]
        );
    }

    /**
     * Pindahkan SELURUH order sejudul ke arsip.
     *
     * Order yang ditarik atau dibatalkan sengaja dilewati: mereka sudah punya
     * penandanya sendiri, dan `archived_at` di atasnya akan membuat mereka muncul di
     * dua daftar arsip sekaligus.
     */
    private function tandaiTerarsip(Title $title, User $actor): void
    {
        $progresses = \App\Models\TitleProgress::whereHas(
            'orderDetail',
            fn ($q) => $q->where('title_id', $title->id)
        )
            ->whereNull('withdrawn_at')
            ->whereNull('cancelled_at')
            ->whereNull('archived_at')
            ->get();

        foreach ($progresses as $p) {
            $p->update(['archived_at' => now()]);

            \App\Models\TitleProgressLog::create([
                'title_progress_id' => $p->id,
                'event'             => 'diarsipkan',
                'from_value'        => \App\Models\TitleProgress::labelFor($p->status),
                'to_value'          => 'Arsip',
                'changed_by'        => $actor->id,
                'note'              => 'Arsip judul disetujui.',
                'is_correction'     => false,
            ]);
        }
    }
}
