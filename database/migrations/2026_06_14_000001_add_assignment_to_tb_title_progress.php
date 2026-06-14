<?php
// database/migrations/2026_06_14_000001_add_assignment_to_tb_title_progress.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tb_title_progress', function (Blueprint $table) {
            $table->unsignedBigInteger('assigned_user_id')->nullable()->after('updated_by');
            $table->string('priority', 10)->default('normal')->after('assigned_user_id');

            $table->foreign('assigned_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index('status');
            $table->index('assigned_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('tb_title_progress', function (Blueprint $table) {
            $table->dropForeign(['assigned_user_id']);
            $table->dropIndex(['status']);
            $table->dropIndex(['assigned_user_id']);
            $table->dropColumn(['assigned_user_id', 'priority']);
        });
    }
};
