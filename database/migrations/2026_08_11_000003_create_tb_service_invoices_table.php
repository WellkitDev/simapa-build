<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_service_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no')->unique();
            $table->foreignId('service_client_id')->nullable()
                  ->constrained('tb_service_clients')->nullOnDelete();

            // SNAPSHOT klien — sumber kebenaran untuk cetakan.
            $table->string('client_name');
            $table->string('client_institution')->nullable();
            $table->string('client_email')->nullable();
            $table->string('client_phone', 40)->nullable();
            $table->text('client_address')->nullable();

            $table->date('issued_at');
            $table->date('due_at')->nullable();

            $table->string('work_status', 20)->default('belum');
            $table->timestamp('work_started_at')->nullable();
            $table->timestamp('work_finished_at')->nullable();

            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('paid_total', 15, 2)->default(0);
            $table->decimal('remaining', 15, 2)->default(0);
            $table->string('payment_status', 20)->default('belum');

            $table->text('note')->nullable();
            $table->text('internal_note')->nullable();

            $table->string('pdf_drive_url')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('sent_count')->default(0);

            $table->text('cancel_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['work_status', 'payment_status']);
            $table->index('issued_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_service_invoices');
    }
};
