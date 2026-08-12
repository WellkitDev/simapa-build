<?php

namespace Tests\Feature;

use App\Models\ServiceInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ServiceInvoiceStatusRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
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
    public function manager_can_move_the_work_status(): void
    {
        $inv = ServiceInvoice::factory()->create(['work_status' => 'belum']);

        $this->actingAs($this->user('manager'))->post(route('service.invoice.status', $inv->id), [
            'work_status' => 'proses', 'note' => 'mulai instalasi',
        ])->assertRedirect();

        $inv->refresh();
        $this->assertSame('proses', $inv->work_status);
        $this->assertNotNull($inv->work_started_at);
        $this->assertSame('mulai instalasi', $inv->logs->first()->note);
    }

    /** @test */
    public function batal_cannot_be_reached_through_the_status_route(): void
    {
        $inv = ServiceInvoice::factory()->create(['work_status' => 'proses']);

        $this->actingAs($this->user('superadmin'))
            ->post(route('service.invoice.status', $inv->id), ['work_status' => 'batal'])
            ->assertSessionHasErrors('work_status');

        $this->assertSame('proses', $inv->fresh()->work_status);
    }

    /** @test */
    public function only_superadmin_can_cancel_and_a_reason_is_required(): void
    {
        $inv = ServiceInvoice::factory()->create(['work_status' => 'proses']);

        // Penolakan non-GET = redirect + flash (EnforcePermission::deny), bukan 403.
        $this->actingAs($this->user('manager'))
            ->post(route('service.invoice.cancel', $inv->id), ['cancel_reason' => 'klien mundur'])
            ->assertRedirect();
        $this->assertSame('proses', $inv->fresh()->work_status);

        $superadmin = $this->user('superadmin');

        $this->actingAs($superadmin)
            ->post(route('service.invoice.cancel', $inv->id), [])
            ->assertSessionHasErrors('cancel_reason');

        $this->actingAs($superadmin)
            ->post(route('service.invoice.cancel', $inv->id), ['cancel_reason' => 'klien mundur'])
            ->assertRedirect();

        $inv->refresh();
        $this->assertSame('batal', $inv->work_status);
        $this->assertSame('klien mundur', $inv->cancel_reason);
        $this->assertSame($superadmin->id, $inv->cancelled_by);
        $this->assertNotNull($inv->cancelled_at);
        $this->assertSame('cancelled', $inv->logs->first()->event);
    }

    /** @test */
    public function cancelled_invoice_refuses_further_status_changes(): void
    {
        $inv = ServiceInvoice::factory()->create(['work_status' => 'batal']);

        $this->actingAs($this->user('manager'))
            ->post(route('service.invoice.status', $inv->id), ['work_status' => 'proses'])
            ->assertRedirect();

        $this->assertSame('batal', $inv->fresh()->work_status);
    }

    /** @test */
    public function the_cancelled_panel_renders_without_offering_status_changes(): void
    {
        // Blade yang gagal DIKOMPILASI hanya terlihat kalau view-nya benar-benar
        // dirender (aturan global 10/13). Cabang isCancelled() dari panel status
        // (canceller->name, cancelled_at->format()) tidak tersentuh tes lain manapun —
        // every_screen_renders_for_manager_and_superadmin() (ServiceInvoiceStoreTest)
        // memakai invoice yang tidak dibatalkan.
        $superadmin = $this->user('superadmin');
        $inv = ServiceInvoice::factory()->create(['work_status' => 'proses']);
        $inv->logs()->create(['event' => 'created']);

        app(\App\Services\ServiceInvoiceWorkflow::class)->cancel($inv, 'klien mundur', $superadmin->id);

        foreach (['manager', 'superadmin'] as $role) {
            $response = $this->actingAs($this->user($role))
                ->get(route('service.invoice.show', $inv->id))
                ->assertOk();

            $response->assertSee('Dibatalkan');
            $response->assertSee('klien mundur');
            $response->assertDontSee('Perbarui Status');
            $response->assertDontSee('Batalkan Invoice');
        }
    }
}
