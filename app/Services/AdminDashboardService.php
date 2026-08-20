<?php
// app/Services/AdminDashboardService.php

namespace App\Services;

use App\Models\Announcement;
use App\Models\BookIsbn;
use App\Models\JournalSubmission;
use App\Models\Title;
use App\Models\TitleArchive;
use App\Models\TitleDocChecklist;

class AdminDashboardService
{
    /**
     * Hitungan pekerjaan dokumen/data admin.
     * Tanpa satu pun angka uang — admin tidak berwenang atas order/pembayaran.
     */
    public function forAdmin(): array
    {
        $diajukanIds = TitleDocChecklist::where('status', 'diajukan')->pluck('title_id');

        return [
            // Checklist dokumen hanya berlaku untuk buku (TitleDocCheckController abort_unless jenis==='buku').
            'doc_belum_lengkap' => Title::where('jenis', 'buku')
                                    ->whereNotIn('id', $diajukanIds)->count(),

            // Judul final yang arsipnya belum diajukan/disetujui. Dulu menghitung
            // TitleArchive berstatus 'draft' — baris yang tak pernah dibuat kode mana pun
            // (TitleArchivalService hanya menulis diajukan/disetujui/ditolak), sehingga
            // ubinnya abadi 0 dan menaut ke halaman yang toh tak menampilkan judul itu.
            //
            // Sengaja memakai Title::siapDiarsipkan() — daftar yang SAMA persis dengan
            // yang ditampilkan halaman tujuannya, lengkap dengan penyaringan PHP
            // manuscriptIsFinal(). Versi hemat (pra-saring SQL saja, tanpa ->get())
            // sudah dipertimbangkan dan ditolak: ia melebihkan hitungan untuk judul yang
            // ordernya membentang lintas tahap atau yang order publish-nya ditarik, jadi
            // kita cuma menukar ubin mati dengan ubin bohong — persis cacat yang sedang
            // ditambal. Biayanya wajar: pra-saring sudah memangkas ke judul tahap final.
            'arsip_menunggu_artefak' => Title::siapDiarsipkan()->count(),
            'arsip_diajukan'         => TitleArchive::where('status', 'diajukan')->count(),

            // Aktif = belum terbit; 'published' sudah selesai.
            'jurnal_submission_aktif' => JournalSubmission::whereIn('status', ['submitted', 'loa'])->count(),

            'isbn_pendaftaran' => BookIsbn::where('status', 'pendaftaran')->count(),
            'isbn_ber_isbn'    => BookIsbn::where('status', 'ber_isbn')->count(),
            'isbn_cetak'       => BookIsbn::where('status', 'cetak')->count(),

            'pengumuman_aktif' => Announcement::where('status', 'published')
                                    ->whereNotNull('published_at')
                                    ->where('published_at', '<=', now())->count(),
        ];
    }
}
