<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_title_doc_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('title_id')->unique()->constrained('tb_titles')->cascadeOnDelete();
            $table->string('status')->default('draft'); // draft | diajukan
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_title_doc_checklists');
    }
};
