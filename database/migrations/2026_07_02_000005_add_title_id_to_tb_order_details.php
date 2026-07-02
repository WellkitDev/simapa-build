<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_order_details', function (Blueprint $table) {
            $table->foreignId('title_id')->nullable()->after('type')->constrained('tb_titles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tb_order_details', function (Blueprint $table) {
            $table->dropConstrainedForeignId('title_id');
        });
    }
};
