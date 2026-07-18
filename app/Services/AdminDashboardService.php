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

            'arsip_menunggu_artefak' => TitleArchive::where('status', 'draft')->count(),
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
