<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_title_chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('title_id')->constrained('tb_titles')->cascadeOnDelete();
            $table->string('judul');
            $table->unsignedInteger('urutan')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_title_chapters');
    }
};
