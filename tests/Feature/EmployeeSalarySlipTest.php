<?php

namespace Tests\Feature;

use App\Models\SalarySlip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeeSalarySlipTest extends TestCase
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
    public function employee_sees_only_own_terbit_slips(): void
    {
        $me    = $this->user('marketing');
        $other = User::factory()->create();
        $mine   = SalarySlip::factory()->create(['user_id' => $me->id, 'status' => 'terbit']);
        $draft  = SalarySlip::factory()->create(['user_id' => $me->id, 'status' => 'draft']);
        $theirs = SalarySlip::factory()->create(['user_id' => $other->id, 'status' => 'terbit']);

        $res = $this->actingAs($me)->get(route('salary.slip.me'))->assertOk();
        $res->assertSee($mine->slip_no);
        $res->assertDontSee($draft->slip_no);
        $res->assertDontSee($theirs->slip_no);
    }

    /** @test */
    public function employee_cannot_download_others_slip(): void
    {
        $me     = $this->user('marketing');
        $other  = User::factory()->create();
        $theirs = SalarySlip::factory()->create(['user_id' => $other->id, 'status' => 'terbit']);

        $this->actingAs($me)->get(route('salary.slip.me.pdf', $theirs->id))->assertNotFound();
    }

    /** @test */
    public function employee_can_download_own_terbit_slip(): void
    {
        $me   = $this->user('marketing');
        $mine = SalarySlip::factory()->create(['user_id' => $me->id, 'status' => 'terbit']);
        $mine->lines()->create(['type' => 'earning', 'label' => 'Gaji', 'amount' => 1000000, 'position' => 0]);
        $mine->recalcTotals();

        $this->actingAs($me)->get(route('salary.slip.me.pdf', $mine->id))->assertOk();
    }

    /** @test */
    public function draft_own_slip_is_not_downloadable(): void
    {
        $me   = $this->user('marketing');
        $mine = SalarySlip::factory()->create(['user_id' => $me->id, 'status' => 'draft']);

        $this->actingAs($me)->get(route('salary.slip.me.pdf', $mine->id))->assertNotFound();
    }
}
