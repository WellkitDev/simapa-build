<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_cash_period_locks', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->unsignedBigInteger('locked_by')->nullable();
            $table->timestamp('locked_at');
            $table->timestamps();

            $table->unique(['year', 'month']);
            $table->foreign('locked_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_cash_period_locks');
    }
};
