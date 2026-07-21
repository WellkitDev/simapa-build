<?php

namespace Tests\Feature;

use App\Models\CashEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class IdempotencyIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function idempotent_directive_renders_hidden_token_field(): void
    {
        $html = Blade::render('<form>@idempotent</form>');

        $this->assertStringContainsString('name="_idempotency_key"', $html);
        $this->assertStringContainsString('type="hidden"', $html);
        // Ada value UUID yang terisi (bukan kosong).
        $this->assertMatchesRegularExpression(
            '/name="_idempotency_key"\s+value="[0-9a-f-]{36}"/',
            $html
        );
    }

    /** @test */
    public function master_layout_loads_guard_and_handles_info_flash(): void
    {
        // Dashboard superadmin ("company") memanggil SalesDashboardService->perMarketingComparison(),
        // yang menjalankan query scope User::role('marketing') — scope ini (beda dari hasRole())
        // melempar RoleDoesNotExist bila role belum ada di DB. Buat semua role yang dipakai
        // di jalur dashboard (pola sama dgn DashboardRoleRoutingTest). firstOrCreate juga memicu
        // listener Role::created di Tests\TestCase yang menghibahkan permission via AccessMatrixSeeder.
        foreach (['superadmin', 'manager', 'accounting', 'admin', 'marketing', 'production'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }

        $user = \App\Models\User::factory()->create();
        $user->assignRole('superadmin');

        $res = $this->actingAs($user)
            ->withSession(['info' => 'Permintaan sudah diproses, data tidak digandakan.'])
            ->get(route('dashboard'));

        $res->assertOk();
        $res->assertSee('js/idempotency.js', false);
        $res->assertSee('window.swalInfo', false);
    }

    private function superadmin(): \App\Models\User
    {
        // Role marketing dsb. dibutuhkan jalur dashboard/permission; buat semua role
        // yang lazim dipakai (pola sama dgn test lain). firstOrCreate memicu listener
        // Role::created di Tests\TestCase -> AccessMatrixSeeder (hibah permission).
        foreach (['superadmin', 'manager', 'accounting', 'admin', 'marketing', 'production'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        $u = \App\Models\User::factory()->create();
        $u->assignRole('superadmin');
        return $u;
    }

    private function entryPayload(string $token = null): array
    {
        $p = [
            'tanggal'    => now()->toDateString(),
            'jenis'      => 'pengeluaran',
            'amount'     => 50000,
            'keterangan' => 'Uji idempotency',
        ];
        if ($token !== null) $p['_idempotency_key'] = $token;
        return $p;
    }

    /** @test */
    public function entry_store_dedupes_double_submit_via_real_route(): void
    {
        $sa = $this->superadmin();
        $token = 'entry-tok-1';

        $this->actingAs($sa)->from(route('accounting.journal'))
            ->post(route('accounting.entry.store'), $this->entryPayload($token));
        $this->actingAs($sa)->from(route('accounting.journal'))
            ->post(route('accounting.entry.store'), $this->entryPayload($token))
            ->assertSessionHas('info');

        $this->assertSame(1, \App\Models\CashEntry::where('keterangan', 'Uji idempotency')->count());
    }

    /** @test */
    public function entry_store_without_token_is_not_deduped(): void
    {
        $sa = $this->superadmin();

        $this->actingAs($sa)->from(route('accounting.journal'))
            ->post(route('accounting.entry.store'), $this->entryPayload());
        $this->actingAs($sa)->from(route('accounting.journal'))
            ->post(route('accounting.entry.store'), $this->entryPayload());

        $this->assertSame(2, \App\Models\CashEntry::where('keterangan', 'Uji idempotency')->count());
    }
}
