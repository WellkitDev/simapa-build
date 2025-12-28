<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tb_order_contacts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')
                ->constrained('tb_orders')
                ->cascadeOnDelete();

            $table->string('cp_phone');
            $table->string('cp_email');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tb_order_contacts');
    }
};
