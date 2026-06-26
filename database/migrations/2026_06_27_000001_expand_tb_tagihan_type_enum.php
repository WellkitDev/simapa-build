<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Expand type ENUM to include the 4 specific service values alongside legacy buku/jurnal.
        DB::statement("ALTER TABLE tb_tagihan MODIFY COLUMN `type` ENUM('bk_mandiri','bk_kolab','at_mandiri','at_kolab','buku','jurnal') NOT NULL DEFAULT 'bk_mandiri'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE tb_tagihan MODIFY COLUMN `type` ENUM('buku','jurnal') NOT NULL DEFAULT 'buku'");
    }
};
