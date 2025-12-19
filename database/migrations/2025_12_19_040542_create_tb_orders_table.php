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
        Schema::create('tb_orders', function (Blueprint $table) {
            $table->id();
            $table->string('code_order')->unique();
            $table->enum('type', ['bk_mandiri', 'bk_kolab', 'at_mandiri', 'at_kolab']);
            $table->string('title');
            $table->string('slug');
            $table->integer('chapters')->nullable();
            $table->string('indexation')->nullable();
            $table->enum('naskah_type', ['dibuatkan', 'mandiri']);
            $table->enum('publication_type', ['regular', 'fastrack']);
            $table->string('contact_phone');
            $table->string('contact_email');
            $table->decimal('cost_amount', 15, 0);
            $table->decimal('pay_amount', 15, 0)->default(0);
            $table->decimal('debit_amount', 15, 0)->default(0);
            $table->enum('status', ['pending', 'approved', 'in_production', 'completed', 'refunded'])->default('pending');
            $table->text('note')->nullable();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tb_orders');
    }
};
