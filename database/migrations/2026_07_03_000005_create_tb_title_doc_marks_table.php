<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_title_doc_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('title_id')->constrained('tb_titles')->cascadeOnDelete();
            $table->foreignId('doc_requirement_id')->constrained('tb_doc_requirements')->cascadeOnDelete();
            $table->string('status')->default('belum'); // ada | belum | tidak_perlu
            $table->string('file_url')->nullable();
            $table->string('file_name')->nullable();
            $table->text('catatan')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['title_id', 'doc_requirement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_title_doc_marks');
    }
};
