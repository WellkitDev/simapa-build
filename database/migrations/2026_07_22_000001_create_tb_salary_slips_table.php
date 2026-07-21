<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_salary_slips', function (Blueprint $table) {
            $table->id();
            $table->string('slip_no')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('employee_name');
            $table->string('employee_position')->nullable();
            $table->smallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->string('status')->default('draft'); // draft | terbit
            $table->decimal('total_earnings', 15, 2)->default(0);
            $table->decimal('total_deductions', 15, 2)->default(0);
            $table->decimal('net_pay', 15, 2)->default(0);
            $table->text('note')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'period_year', 'period_month']);
            $table->index(['period_year', 'period_month']);
        });
    }

    public function down(): void { Schema::dropIfExists('tb_salary_slips'); }
};
