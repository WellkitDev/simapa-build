<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // payment_id sudah nullable dari migration awal, tidak perlu diubah

        Schema::table('tb_invoices', function (Blueprint $table) {
            // Kolom baru
            $table->string('type', 20)->default('regular')->after('invoice_no');
            $table->text('note')->nullable()->after('status');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete()->after('note');
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete()->after('cancelled_at');
            $table->timestamp('refunded_at')->nullable()->after('refunded_by');
        });

        // Data migration: existing records jadi type=regular, status pending -> diterbitkan
        DB::table('tb_invoices')->update(['type' => 'regular']);
        DB::table('tb_invoices')->where('status', 'pending')->update(['status' => 'diterbitkan']);
    }

    public function down(): void
    {
        Schema::table('tb_invoices', function (Blueprint $table) {
            $table->dropForeign(['cancelled_by']);
            $table->dropForeign(['refunded_by']);
            $table->dropColumn(['type', 'note', 'cancelled_by', 'cancelled_at', 'refunded_by', 'refunded_at']);
        });
    }
};
