<?php
// database/migrations/2026_06_14_000002_add_group_key_to_tb_order_details.php

use App\Models\OrderDetail;
use App\Services\TitleArchiveService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tb_order_details', function (Blueprint $table) {
            $table->string('group_key')->nullable()->after('slug')->index();
        });

        // Backfill data lama (tanpa memicu model event — pakai DB langsung).
        $svc = new TitleArchiveService();
        OrderDetail::query()
            ->select('id', 'type', 'title')
            ->chunkById(200, function ($rows) use ($svc) {
                foreach ($rows as $row) {
                    DB::table('tb_order_details')
                        ->where('id', $row->id)
                        ->update(['group_key' => $svc->groupKeyFor($row->type, $row->title)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('tb_order_details', function (Blueprint $table) {
            $table->dropIndex(['group_key']);
            $table->dropColumn('group_key');
        });
    }
};
