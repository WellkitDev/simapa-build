<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tb_manuscript_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('title_id')->constrained('tb_titles')->cascadeOnDelete();
            $t->foreignId('title_chapter_id')->nullable()->constrained('tb_title_chapters')->nullOnDelete();
            $t->string('slot', 20);              // 'masuk' | 'final'
            $t->unsignedInteger('version')->default(1);
            $t->string('original_name');
            $t->string('drive_file_id')->nullable();
            $t->string('drive_url')->nullable();
            $t->unsignedBigInteger('file_size')->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->nullable();
            $t->index(['title_id', 'title_chapter_id', 'slot', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_manuscript_files');
    }
};
