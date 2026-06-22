<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tambah kolom + index baru DULU. Index (user_id, start_date, end_date) menjadi
        // index pengganti untuk FK user_id, sehingga unique (user_id, year, month) bisa di-drop
        // (MySQL menolak drop index yang sedang dipakai sebuah foreign key).
        Schema::table('tb_marketing_targets', function (Blueprint $table) {
            $table->date('start_date')->nullable()->after('user_id');
            $table->date('end_date')->nullable()->after('start_date');
            $table->uuid('batch_id')->nullable()->after('commission_rate');
            $table->boolean('commission_paid')->default(false)->after('batch_id');
            $table->timestamp('commission_paid_at')->nullable()->after('commission_paid');
            $table->foreignId('commission_paid_by')->nullable()->after('commission_paid_at')->constrained('users')->nullOnDelete();

            $table->index(['user_id', 'start_date', 'end_date']);
            $table->index('batch_id');
        });

        Schema::table('tb_marketing_targets', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'year', 'month']);
            $table->dropColumn(['year', 'month']);
        });
    }

    public function down(): void
    {
        // Re-add unique dulu agar FK user_id punya index backing sebelum index composite di-drop.
        Schema::table('tb_marketing_targets', function (Blueprint $table) {
            $table->smallInteger('year')->after('user_id');
            $table->unsignedTinyInteger('month')->after('year');
            $table->unique(['user_id', 'year', 'month']);
        });

        Schema::table('tb_marketing_targets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('commission_paid_by');
            $table->dropIndex(['user_id', 'start_date', 'end_date']);
            $table->dropIndex(['batch_id']);
            $table->dropColumn(['start_date', 'end_date', 'batch_id', 'commission_paid', 'commission_paid_at']);
        });
    }
};
