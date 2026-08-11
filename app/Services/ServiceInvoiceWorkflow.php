<?php

namespace App\Services;

use App\Models\ServiceInvoice;
use Illuminate\Support\Facades\DB;

/**
 * Perpindahan status pengerjaan invoice layanan, beserta jejaknya.
 *
 * Ditaruh di Service mengikuti konvensi yang sudah hidup di codebase ini untuk
 * pola "ubah keadaan + tulis baris log": CashPeriodService::lock()/unlock() dan
 * TitleProgressService::log(). Model tetap sekadar rekaman.
 *
 * Gerbang "siapa boleh" TIDAK ada di sini — itu urusan permission di rute.
 */
class ServiceInvoiceWorkflow
{
    /**
     * Pindahkan status pengerjaan dan catat jejaknya. Transisi bebas antara
     * belum/proses/selesai — pekerjaan jasa rutin kembali ke Proses karena revisi
     * klien, dan memaksa satu arah cuma membuat operator berbohong.
     *
     * Pembatalan TIDAK lewat sini: 'batal' keadaan terminal yang butuh alasan.
     * Lihat cancel() (ditambahkan di Task 10).
     *
     * @return bool true bila status benar-benar berpindah; false bila sama.
     */
    public function changeStatus(ServiceInvoice $invoice, string $to, ?string $note, ?int $userId): bool
    {
        $from = $invoice->work_status;
        if ($from === $to) {
            return false;
        }

        $attrs = ['work_status' => $to];

        if ($to === 'proses' && $invoice->work_started_at === null) {
            $attrs['work_started_at'] = now();
        }
        if ($to === 'selesai') {
            $attrs['work_finished_at'] = now();
        } elseif ($from === 'selesai') {
            // Keluar dari Selesai: kosongkan supaya tanggal selesai tidak berbohong.
            $attrs['work_finished_at'] = null;
        }

        // Perpindahan status dan jejaknya harus jatuh bersama: status yang berpindah
        // tanpa baris log adalah riwayat yang berbohong.
        DB::transaction(function () use ($invoice, $attrs, $from, $to, $note, $userId) {
            $invoice->update($attrs);

            $invoice->logs()->create([
                'event'       => 'status_changed',
                'from_status' => $from,
                'to_status'   => $to,
                'note'        => $note,
                'changed_by'  => $userId,
            ]);
        });

        return true;
    }
}
