<?php

namespace Database\Factories;

use App\Models\SalarySlip;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SalarySlipFactory extends Factory
{
    protected $model = SalarySlip::class;

    public function definition(): array
    {
        return [
            'slip_no'           => 'SLP-' . fake()->unique()->numerify('######'),
            'user_id'           => User::factory(),
            'employee_name'     => fake()->name(),
            'employee_position' => 'Staf',
            'period_year'       => 2026,
            'period_month'      => 7,
            'status'            => 'draft',
            'total_earnings'    => 0,
            'total_deductions'  => 0,
            'net_pay'           => 0,
        ];
    }
}
