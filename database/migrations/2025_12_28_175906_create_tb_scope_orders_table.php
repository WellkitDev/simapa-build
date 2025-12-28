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
        Schema::create('tb_scope_orders', function (Blueprint $table) {
            $table->foreignId('scope_id')
                ->constrained('tb_scopes')
                ->cascadeOnDelete();

            $table->foreignId('order_detail_id')
                ->constrained('tb_order_details')
                ->cascadeOnDelete();

            $table->primary(['scope_id', 'order_detail_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tb_scope_orders');
    }
};
