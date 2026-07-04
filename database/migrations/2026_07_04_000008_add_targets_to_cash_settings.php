<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_cash_settings', function (Blueprint $table) {
            $table->decimal('target_operasional', 15, 2)->default(0)->after('team_members');
            $table->decimal('target_order', 15, 2)->default(0)->after('target_operasional');
        });
    }

    public function down(): void
    {
        Schema::table('tb_cash_settings', function (Blueprint $table) {
            $table->dropColumn(['target_operasional', 'target_order']);
        });
    }
};
