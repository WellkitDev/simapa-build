<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\Invoice;
use App\Models\OrderDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class InvoiceIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    /** @test */
    public function empty_invoice_index_renders_without_manual_colspan_row(): void
    {
        $this->actingAs($this->user('manager'));
        $html = $this->get(route('invoice.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('colspan="7"', $html);
    }

    /** @test */
    public function download_button_shown_only_for_issued_or_paid_invoice(): void
    {
        $manager = $this->user('manager');
        $order = Order::factory()->create();
        OrderDetail::factory()->create(['order_id' => $order->id]);
        $draft  = Invoice::factory()->create(['order_id' => $order->id, 'status' => 'draft', 'invoice_no' => 'INV-DRAFT']);
        $issued = Invoice::factory()->create(['order_id' => $order->id, 'status' => 'diterbitkan', 'invoice_no' => 'INV-ISSUED']);

        $this->actingAs($manager);
        $html = $this->get(route('invoice.index'))->assertOk()->getContent();

        $this->assertStringContainsString(route('invoice.pdf', $issued->id), $html);
        $this->assertStringNotContainsString(route('invoice.pdf', $draft->id), $html);
    }

    /** @test */
    public function manual_invoice_create_route_is_removed(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('invoice.create'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('invoice.store'));
    }
}
