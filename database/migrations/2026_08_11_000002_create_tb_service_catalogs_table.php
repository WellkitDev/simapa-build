<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_service_catalogs', function (Blueprint $table) {
            $table->id();
            $table->string('category', 40);
            $table->string('name');
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('price_max', 15, 2)->nullable();
            $table->string('unit', 20)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['category', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_service_catalogs');
    }
};
