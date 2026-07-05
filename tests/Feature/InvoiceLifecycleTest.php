<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\Invoice;
use App\Models\InvoiceLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class InvoiceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $marketing;
    private User $manager;
    private User $superadmin;
    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'marketing',  'guard_name' => 'web']);
        Role::create(['name' => 'manager',    'guard_name' => 'web']);
        Role::create(['name' => 'superadmin', 'guard_name' => 'web']);

        $this->marketing  = User::factory()->create(); $this->marketing->assignRole('marketing');
        $this->manager    = User::factory()->create(); $this->manager->assignRole('manager');
        $this->superadmin = User::factory()->create(); $this->superadmin->assignRole('superadmin');

        $this->order = Order::factory()->create(['status' => 'pending']);
    }

    /** @test */
    public function marketing_cannot_edit_invoice(): void
    {
        $invoice = Invoice::factory()->create([
            'order_id' => $this->order->id,
            'type'     => 'proforma',
            'status'   => 'draft',
        ]);

        $this->actingAs($this->marketing);

        $this->get(route('invoice.edit', $invoice->id))->assertStatus(403);
    }

    /** @test */
    public function manager_can_update_status(): void
    {
        $invoice = Invoice::factory()->create([
            'order_id' => $this->order->id,
            'type'     => 'proforma',
            'status'   => 'draft',
        ]);

        $this->actingAs($this->manager);

        $this->post(route('invoice.updateStatus', $invoice->id), [
            'status' => 'diterbitkan',
            'note'   => '',
        ])->assertRedirect();

        $this->assertDatabaseHas('tb_invoices', ['id' => $invoice->id, 'status' => 'diterbitkan']);
        $this->assertDatabaseHas('tb_invoice_logs', ['invoice_id' => $invoice->id, 'to_status' => 'diterbitkan']);
    }

    /** @test */
    public function manager_cannot_cancel_invoice(): void
    {
        $invoice = Invoice::factory()->create([
            'order_id' => $this->order->id,
            'status'   => 'diterbitkan',
        ]);

        $this->actingAs($this->manager);

        $this->post(route('invoice.cancel', $invoice->id), ['note' => 'Coba cancel'])
            ->assertStatus(403);
    }

    /** @test */
    public function superadmin_can_cancel_invoice_with_note(): void
    {
        $invoice = Invoice::factory()->create([
            'order_id' => $this->order->id,
            'status'   => 'diterbitkan',
        ]);

        $this->actingAs($this->superadmin);

        $this->post(route('invoice.cancel', $invoice->id), ['note' => 'Dibatalkan karena permintaan klien'])
            ->assertRedirect();

        $this->assertDatabaseHas('tb_invoices', ['id' => $invoice->id, 'status' => 'dibatalkan']);
    }

    /** @test */
    public function superadmin_can_refund_only_lunas_invoice(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        \App\Models\Payment::create(['order_id' => $this->order->id, 'payment_type' => 'lunas', 'amount' => 500000, 'status' => 'paid', 'paid_at' => '2026-06-01']);

        $lunas = Invoice::factory()->create(['order_id' => $this->order->id, 'status' => 'lunas']);
        $draft = Invoice::factory()->create(['order_id' => $this->order->id, 'status' => 'draft']);

        $this->actingAs($this->superadmin);

        $this->post(route('invoice.refund', $lunas->id), [
            'amount' => 200000, 'reason' => 'Dana dikembalikan', 'method' => 'transfer', 'tanggal' => '2026-06-05',
        ])->assertRedirect();
        $this->assertDatabaseHas('tb_invoices', ['id' => $lunas->id, 'status' => 'refund']);

        $this->post(route('invoice.refund', $draft->id), [
            'amount' => 100000, 'reason' => 'Coba refund draft', 'method' => 'transfer', 'tanggal' => '2026-06-05',
        ])->assertSessionHasErrors();
    }
}
