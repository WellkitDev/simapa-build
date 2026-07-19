<?php
// tests/Unit/ManuscriptStageStatsServiceTest.php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\ManuscriptStageStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ManuscriptStageStatsServiceTest extends TestCase
{
    use RefreshDatabase;

    private ManuscriptStageStatsService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'production'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        $this->svc = app(ManuscriptStageStatsService::class);
    }

    /** Satu judul (group_key sama) dengan varian di dua tahap → dihitung SEKALI di bottleneck. */
    private function titleWithVariants(string $type, string $title, array $stages, ?int $assignedTo = null): void
    {
        $order = Order::factory()->create();
        foreach ($stages as $status) {
            $detail = OrderDetail::factory()->create([
                'order_id' => $order->id, 'type' => $type, 'title' => $title,
            ]);
            TitleProgress::create([
                'order_detail_id' => $detail->id, 'status' => $status,
                'assigned_role' => 'production', 'assigned_user_id' => $assignedTo,
                'started_at' => now(),
            ]);
        }
    }

    /** @test */
    public function menghitung_judul_unik_bukan_baris_order_detail(): void
    {
        // Satu buku, 2 varian: editing (idx 1) & layout (idx 2) → bottleneck = editing.
        $this->titleWithVariants('bk_mandiri', 'Buku A', ['editing', 'layout']);

        $out = $this->svc->global();

        $this->assertSame(['Editing'], $out['buku']['labels']);
        $this->assertSame([1], $out['buku']['series']); // 1 judul, bukan 2
    }

    /** @test */
    public function memisahkan_buku_dan_artikel(): void
    {
        $this->titleWithVariants('bk_mandiri', 'Buku A', ['editing']);
        $this->titleWithVariants('bk_kolab',   'Buku B', ['layout']);
        $this->titleWithVariants('jr_sinta',   'Artikel A', ['templating']);

        $out = $this->svc->global();

        $this->assertEqualsCanonicalizing(['Editing', 'Layout'], $out['buku']['labels']);
        $this->assertSame(2, array_sum($out['buku']['series']));
        $this->assertSame(['Templating'], $out['artikel']['labels']);
        $this->assertSame([1], $out['artikel']['series']);
    }

    /** @test */
    public function mengecualikan_menunggu_proses_dan_final(): void
    {
        $this->titleWithVariants('bk_mandiri', 'Nunggu', ['menunggu_proses']);
        $this->titleWithVariants('bk_mandiri', 'Selesai', ['terbit']);
        $this->titleWithVariants('bk_mandiri', 'Aktif', ['proofreading']);

        $out = $this->svc->global();

        $this->assertSame(['Proofreading'], $out['buku']['labels']);
        $this->assertSame([1], $out['buku']['series']);
    }

    /** @test */
    public function labels_terurut_sesuai_urutan_tahap(): void
    {
        $this->titleWithVariants('bk_mandiri', 'B1', ['isbn']);        // idx 4
        $this->titleWithVariants('bk_mandiri', 'B2', ['editing']);     // idx 1
        $this->titleWithVariants('bk_mandiri', 'B3', ['layout']);      // idx 2

        $out = $this->svc->global();

        $this->assertSame(['Editing', 'Layout', 'Isbn'], $out['buku']['labels']);
    }

    /** @test */
    public function for_editor_hanya_judul_yang_punya_varian_miliknya(): void
    {
        $me = User::factory()->create(); $me->assignRole('production');
        $other = User::factory()->create(); $other->assignRole('production');

        $this->titleWithVariants('bk_mandiri', 'Milikku', ['editing'], $me->id);
        $this->titleWithVariants('bk_mandiri', 'Milik Orang', ['editing'], $other->id);

        $out = $this->svc->forEditor($me);

        $this->assertSame([1], $out['buku']['series']); // hanya "Milikku"
    }

    /** @test */
    public function kosong_menghasilkan_labels_dan_series_kosong(): void
    {
        $out = $this->svc->global();
        $this->assertSame([], $out['buku']['labels']);
        $this->assertSame([], $out['buku']['series']);
        $this->assertSame([], $out['artikel']['labels']);
    }
}
