<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_invoices', function (Blueprint $table) {
            $table->text('refund_reason')->nullable()->after('refunded_at');
            $table->string('refund_method')->nullable()->after('refund_reason');
            $table->string('refund_account')->nullable()->after('refund_method');
            $table->foreignId('refund_payment_id')->nullable()->after('refund_account')
                  ->constrained('tb_payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tb_invoices', function (Blueprint $table) {
            $table->dropForeign(['refund_payment_id']);
            $table->dropColumn(['refund_reason', 'refund_method', 'refund_account', 'refund_payment_id']);
        });
    }
};
