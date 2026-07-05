<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Mail\RefundMail;
use App\Support\RefundPdfData;
use App\Services\GoogleDriveService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SendRefundJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected int $invoiceId) {}

    public function handle(GoogleDriveService $drive): void
    {
        $invoice = Invoice::with('order.contact', 'order.details', 'refundPayment')->find($this->invoiceId);
        if (! $invoice || ! $invoice->refundPayment) {
            return;
        }

        $data   = RefundPdfData::for($invoice);
        $pdf    = Pdf::loadView('payments.refunds.refund_pdf', $data);
        $pdfOut = $pdf->output();

        // Best-effort simpan + upload Drive (tak menggagalkan email bila Drive mati)
        try {
            $tempDir = storage_path('app/temp/refunds');
            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            $tempPath = $tempDir . '/Refund_' . $invoice->invoice_no . '.pdf';
            file_put_contents($tempPath, $pdfOut);
            $folderId = $drive->getOrCreateFolderByPath('Application/Refunds/' . now()->format('Y'));
            if ($folderId) {
                $drive->uploadFile($tempPath, $folderId, true);
            }
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        } catch (\Throwable $e) {
            Log::warning('SendRefundJob Drive gagal: ' . $e->getMessage());
        }

        $email = optional($invoice->order?->contact)->cp_email;
        if ($email) {
            Mail::to($email)->send(new RefundMail($invoice, $data, $pdfOut));
        }
    }
}
