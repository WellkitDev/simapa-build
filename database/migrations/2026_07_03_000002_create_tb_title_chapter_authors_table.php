<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_title_chapter_authors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('title_chapter_id')->constrained('tb_title_chapters')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('tb_authors')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_title_chapter_authors');
    }
};
