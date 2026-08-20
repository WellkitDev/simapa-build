<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\GoogleDriveService;
use App\Services\TitleProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderFulfillmentTest extends TestCase
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

    private function superadmin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');
        return $u->fresh();
    }

    /** Artikel di tahap `loa` — satu langkah sebelum `publish`. */
    private function naskah(string $status = 'loa'): TitleProgress
    {
        $title  = Title::create(['title' => 'Artikel Uji', 'jenis' => 'artikel',
                                 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $order  = Order::factory()->create();
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'at_mandiri',
            'title' => 'Artikel Uji', 'title_id' => $title->id,
        ]);

        return TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => $status,
            'bidang' => 'artikel', 'started_at' => now(),
        ]);
    }

    /** @test */
    public function order_baru_berstatus_berjalan(): void
    {
        $order = Order::factory()->create();

        $this->assertSame('berjalan', $order->fresh()->fulfillment_status);
        $this->assertNull($order->fresh()->completed_at);
    }

    /** @test */
    public function naskah_publish_membuat_ordernya_selesai(): void
    {
        $progress = $this->naskah('loa');

        app(TitleProgressService::class)->advance($progress, $this->superadmin());

        $order = $progress->orderDetail->order->fresh();
        $this->assertSame('publish', $progress->fresh()->status);
        $this->assertSame('selesai', $order->fulfillment_status);
        $this->assertNotNull($order->completed_at);
    }

    /** @test */
    public function koreksi_mundur_mengembalikan_order_ke_berjalan(): void
    {
        $progress = $this->naskah('publish');
        $order    = $progress->orderDetail->order;
        $order->update(['fulfillment_status' => 'selesai', 'completed_at' => now()]);

        app(TitleProgressService::class)
            ->correct($progress, 'revisi', $this->superadmin(), 'Salah tandai');

        $order->refresh();
        $this->assertSame('berjalan', $order->fulfillment_status);
        $this->assertNull($order->completed_at);
    }

    /** @test */
    public function order_dibatalkan_tidak_ditimpa_jadi_selesai(): void
    {
        $progress = $this->naskah('loa');
        $order    = $progress->orderDetail->order;
        $order->update(['fulfillment_status' => 'dibatalkan']);

        app(TitleProgressService::class)->advance($progress, $this->superadmin());

        $this->assertSame('dibatalkan', $order->fresh()->fulfillment_status);
    }
}
