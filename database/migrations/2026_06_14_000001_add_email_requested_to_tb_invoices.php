<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_invoices', function (Blueprint $table) {
            $table->boolean('email_requested')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tb_invoices', function (Blueprint $table) {
            $table->dropColumn('email_requested');
        });
    }
};
