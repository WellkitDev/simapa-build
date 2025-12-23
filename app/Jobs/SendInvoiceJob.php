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
    public function __construct(int $invoiceId)
    {
        //
        $this->invoiceId  = $invoiceId;


    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        //
        // Ambil data FRESH dari database → pasti bersih & relasi lengkap
        $invoice = Invoice::with(['order.authors', 'order.scopes'])->findOrFail($this->invoiceId);
        $order   = $invoice->order;
        // === KIRIM EMAIL DENGAN ATTACHMENT PDF ===
        $mainAuthor = $order->authors->where('pivot.possition', 1)->first();

        $emailTo = $mainAuthor && $mainAuthor->email
            ? $mainAuthor->email
            : $order->contact_email;

        try {
            Mail::to($emailTo)->send(new InvoiceMail($invoice));

            Log::info('Email invoice berhasil dikirim', [
                'to' => $emailTo,
                'invoice_no' => $invoice->inv_no
            ]);
        } catch (\Exception $e) {
            Log::error('Gagal kirim email invoice', [
                'to' => $emailTo,
                'error' => $e->getMessage()
            ]);
            // Laravel queue akan otomatis retry sesuai config
            throw $e; // biar queue retry
        }
    }
}
