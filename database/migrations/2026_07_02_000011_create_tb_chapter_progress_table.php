<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_chapter_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('title_chapter_id')->unique()->constrained('tb_title_chapters')->cascadeOnDelete();
            $table->string('status', 16)->default('menunggu_proses');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('needs_review')->default(false);
            $table->text('note')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_log_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_chapter_progress');
    }
};
