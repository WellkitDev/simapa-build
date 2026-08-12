<?php

namespace App\Jobs;

use App\Mail\ServiceInvoiceMail;
use App\Models\ServiceInvoice;
use App\Services\GoogleDriveService;
use App\Support\ServiceInvoicePdfData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendServiceInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(protected int $invoiceId) {}

    public function handle(GoogleDriveService $drive): void
    {
        $invoice = ServiceInvoice::with(['items', 'payments'])->find($this->invoiceId);
        if (! $invoice || ! $invoice->client_email) {
            return;
        }

        $pdfContent = Pdf::loadView('services.invoices.invoice_pdf', ServiceInvoicePdfData::for($invoice))->output();

        // URUTAN PENTING: email dikirim LEBIH DULU. SendInvoiceJob yang lama menaruh
        // Mail::to(...) di dalam if ($folderId), sehingga Google Drive bermasalah =
        // invoice tidak pernah sampai ke klien, tanpa jejak apa pun.
        Mail::to($invoice->client_email)->send(new ServiceInvoiceMail($invoice, $pdfContent));

        // Arsip Drive: best-effort. Gagal di sini TIDAK membatalkan apa pun.
        $driveUrl = null;
        try {
            $tempDir = storage_path('app/temp/service-invoices');
            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            $tempPath = $tempDir . '/Invoice_Layanan_' . $invoice->invoice_no . '.pdf';
            file_put_contents($tempPath, $pdfContent);

            $folderId = $drive->getOrCreateFolderByPath('Application/ServiceInvoices/' . $invoice->issued_at->format('Y'));
            if ($folderId) {
                $result   = $drive->uploadFile($tempPath, $folderId, true);
                $driveUrl = $result['url'] ?? null;
            }

            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        } catch (\Throwable $e) {
            Log::warning('SendServiceInvoiceJob: arsip Drive gagal, email tetap terkirim. ' . $e->getMessage());
        }

        $invoice->forceFill([
            'sent_at'       => now(),
            'sent_count'    => $invoice->sent_count + 1,
            'pdf_drive_url' => $driveUrl ?? $invoice->pdf_drive_url,
        ])->save();

        $invoice->logs()->create([
            'event' => 'emailed',
            'note'  => 'Dikirim ke ' . $invoice->client_email . ($driveUrl ? '' : ' (arsip Drive gagal)'),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $invoice = ServiceInvoice::find($this->invoiceId);
        $invoice?->logs()->create([
            'event' => 'email_failed',
            'note'  => substr($e->getMessage(), 0, 500),
        ]);
    }
}
