<?php

namespace Tests\Feature;

use App\Models\ServiceInvoice;
use App\Models\User;
use App\Services\GoogleDriveService;
use Database\Seeders\AccessMatrixSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ServiceInvoiceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['superadmin', 'manager', 'accounting', 'admin', 'marketing', 'production'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        (new AccessMatrixSeeder())->run();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    public static function shutOutRoles(): array
    {
        return [['admin'], ['marketing'], ['production'], ['accounting']];
    }

    /**
     * @test
     * @dataProvider shutOutRoles
     */
    public function roles_outside_the_module_see_nothing(string $role): void
    {
        $inv  = ServiceInvoice::factory()->create();
        $user = $this->user($role);

        foreach ([
            route('service.invoice.index'),
            route('service.invoice.create'),
            route('service.invoice.show', $inv->id),
            route('service.invoice.edit', $inv->id),
            route('service.invoice.pdf', $inv->id),
            route('service.catalog.index'),
            route('service.client.index'),
        ] as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }
    }

    /** @test */
    public function shut_out_roles_hold_none_of_the_module_permissions(): void
    {
        // Menu sidebar dijaga @canany atas ketiga permission ini, jadi memeriksa
        // permission-nya lebih tepat (dan jauh lebih tahan banting) daripada
        // merender dashboard yang isinya berbeda-beda per role.
        foreach (['admin', 'marketing', 'production', 'accounting'] as $role) {
            $user = $this->user($role);

            $this->assertFalse($user->can('service_invoice.view'), "{$role} tidak boleh punya service_invoice.view");
            $this->assertFalse($user->can('service_catalog.view'), "{$role} tidak boleh punya service_catalog.view");
            $this->assertFalse($user->can('service_client.view'),  "{$role} tidak boleh punya service_client.view");
        }
    }

    /** @test */
    public function manager_gets_everything_except_cancel_and_delete(): void
    {
        $manager = $this->user('manager');
        $inv     = ServiceInvoice::factory()->create();

        $this->assertTrue($manager->can('service_invoice.view'));
        $this->assertTrue($manager->can('service_invoice.create'));
        $this->assertTrue($manager->can('service_invoice.edit'));
        $this->assertTrue($manager->can('service_invoice.status'));
        $this->assertTrue($manager->can('service_invoice.payment'));
        $this->assertTrue($manager->can('service_invoice.export'));
        $this->assertTrue($manager->can('service_invoice.send'));
        $this->assertTrue($manager->can('service_catalog.manage'));
        $this->assertTrue($manager->can('service_client.manage'));

        $this->assertFalse($manager->can('service_invoice.cancel'));
        $this->assertFalse($manager->can('service_invoice.delete'));

        // Non-GET yang ditolak = redirect + flash, bukan 403 (EnforcePermission::deny).
        $this->actingAs($manager)->post(route('service.invoice.cancel', $inv->id), ['cancel_reason' => 'x'])->assertRedirect();
        $this->actingAs($manager)->delete(route('service.invoice.destroy', $inv->id))->assertRedirect();

        $inv->refresh();
        $this->assertNotSame('batal', $inv->work_status);
        $this->assertNotSoftDeleted('tb_service_invoices', ['id' => $inv->id]);
    }

    /** @test */
    public function superadmin_passes_every_gate(): void
    {
        $inv = ServiceInvoice::factory()->create();

        $this->actingAs($this->user('superadmin'))->get(route('service.invoice.index'))->assertOk();
        $this->actingAs($this->user('superadmin'))
            ->post(route('service.invoice.cancel', $inv->id), ['cancel_reason' => 'klien mundur'])
            ->assertRedirect();
    }
}
