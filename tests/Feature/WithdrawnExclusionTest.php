<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WithdrawnExclusionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    /** @test */
    public function scope_active_menyembunyikan_baris_yang_ditarik(): void
    {
        $title  = Title::create(['title' => 'Judul Uji', 'jenis' => 'artikel',
                                 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $order  = Order::factory()->create();
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'at_mandiri',
            'title' => 'Judul Uji', 'title_id' => $title->id,
        ]);
        $progress = TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => 'editing',
            'bidang' => 'artikel', 'started_at' => now(),
        ]);

        $this->assertSame(1, TitleProgress::active()->count());

        $progress->update(['withdrawn_at' => now(), 'withdrawn_reason' => 'Refund penuh']);

        $this->assertSame(0, TitleProgress::active()->count());
        $this->assertTrue($progress->fresh()->isWithdrawn());
    }
}
