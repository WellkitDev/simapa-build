<?php

namespace App\Jobs;

use App\Mail\SalarySlipMail;
use App\Models\SalarySlip;
use App\Services\GoogleDriveService;
use App\Support\SalarySlipPdfData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendSalarySlipJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected int $slipId) {}

    public function handle(GoogleDriveService $drive): void
    {
        $slip = SalarySlip::with('earnings', 'deductions', 'employee')->find($this->slipId);
        if (! $slip || $slip->status !== 'terbit') {
            return;
        }

        $data   = SalarySlipPdfData::for($slip);
        $pdfOut = Pdf::loadView('salary.slips.salary_slip_pdf', $data)->output();

        try {
            $tempDir = storage_path('app/temp/salary-slips');
            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            $tempPath = $tempDir . '/SlipGaji_' . $slip->slip_no . '.pdf';
            file_put_contents($tempPath, $pdfOut);
            $folderId = $drive->getOrCreateFolderByPath('Application/SalarySlips/' . $slip->period_year);
            if ($folderId) {
                $drive->uploadFile($tempPath, $folderId, true);
            }
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        } catch (\Throwable $e) {
            Log::warning('SendSalarySlipJob Drive gagal: ' . $e->getMessage());
        }

        $email = optional($slip->employee)->email;
        if ($email) {
            Mail::to($email)->send(new SalarySlipMail($slip, $data, $pdfOut));
        }
    }

    /**
     * Dipanggil queue sesudah semua percobaan habis.
     *
     * Tanpa method ini kegagalan hanya menjadi baris di tabel failed_jobs, yang tak
     * pernah dibuka siapa pun — email tak sampai tanpa ada yang tahu.
     *
     * Sengaja tahan banting: pencarian datanya boleh gagal (barisnya bisa saja sudah
     * dihapus). failed() yang ikut melempar membuat kegagalannya hilang sama sekali.
     */
    public function failed(\Throwable $e): void
    {
        $rujukan = null;

        try {
            $rujukan = \App\Models\SalarySlip::find($this->slipId)?->periodLabel()
                ?? ('#' . $this->slipId);
        } catch (\Throwable) {
            $rujukan = null;
        }

        \Illuminate\Support\Facades\Log::error('Slip gaji gagal dikirim', [
            'pesan' => $e->getMessage(),
        ]);

        app(\App\Services\Notifier::class)->pengirimanGagal('Slip gaji', $rujukan, $e->getMessage());
    }
}
