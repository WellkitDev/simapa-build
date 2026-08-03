<?php

use App\Services\TitleBackfillService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Bila tabel masih kosong (mis. migrate:fresh di lingkungan test/baru),
        // tidak ada data lama untuk di-backfill — lewati. Ini menjaga migrasi
        // ini tetap aman direplay dari nol meski model OrderDetail belakangan
        // mendapat global scope baru (SoftDeletes) lewat migrasi berikutnya.
        if (DB::table('tb_order_details')->count() === 0) {
            return;
        }

        (new TitleBackfillService())->run();
    }

    public function down(): void
    {
        // no-op: title_id dibiarkan saat rollback
    }
};
