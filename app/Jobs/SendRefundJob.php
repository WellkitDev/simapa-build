<?php

namespace App\Jobs;

use App\Models\Payment;
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

    public function __construct(protected int $paymentId) {}

    public function handle(GoogleDriveService $drive): void
    {
        $refund = Payment::with('order.contact', 'order.details', 'order.payments')->find($this->paymentId);
        if (! $refund || $refund->payment_type !== 'refund') {
            return;
        }

        $data   = RefundPdfData::for($refund);
        $pdf    = Pdf::loadView('payments.refunds.refund_pdf', $data);
        $pdfOut = $pdf->output();
        $code   = optional($refund->order)->code_order ?? 'order';

        try {
            $tempDir = storage_path('app/temp/refunds');
            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            $tempPath = $tempDir . '/Refund_' . $code . '.pdf';
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

        $email = optional($refund->order?->contact)->cp_email;
        if ($email) {
            Mail::to($email)->send(new RefundMail($refund, $data, $pdfOut));
        }
    }
}
