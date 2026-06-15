<?php
// database/migrations/2026_06_15_000002_upgrade_title_progress_logs_to_activity_log.php
//
// Mengubah tb_title_progress_logs dari log khusus-status menjadi log aktivitas umum
// (status, editor, prioritas, target, review) dengan skema generik event/from/to.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tb_title_progress_logs', function (Blueprint $table) {
            $table->string('event', 30)->default('status_advanced')->after('title_progress_id');
            $table->string('from_value', 150)->nullable()->after('event');
            $table->string('to_value', 150)->nullable()->after('from_value');
        });

        // Backfill data lama (status-only) ke skema generik.
        DB::table('tb_title_progress_logs')->update([
            'from_value' => DB::raw('from_status'),
            'to_value'   => DB::raw('to_status'),
        ]);
        DB::table('tb_title_progress_logs')->where('is_correction', true)->update(['event' => 'status_corrected']);

        Schema::table('tb_title_progress_logs', function (Blueprint $table) {
            $table->dropColumn(['from_status', 'to_status']);
        });

        Schema::table('tb_title_progress', function (Blueprint $table) {
            $table->timestamp('last_log_at')->nullable()->after('target_date');
        });
    }

    public function down(): void
    {
        Schema::table('tb_title_progress_logs', function (Blueprint $table) {
            $table->string('from_status', 50)->nullable()->after('title_progress_id');
            $table->string('to_status', 50)->nullable()->after('from_status');
        });

        DB::table('tb_title_progress_logs')->update([
            'from_status' => DB::raw('from_value'),
            'to_status'   => DB::raw('to_value'),
        ]);

        Schema::table('tb_title_progress_logs', function (Blueprint $table) {
            $table->dropColumn(['event', 'from_value', 'to_value']);
        });

        Schema::table('tb_title_progress', function (Blueprint $table) {
            $table->dropColumn('last_log_at');
        });
    }
};
