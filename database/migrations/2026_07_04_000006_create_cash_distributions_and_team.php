<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_cash_settings', function (Blueprint $table) {
            $table->unsignedInteger('team_members')->default(8)->after('tanggal_awal');
        });

        Schema::create('tb_cash_distributions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type');            // percent | flat
            $table->decimal('value', 15, 2);   // % bila percent; Rp bila flat
            $table->boolean('per_member')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        $now = now();
        DB::table('tb_cash_distributions')->insert([
            ['name' => 'Harta/Pemilik', 'type' => 'percent', 'value' => 5, 'per_member' => false, 'active' => true, 'position' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Saving + Dana Tak Terduga', 'type' => 'percent', 'value' => 10, 'per_member' => false, 'active' => true, 'position' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Fee Tim', 'type' => 'percent', 'value' => 85, 'per_member' => true, 'active' => true, 'position' => 3, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_cash_distributions');
        Schema::table('tb_cash_settings', function (Blueprint $table) {
            $table->dropColumn('team_members');
        });
    }
};
