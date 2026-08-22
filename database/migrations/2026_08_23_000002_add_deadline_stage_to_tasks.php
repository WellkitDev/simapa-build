<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap pengingat tenggang yang TERAKHIR dikirim untuk satu tugas.
 *
 * `deadline_notified_at` yang sudah ada hanya bisa menjawab "sudah pernah diingatkan
 * atau belum" — sekali terisi, tugas itu tak pernah diingatkan lagi. Cukup untuk satu
 * pengingat, tapi tak bisa membedakan tiga tahap yang diminta: mendekati tenggang, hari
 * jatuh tempo, dan lewat tenggang.
 *
 * Kolom ini yang membuat tiap tahap berbunyi tepat SEKALI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_tasks', function (Blueprint $t) {
            $t->string('deadline_stage', 10)->nullable()->after('deadline_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('tb_tasks', function (Blueprint $t) {
            $t->dropColumn('deadline_stage');
        });
    }
};
