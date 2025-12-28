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
        Schema::create('tb_order_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')
                ->constrained('tb_orders')
                ->cascadeOnDelete();

            $table->string('type');
            $table->string('title');
            $table->string('slug');
            $table->integer('chapters')->nullable();
            $table->string('indexation')->nullable();
            $table->string('naskah_type');
            $table->string('publication_type');
            $table->decimal('cost_amount', 15, 0);

            $table->timestamps();

            $table->index(['order_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tb_order_details');
    }
};
