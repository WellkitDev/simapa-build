<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tb_title_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_detail_id')->unique()->constrained('tb_order_details')->cascadeOnDelete();
            $table->string('status', 50)->default('menunggu_proses');
            $table->string('assigned_role', 50)->default('marketing');
            $table->text('note')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_title_progress');
    }
};
