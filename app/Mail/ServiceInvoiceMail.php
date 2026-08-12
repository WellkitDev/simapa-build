<?php

namespace App\Mail;

use App\Models\ServiceInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ServiceInvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ServiceInvoice $invoice,
        public string $pdfContent,
    ) {}

    public function envelope(): Envelope
    {
        // invoice_no — BUKAN inv_no. Atribut itu tidak ada di model dan membuat
        // subjek terkirim tanpa nomor, seperti yang terjadi di InvoiceMail lama.
        return new Envelope(
            subject: 'Invoice Layanan #' . $this->invoice->invoice_no . ' — Avidpedia',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'pages.mails.service_invoice_mail');
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfContent, 'Invoice_Layanan_' . $this->invoice->invoice_no . '.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
