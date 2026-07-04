<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_cash_margins', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable();
            $table->string('label');
            $table->decimal('margin_pct', 6, 2);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('tb_cash_fixed_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('period'); // bulanan | tahunan
            $table->decimal('amount', 15, 2);
            $table->text('note')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        $now = now();
        $margins = [
            ['M_ART_S2', 'Artikel Mandiri Sinta 2-3', 25, 1],
            ['M_ART_S4', 'Artikel Mandiri Sinta 4-6', 30, 2],
            ['M_KOL_S2', 'Artikel Kolaborasi Sinta 2-3', 25, 3],
            ['M_KOL_S4', 'Artikel Kolaborasi Sinta 4-6', 30, 4],
            ['M_BK_ALL', 'Buku (semua jenis)', 87, 5],
        ];
        foreach ($margins as [$code, $label, $pct, $pos]) {
            DB::table('tb_cash_margins')->insert(['code' => $code, 'label' => $label, 'margin_pct' => $pct, 'active' => true, 'position' => $pos, 'created_at' => $now, 'updated_at' => $now]);
        }

        $expenses = [
            ['Hosting Avidpedia', 'tahunan', 975000, 1],
            ['Hosting Jurnal', 'tahunan', 1755000, 2],
            ['Domain Avidpedia', 'tahunan', 205000, 3],
            ['Domain Jurnal', 'tahunan', 205000, 4],
            ['Keanggotaan DOI PubMEDIA', 'tahunan', 750000, 5],
            ['Saving Bulanan', 'bulanan', 500000, 6],
        ];
        foreach ($expenses as [$name, $period, $amount, $pos]) {
            DB::table('tb_cash_fixed_expenses')->insert(['name' => $name, 'period' => $period, 'amount' => $amount, 'active' => true, 'position' => $pos, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_cash_fixed_expenses');
        Schema::dropIfExists('tb_cash_margins');
    }
};
