<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\CashMargin;
use App\Models\CashFixedExpense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class AccountingAssumptionTest extends TestCase
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
    public function accounting_opens_assumption(): void
    {
        $this->actingAs($this->user('accounting'))->get(route('accounting.assumption'))
            ->assertOk()->assertSee('Buku (semua jenis)')->assertSee('Hosting Avidpedia');
    }

    /** @test */
    public function crud_margin(): void
    {
        $sa = $this->user('superadmin');
        $this->actingAs($sa)->post(route('accounting.assumption.margin.store'), ['code' => 'M_NEW', 'label' => 'Produk Baru', 'margin_pct' => 40])->assertRedirect();
        $m = CashMargin::where('code', 'M_NEW')->first();
        $this->assertSame('40.00', $m->margin_pct);

        $this->actingAs($sa)->put(route('accounting.assumption.margin.update', $m->id), ['code' => 'M_NEW', 'label' => 'Produk Ubah', 'margin_pct' => 45])->assertRedirect();
        $this->assertSame('Produk Ubah', $m->fresh()->label);

        $this->actingAs($sa)->delete(route('accounting.assumption.margin.destroy', $m->id))->assertRedirect();
        $this->assertNull(CashMargin::find($m->id));
    }

    /** @test */
    public function crud_expense(): void
    {
        $sa = $this->user('superadmin');
        $this->actingAs($sa)->post(route('accounting.assumption.expense.store'), ['name' => 'Listrik', 'period' => 'bulanan', 'amount' => 1000000])->assertRedirect();
        $e = CashFixedExpense::where('name', 'Listrik')->first();
        $this->assertSame(1000000.0, $e->monthlyAmount());

        $this->actingAs($sa)->put(route('accounting.assumption.expense.update', $e->id), ['name' => 'Listrik', 'period' => 'tahunan', 'amount' => 1200000])->assertRedirect();
        $this->assertSame(100000.0, $e->fresh()->monthlyAmount());

        $this->actingAs($sa)->delete(route('accounting.assumption.expense.destroy', $e->id))->assertRedirect();
        $this->assertNull(CashFixedExpense::find($e->id));
    }

    /** @test */
    public function marketing_cannot_access(): void
    {
        $this->actingAs($this->user('marketing'))->get(route('accounting.assumption'))->assertForbidden();
    }
}
