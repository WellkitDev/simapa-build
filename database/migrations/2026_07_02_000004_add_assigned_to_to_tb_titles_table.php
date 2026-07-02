<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_titles', function (Blueprint $table) {
            // Distribusi judul ke marketing. NULL = semua marketing; diisi = hanya marketing tsb.
            $table->foreignId('assigned_to')->nullable()->after('scope_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tb_titles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_to');
        });
    }
};
