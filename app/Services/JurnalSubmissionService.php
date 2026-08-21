<?php

namespace App\Services;

use App\Models\Journal;
use App\Models\JournalSubmission;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;

/**
 * Menyambungkan tahap naskah artikel dengan Direktori Jurnal.
 *
 * Sebelumnya keduanya dua sumber kebenaran yang berjalan sendiri: tahap bergerak
 * submit → loa → publish di Pelacakan Naskah, sementara tgl_submit / link_publish
 * hidup di tb_journal_submissions yang hanya bisa diisi dari modul jurnal. Tak ada
 * yang menghubungkan — di data produksi 15 artikel sampai ke tahap jurnal dengan
 * NOL catatan submission, dan 3 berkas LoA menggantung tanpa induk.
 *
 * Datanya kini direbut di tempat orang bekerja: tombol "Selesaikan tahap".
 */
class JurnalSubmissionService
{
    /**
     * Tahap artikel yang MENYELESAIKANNYA berarti sesuatu di sisi jurnal.
     *
     * Perhatikan tak ada 'publish': publish adalah tahap FINAL (TitleProgress::FINAL_STAGES),
     * jadi ia tak pernah "diselesaikan" — advance() dari sana melempar "sudah di tahap
     * akhir". Link terbit karenanya direbut saat menyelesaikan LoA, yaitu transisi yang
     * MASUK ke publish.
     */
    private const TAHAP_KE_STATUS = [
        'submit' => 'submitted',
        'loa'    => 'published',
    ];

    /** Tahap yang meminta data tambahan dari pengguna saat diselesaikan. */
    public static function tahapMintaData(string $tahap): bool
    {
        return isset(self::TAHAP_KE_STATUS[$tahap]);
    }

    /** Aturan validasi untuk tahap yang sedang diselesaikan. Kosong = tak ada yang diminta. */
    public static function aturan(TitleProgress $progress): array
    {
        if (! self::artikel($progress)) {
            return [];
        }

        return match ($progress->status) {
            // journal_id ATAU nama_jurnal — bukan keduanya wajib. Direktori Jurnal
            // masih kosong di produksi (0 baris) dan journal_id NOT NULL, jadi
            // mewajibkan memilih dari direktori berarti memblokir tahap Submit
            // sampai ada yang mengisi direktorinya lebih dulu.
            'submit' => [
                'journal_id'   => 'nullable|integer|exists:tb_journals,id',
                'nama_jurnal'  => 'required_without:journal_id|nullable|string|max:200',
                'tgl_submit'   => 'nullable|date',
                'ojs_akun'     => 'nullable|string|max:120',
                'ojs_password' => 'nullable|string|max:120',
            ],
            // Diminta saat menyelesaikan LoA, bukan Publish: assertLinkTerbit() menahan
            // transisi ke tahap final selama Title::linkTerbit() masih null, dan untuk
            // artikel nilai itu dicari di link_publish submission. Tanpa direbut di sini,
            // orang tertahan di LoA tanpa jalan keluar dari layar tempat ia bekerja.
            //
            // WAJIB hanya bila linknya memang belum ada. Title::linkTerbit() punya tiga
            // sumber (kolom judul, submission jurnal, registrasi ISBN); menuntutnya tanpa
            // syarat berarti menolak naskah yang linknya sudah tercatat di Direktori Judul
            // — gerbang kedua yang berbeda pendapat dengan gerbang aslinya.
            'loa' => [
                'link_publish' => (self::butuhLink($progress) ? 'required' : 'nullable') . '|url|max:255',
                'tgl_terbit'   => 'nullable|date',
            ],
            default => [],
        };
    }

    /**
     * Catat data jurnal untuk tahap yang BARU SAJA diselesaikan.
     *
     * Dipanggil dengan status SEBELUM advance() — sesudahnya progress sudah pindah
     * ke tahap berikutnya dan jejak tahap yang baru selesai hilang.
     */
    public function catat(TitleProgress $progress, string $tahapSelesai, array $data, User $actor): ?JournalSubmission
    {
        if (! self::artikel($progress) || ! isset(self::TAHAP_KE_STATUS[$tahapSelesai])) {
            return null;
        }

        $title = $progress->orderDetail?->titleRef;
        if (! $title) {
            return null;
        }

        $submission = $this->submissionBerjalan($title);

        // Tanpa jurnal tak ada yang bisa dicatat: journal_id NOT NULL di tabelnya.
        // Tahap loa/publish memakai submission yang sudah dibuat saat Submit.
        $journalId = $this->journalId($data, $actor) ?? $submission?->journal_id;
        if (! $journalId) {
            return null;
        }

        $isi = ['journal_id' => $journalId, 'status' => self::TAHAP_KE_STATUS[$tahapSelesai]];

        if ($tahapSelesai === 'submit') {
            $isi['tgl_submit'] = $data['tgl_submit'] ?? now()->toDateString();
            foreach (['ojs_akun', 'ojs_password'] as $k) {
                if (filled($data[$k] ?? null)) {
                    $isi[$k] = $data[$k];
                }
            }
        }

        if ($tahapSelesai === 'loa') {
            $isi['link_publish'] = $data['link_publish'] ?? null;
            $isi['tgl_terbit']   = $data['tgl_terbit'] ?? now()->toDateString();
        }

        if ($submission) {
            $submission->update($isi);

            return $submission;
        }

        return JournalSubmission::create($isi + [
            'title_id'   => $title->id,
            'created_by' => $actor->id,
        ]);
    }

    /**
     * Submission yang sedang berjalan untuk judul ini.
     *
     * Sengaja id DESC, bukan created_at: dua baris yang lahir di detik yang sama
     * membuat urutan created_at tak deterministik di MySQL — alasan yang sama
     * dengan Title::linkTerbit().
     */
    private function submissionBerjalan(Title $title): ?JournalSubmission
    {
        return JournalSubmission::where('title_id', $title->id)->orderByDesc('id')->first();
    }

    /**
     * id jurnal dari pilihan direktori, atau dari nama yang diketik.
     *
     * Nama baru masuk Direktori Jurnal — pola yang sama dengan Scope::firstOrCreate
     * yang sudah dipakai di tiga tempat lain. Dengan begitu direktori terisi dari
     * pemakaian nyata, bukan sebagai syarat yang harus dipenuhi lebih dulu.
     */
    private function journalId(array $data, User $actor): ?int
    {
        if (filled($data['journal_id'] ?? null)) {
            return (int) $data['journal_id'];
        }

        $nama = trim((string) ($data['nama_jurnal'] ?? ''));
        if ($nama === '') {
            return null;
        }

        return Journal::firstOrCreate(['nama' => $nama], ['created_by' => $actor->id])->id;
    }

    /** Judulnya belum punya link terbit dari sumber mana pun. */
    public static function butuhLink(TitleProgress $progress): bool
    {
        $title = $progress->orderDetail?->titleRef;

        return $title === null ? false : $title->butuhLinkTerbit();
    }

    private static function artikel(TitleProgress $progress): bool
    {
        return ! in_array($progress->orderDetail?->type, ['bk_mandiri', 'bk_kolab'], true);
    }
}
