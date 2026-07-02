<?php

use App\Services\TitleBackfillService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new TitleBackfillService())->run();
    }

    public function down(): void
    {
        // no-op: title_id dibiarkan saat rollback
    }
};
