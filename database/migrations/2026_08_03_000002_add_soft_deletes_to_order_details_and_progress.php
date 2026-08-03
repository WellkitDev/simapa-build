<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_order_details', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('tb_title_progress', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('tb_order_details', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('tb_title_progress', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
