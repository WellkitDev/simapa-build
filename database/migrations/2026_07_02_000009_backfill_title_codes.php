<?php

use App\Services\TitleCodeService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Backfill data lama — pakai query builder langsung, BUKAN model
        // Eloquent Title: model bisa punya global scope (mis. SoftDeletes)
        // yang ditambahkan belakangan lewat migrasi lain, dan migrasi ini
        // harus tetap jalan bila database di-replay dari nol.
        $svc = new TitleCodeService();
        DB::table('tb_titles')
            ->whereNull('code')
            ->orderBy('id')
            ->get(['id', 'title'])
            ->each(function ($t) use ($svc) {
                DB::table('tb_titles')->where('id', $t->id)->update(['code' => $svc->generate($t->title, $t->id)]);
            });
    }

    public function down(): void
    {
        // no-op: kode dibiarkan saat rollback
    }
};
