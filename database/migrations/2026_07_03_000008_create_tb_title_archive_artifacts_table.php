<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_title_archive_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('title_id')->constrained('tb_titles')->cascadeOnDelete();
            $table->string('key')->nullable();
            $table->string('label');
            $table->string('type')->default('text'); // file | link | text
            $table->text('value')->nullable();
            $table->string('file_name')->nullable();
            $table->foreignId('pic_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->boolean('is_custom')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('tb_title_archive_artifacts'); }
};
