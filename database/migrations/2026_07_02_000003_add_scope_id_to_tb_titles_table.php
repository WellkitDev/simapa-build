<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_titles', function (Blueprint $table) {
            $table->foreignId('scope_id')->nullable()->after('tipe_naskah')->constrained('tb_scopes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tb_titles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scope_id');
        });
    }
};
