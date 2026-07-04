<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\CashEntry;
use App\Models\CashDistribution;
use App\Models\CashSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class AccountingDistributionTest extends TestCase
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
    public function accounting_opens_with_month_laba_default_profit(): void
    {
        CashEntry::create(['tanggal' => '2026-06-05', 'jenis' => 'pemasukan', 'amount' => 800000, 'keterangan' => 'x', 'source' => 'manual']);

        $this->actingAs($this->user('accounting'))->get(route('accounting.distribution', ['year' => 2026, 'month' => 6]))
            ->assertOk()->assertSee('Harta/Pemilik')->assertSee('Fee Tim');
    }

    /** @test */
    public function crud_rule_and_members(): void
    {
        $sa = $this->user('superadmin');
        $this->actingAs($sa)->post(route('accounting.distribution.rule.store'), ['name' => 'PPn Bank', 'type' => 'flat', 'value' => 20000])->assertRedirect();
        $rule = CashDistribution::where('name', 'PPn Bank')->first();
        $this->assertSame('flat', $rule->type);

        $this->actingAs($sa)->put(route('accounting.distribution.rule.update', $rule->id), ['name' => 'PPn Bank', 'type' => 'flat', 'value' => 25000])->assertRedirect();
        $this->assertSame('25000.00', $rule->fresh()->value);

        $this->actingAs($sa)->put(route('accounting.distribution.settings'), ['team_members' => 6])->assertRedirect();
        $this->assertSame(6, CashSetting::singleton()->team_members);

        $this->actingAs($sa)->delete(route('accounting.distribution.rule.destroy', $rule->id))->assertRedirect();
        $this->assertNull(CashDistribution::find($rule->id));
    }

    /** @test */
    public function marketing_cannot_access(): void
    {
        $this->actingAs($this->user('marketing'))->get(route('accounting.distribution'))->assertForbidden();
    }
}
