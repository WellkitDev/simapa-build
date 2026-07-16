<?php

use App\Services\PaymentCashBackfillService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new PaymentCashBackfillService())->run();
    }

    public function down(): void
    {
        // no-op: entri kas hasil backfill dibiarkan saat rollback.
        // Menghapusnya berisiko membuang entri yang sudah disunting manual.
    }
};
