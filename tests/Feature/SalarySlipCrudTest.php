<?php

namespace Tests\Feature;

use App\Models\SalarySlip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalarySlipCrudTest extends TestCase
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
    public function update_resyncs_lines_and_recomputes(): void
    {
        $emp  = User::factory()->create();
        $slip = SalarySlip::factory()->create(['user_id' => $emp->id, 'period_year' => 2026, 'period_month' => 7, 'status' => 'draft']);
        $slip->lines()->create(['type' => 'earning', 'label' => 'Lama', 'amount' => 1000000, 'position' => 0]);
        $slip->recalcTotals();

        $this->actingAs($this->user('accounting'))->put(route('salary.slip.update', $slip->id), [
            'user_id' => $emp->id, 'period_year' => 2026, 'period_month' => 7,
            'earnings'   => [['label' => 'Gaji Pokok', 'amount' => 8000000]],
            'deductions' => [['label' => 'PPh21', 'amount' => 500000]],
        ])->assertRedirect(route('salary.slip.show', $slip->id));

        $slip->refresh();
        $this->assertEquals(8000000, $slip->total_earnings);
        $this->assertEquals(500000,  $slip->total_deductions);
        $this->assertEquals(7500000, $slip->net_pay);
        $this->assertCount(2, $slip->lines);
        $this->assertFalse($slip->lines->contains('label', 'Lama'));
    }

    /** @test */
    public function terbit_slip_cannot_be_edited(): void
    {
        $slip = SalarySlip::factory()->create(['status' => 'terbit']);
        $this->actingAs($this->user('accounting'))->get(route('salary.slip.edit', $slip->id))
            ->assertRedirect(route('salary.slip.show', $slip->id));
    }

    /** @test */
    public function destroy_soft_deletes(): void
    {
        $slip = SalarySlip::factory()->create();
        $this->actingAs($this->user('accounting'))->delete(route('salary.slip.destroy', $slip->id))->assertRedirect();
        $this->assertSoftDeleted('tb_salary_slips', ['id' => $slip->id]);
    }

    /** @test */
    public function show_displays_take_home_pay(): void
    {
        $slip = SalarySlip::factory()->create();
        $slip->lines()->create(['type' => 'earning', 'label' => 'Gaji Pokok', 'amount' => 5000000, 'position' => 0]);
        $slip->recalcTotals();
        $this->actingAs($this->user('accounting'))->get(route('salary.slip.show', $slip->id))
            ->assertOk()->assertSee('TAKE HOME PAY');
    }
}
