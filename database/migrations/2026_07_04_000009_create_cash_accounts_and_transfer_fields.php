<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_cash_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('purpose')->nullable();
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->boolean('is_income_default')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        $saldoAwal = (float) (DB::table('tb_cash_settings')->value('saldo_awal') ?? 0);
        $now = now();
        DB::table('tb_cash_accounts')->insert([
            ['name' => 'Kas Pemasukan', 'purpose' => 'pemasukan', 'opening_balance' => $saldoAwal, 'is_income_default' => true,  'active' => true, 'position' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Operational',   'purpose' => 'operational', 'opening_balance' => 0, 'is_income_default' => false, 'active' => true, 'position' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Harta',         'purpose' => 'harta', 'opening_balance' => 0, 'is_income_default' => false, 'active' => true, 'position' => 3, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::table('tb_cash_entries', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('cash_category_id')
                  ->constrained('tb_cash_accounts')->nullOnDelete();
            $table->boolean('is_transfer')->default(false)->after('source');
            $table->string('transfer_group')->nullable()->after('is_transfer');
        });

        $incomeId = DB::table('tb_cash_accounts')->where('is_income_default', true)->value('id');
        DB::table('tb_cash_entries')->whereNull('account_id')->update(['account_id' => $incomeId]);
    }

    public function down(): void
    {
        Schema::table('tb_cash_entries', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropColumn(['account_id', 'is_transfer', 'transfer_group']);
        });
        Schema::dropIfExists('tb_cash_accounts');
    }
};
