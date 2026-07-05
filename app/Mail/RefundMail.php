<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;

class RefundMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invoice $invoice, public array $data, public ?string $pdf = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Bukti Refund — ' . $this->invoice->invoice_no);
    }

    public function content(): Content
    {
        return new Content(view: 'pages.mails.refund_mail');
    }

    public function attachments(): array
    {
        if (! $this->pdf) {
            return [];
        }
        return [
            Attachment::fromData(fn () => $this->pdf, 'Refund_' . $this->invoice->invoice_no . '.pdf')->withMime('application/pdf'),
        ];
    }
}
