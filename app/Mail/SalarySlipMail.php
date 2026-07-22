<?php

namespace App\Mail;

use App\Models\SalarySlip;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SalarySlipMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SalarySlip $slip, public array $data, public ?string $pdf = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Slip Gaji — ' . $this->slip->periodLabel());
    }

    public function content(): Content
    {
        return new Content(view: 'pages.mails.salary_slip_mail');
    }

    public function attachments(): array
    {
        if (! $this->pdf) {
            return [];
        }
        return [
            Attachment::fromData(fn () => $this->pdf, 'SlipGaji_' . $this->slip->slip_no . '.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
