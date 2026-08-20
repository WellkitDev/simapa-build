<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Invoice;
use App\Mail\InvoiceMail;
use Illuminate\Bus\Queueable;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use App\Services\GoogleDriveService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SendInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $invoiceId;
    // protected string $pdfContent;


    /**
     * Create a new job instance.
     */
    public function __construct($invoiceId)
    {
        //
        $this->invoiceId  = $invoiceId;


    }

    /**
     * Execute the job.
     */
    public function handle(GoogleDriveService $drive): void
    {
        //
        $invoice = Invoice::find($this->invoiceId);
        if (!$invoice) return;

        $data             = \App\Support\InvoicePdfData::for($invoice);
        $invoice          = $data['invoice'];
        $totalCost        = $data['totalCost'];
        $alreadyPaid      = $data['alreadyPaid'];
        $remainingBalance = $data['remainingBalance'];

        $pdf = Pdf::loadView('payments.invoices.book_invoice_pdf', [
            'invoice' => $invoice,
            'order' => $invoice->order,
            'detail' => $invoice->order->details,
            'totalCost' => $totalCost,
            'alreadyPaid' => $alreadyPaid,
            'remainingBalance' => $remainingBalance
        ]);

        // 3. Simpan Temporer
        $filename = "Invoice_{$invoice->invoice_no}.pdf";
        $tempDir = storage_path('app/temp/invoices');
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $tempPath = $tempDir . '/' . $filename;

        // PERBAIKAN: Gunakan output() untuk mendapatkan konten binary PDF
        file_put_contents($tempPath, $pdf->output());

        // 4. Upload ke Drive
        $year = now()->format('Y');
        $folderPath = "Application/Invoices/{$year}";
        $folderId = $drive->getOrCreateFolderByPath($folderPath);

        if ($folderId) {
            // Asumsi uploadFile menerima path lokal file
            $uploadResult = $drive->uploadFile($tempPath, $folderId, true);

            if ($uploadResult && isset($uploadResult['url'])) {
                $invoice->update(['pdf_drive_id' => $uploadResult['url']]);

                // 5. Kirim Email dengan Data Dinamis
                Mail::to($invoice->order->contact->cp_email)->send(new InvoiceMail($invoice, [
                    'totalCost' => $totalCost,
                    'alreadyPaid' => $alreadyPaid,
                    'remainingBalance' => $remainingBalance,
                    'title' => $invoice->order->details->title ?? '-'
                ]));
            }
        }

        // 6. CLEANUP: Hapus file temp setelah proses selesai
        if (file_exists($tempPath)) {
            unlink($tempPath);
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
            $rujukan = \App\Models\Invoice::find($this->invoiceId)?->invoice_number
                ?? ('#' . $this->invoiceId);
        } catch (\Throwable) {
            $rujukan = null;
        }

        \Illuminate\Support\Facades\Log::error('Invoice gagal dikirim', [
            'pesan' => $e->getMessage(),
        ]);

        app(\App\Services\Notifier::class)->pengirimanGagal('Invoice', $rujukan, $e->getMessage());
    }
}
