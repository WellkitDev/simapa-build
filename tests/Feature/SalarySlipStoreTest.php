<?php

namespace Tests\Feature;

use App\Models\SalarySlip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalarySlipStoreTest extends TestCase
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
    public function accounting_creates_slip_with_lines_and_totals(): void
    {
        $emp = User::factory()->create(['name' => 'Budi']);
        $emp->profile()->create(['job_name' => 'Editor']);

        $this->actingAs($this->user('accounting'))->post(route('salary.slip.store'), [
            'user_id' => $emp->id, 'period_year' => 2026, 'period_month' => 7,
            'earnings' => [
                ['label' => 'Gaji Pokok', 'amount' => 5000000],
                ['label' => 'Tunjangan',  'amount' => 1000000],
            ],
            'deductions' => [
                ['label' => 'BPJS', 'amount' => 300000],
            ],
        ])->assertRedirect(route('salary.slip.index'));

        $slip = SalarySlip::first();
        $this->assertNotNull($slip);
        $this->assertSame('Budi', $slip->employee_name);
        $this->assertSame('Editor', $slip->employee_position);
        $this->assertEquals(6000000, $slip->total_earnings);
        $this->assertEquals(300000,  $slip->total_deductions);
        $this->assertEquals(5700000, $slip->net_pay);
        $this->assertSame('SLP-202607-0001', $slip->slip_no);
        $this->assertCount(3, $slip->lines);
    }

    /** @test */
    public function duplicate_period_for_same_employee_is_rejected(): void
    {
        $emp = User::factory()->create();
        SalarySlip::factory()->create(['user_id' => $emp->id, 'period_year' => 2026, 'period_month' => 7]);

        $this->actingAs($this->user('accounting'))->post(route('salary.slip.store'), [
            'user_id' => $emp->id, 'period_year' => 2026, 'period_month' => 7,
            'earnings' => [['label' => 'Gaji Pokok', 'amount' => 1000000]],
        ])->assertSessionHasErrors('user_id');

        $this->assertSame(1, SalarySlip::count());
    }

    /** @test */
    public function create_form_renders_for_accounting(): void
    {
        $this->actingAs($this->user('accounting'))->get(route('salary.slip.create'))
            ->assertOk()->assertSee('Rincian Penghasilan')->assertSee('Rincian Potongan');
    }
}
