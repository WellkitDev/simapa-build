<?php

namespace App\Observers;

use App\Models\CashEntry;
use App\Models\Payment;
use App\Services\PaymentCashSyncService;

class PaymentObserver
{
    public function saved(Payment $payment): void
    {
        app(PaymentCashSyncService::class)->sync($payment);
    }

    public function deleted(Payment $payment): void
    {
        CashEntry::where('payment_id', $payment->id)->delete();
    }
}
