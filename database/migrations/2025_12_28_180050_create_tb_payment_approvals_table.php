<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tb_payment_approvals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payment_id')
                ->constrained('tb_payments')
                ->cascadeOnDelete();

            $table->string('approved_by')->nullable();
            $table->string('status')->default('pending');
            $table->text('note')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tb_payment_approvals');
    }
};
