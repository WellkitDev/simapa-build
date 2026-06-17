<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TagihanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tagihan_no'  => 'TAG-' . date('Ym') . '-' . $this->faker->unique()->numerify('####'),
            'created_by'  => User::factory(),
            'client_name' => $this->faker->company(),
            'client_email' => $this->faker->safeEmail(),
            'client_phone' => $this->faker->numerify('08##########'),
            'title'       => $this->faker->sentence(3),
            'type'        => 'buku',
            'amount'      => $this->faker->numberBetween(500000, 5000000),
            'status'      => 'diajukan',
        ];
    }
}
