<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderCancelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    /** @test */
    public function kolom_pembatalan_dan_soft_delete_tersedia(): void
    {
        $this->assertTrue(Schema::hasColumns('tb_orders', ['cancel_reason', 'cancelled_by', 'cancelled_at', 'deleted_at']));
        $this->assertTrue(Schema::hasColumn('tb_order_details', 'deleted_at'));
        $this->assertTrue(Schema::hasColumn('tb_title_progress', 'deleted_at'));
    }

    /** @test */
    public function tiga_model_memakai_soft_deletes(): void
    {
        $owner  = $this->user('marketing');
        $order  = Order::factory()->create(['user_id' => $owner->id]);
        $detail = OrderDetail::factory()->create(['order_id' => $order->id]);
        $prog   = TitleProgress::create([
            'order_detail_id' => $detail->id,
            'status'          => 'menunggu_proses',
            'assigned_role'   => 'marketing',
            'updated_by'      => $owner->id,
            'started_at'      => now(),
        ]);

        $order->delete();
        $detail->delete();
        $prog->delete();

        $this->assertSoftDeleted('tb_orders', ['id' => $order->id]);
        $this->assertSoftDeleted('tb_order_details', ['id' => $detail->id]);
        $this->assertSoftDeleted('tb_title_progress', ['id' => $prog->id]);
        $this->assertNull(Order::find($order->id));
        $this->assertNull(OrderDetail::find($detail->id));
        $this->assertNull(TitleProgress::find($prog->id));
    }
}
