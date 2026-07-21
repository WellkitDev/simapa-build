<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_salary_slip_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_slip_id')->constrained('tb_salary_slips')->cascadeOnDelete();
            $table->string('type');  // earning | deduction
            $table->string('label');
            $table->decimal('amount', 15, 2)->default(0);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index(['salary_slip_id', 'type']);
        });
    }

    public function down(): void { Schema::dropIfExists('tb_salary_slip_lines'); }
};
