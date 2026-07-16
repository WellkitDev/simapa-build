<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\CashSetting;
use App\Models\Payment;
use RuntimeException;

class PaymentCashBackfillService
{
    /**
     * Tarik semua payment 'paid' jadi entri kas. Idempotent: sync() memakai
     * updateOrCreate per payment_id, jadi menjalankan ulang tak menggandakan.
     *
     * @return array{synced:int}
     */
    public function run(): array
    {
        $this->guardOpening();

        $sync = app(PaymentCashSyncService::class);
        $synced = 0;

        Payment::where('status', 'paid')->orderBy('id')
            ->chunkById(200, function ($payments) use ($sync, &$synced) {
                foreach ($payments as $payment) {
                    $sync->sync($payment);
                    $synced++;
                }
            });

        return ['synced' => $synced];
    }

    /**
     * Backfill aman HANYA bila saldo awal nol. Bila saldo awal sudah diisi, ia
     * mewakili transaksi lama secara ringkas — menarik payment-nya lagi =
     * dobel-hitung pembukuan. Berhenti keras; situasi ini butuh penilaian
     * manusia, jangan diam-diam menggandakan.
     */
    private function guardOpening(): void
    {
        $accounts = (float) CashAccount::sum('opening_balance');
        $global   = (float) (CashSetting::singleton()->saldo_awal ?? 0);

        if ($accounts != 0.0 || $global != 0.0) {
            throw new RuntimeException(
                'Backfill dibatalkan: saldo awal sudah diisi (akun: ' . $accounts . ', global: ' . $global . '). '
                . 'Menarik payment lama akan dobel-hitung. Nolkan saldo awal dulu, atau lewati backfill ini.'
            );
        }
    }
}
