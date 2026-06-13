<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tb_title_progress_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('title_progress_id')->constrained('tb_title_progress')->cascadeOnDelete();
            $table->string('from_status', 50);
            $table->string('to_status', 50);
            $table->foreignId('changed_by')->constrained('users');
            $table->text('note')->nullable();
            $table->boolean('is_correction')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_title_progress_logs');
    }
};
