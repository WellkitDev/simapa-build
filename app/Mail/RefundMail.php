<?php

namespace App\Mail;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;

class RefundMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Payment $refund, public array $data, public ?string $pdf = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Bukti Refund — Order ' . (optional($this->refund->order)->code_order ?? ''));
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
            Attachment::fromData(fn () => $this->pdf, 'Refund_' . (optional($this->refund->order)->code_order ?? 'order') . '.pdf')->withMime('application/pdf'),
        ];
    }
}
