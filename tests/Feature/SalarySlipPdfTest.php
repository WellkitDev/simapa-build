<?php

namespace Tests\Feature;

use App\Models\SalarySlip;
use App\Models\User;
use App\Support\SalarySlipPdfData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalarySlipPdfTest extends TestCase
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
    public function accounting_can_download_pdf(): void
    {
        $slip = SalarySlip::factory()->create();
        $slip->lines()->create(['type' => 'earning', 'label' => 'Gaji Pokok', 'amount' => 5000000, 'position' => 0]);
        $slip->recalcTotals();

        $res = $this->actingAs($this->user('accounting'))->get(route('salary.slip.pdf', $slip->id));
        $res->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $res->headers->get('content-type'));
    }

    /** @test */
    public function pdf_data_includes_terbilang(): void
    {
        $slip = SalarySlip::factory()->create(['net_pay' => 5700000]);
        $data = SalarySlipPdfData::for($slip);
        $this->assertSame('Lima juta tujuh ratus ribu rupiah', $data['terbilang']);
        $this->assertSame('Juli 2026', $data['periodLabel']);
    }
}
