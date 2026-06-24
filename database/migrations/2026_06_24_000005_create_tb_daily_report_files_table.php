<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_daily_report_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_report_id')->constrained('tb_daily_reports')->cascadeOnDelete();
            $table->string('drive_file_id');
            $table->string('name');
            $table->string('url', 1024);
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_daily_report_files');
    }
};
