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
        Schema::create('tb_author_orders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_detail_id')
                ->constrained('tb_order_details')
                ->cascadeOnDelete();

            $table->foreignId('author_id')
                ->constrained('tb_authors')
                ->cascadeOnDelete();

            $table->integer('position');

            $table->unique(['order_detail_id', 'author_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tb_author_orders');
    }
};
