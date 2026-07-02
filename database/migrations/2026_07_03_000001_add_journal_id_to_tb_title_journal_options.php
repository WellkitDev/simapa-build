<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_title_journal_options', function (Blueprint $table) {
            $table->foreignId('journal_id')->nullable()->after('title_id')->constrained('tb_journals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tb_title_journal_options', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_id');
        });
    }
};
