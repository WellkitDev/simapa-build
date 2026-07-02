<?php

use App\Models\Title;
use App\Services\TitleCodeService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $svc = new TitleCodeService();
        Title::whereNull('code')->orderBy('id')->get()->each(function (Title $t) use ($svc) {
            $t->update(['code' => $svc->generate($t->title, $t->id)]);
        });
    }

    public function down(): void
    {
        // no-op: kode dibiarkan saat rollback
    }
};
