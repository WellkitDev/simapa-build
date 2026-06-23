<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_marketing_targets', function (Blueprint $table) {
            // 'percent' → komisi = commission_rate% × realisasi; 'flat' → komisi = commission_flat (nominal tetap).
            $table->string('commission_type', 16)->default('percent')->after('commission_rate');
            $table->decimal('commission_flat', 15, 0)->default(0)->after('commission_type');
        });
    }

    public function down(): void
    {
        Schema::table('tb_marketing_targets', function (Blueprint $table) {
            $table->dropColumn(['commission_type', 'commission_flat']);
        });
    }
};
