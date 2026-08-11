<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_service_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_invoice_id')->constrained('tb_service_invoices')->cascadeOnDelete();
            $table->foreignId('service_catalog_id')->nullable()
                  ->constrained('tb_service_catalogs')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('qty', 8, 2)->default(1);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_service_invoice_items');
    }
};
