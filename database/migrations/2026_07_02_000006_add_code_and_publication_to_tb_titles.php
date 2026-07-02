<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_titles', function (Blueprint $table) {
            $table->string('code', 16)->nullable()->unique()->after('title');
            $table->date('target_terbit')->nullable()->after('reject_note');
            $table->string('jurnal_target')->nullable()->after('target_terbit');
            $table->string('jurnal_link')->nullable()->after('jurnal_target');
            $table->string('template_link')->nullable()->after('jurnal_link');
            $table->string('apc_info')->nullable()->after('template_link');
            $table->text('catatan_publikasi')->nullable()->after('apc_info');
        });
    }

    public function down(): void
    {
        Schema::table('tb_titles', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'target_terbit', 'jurnal_target', 'jurnal_link', 'template_link', 'apc_info', 'catatan_publikasi']);
        });
    }
};
