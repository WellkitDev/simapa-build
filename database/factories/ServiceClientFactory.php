<?php

namespace Database\Factories;

use App\Models\ServiceClient;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceClientFactory extends Factory
{
    protected $model = ServiceClient::class;

    public function definition(): array
    {
        return [
            'name'        => $this->faker->name(),
            'institution' => 'Universitas ' . $this->faker->city(),
            'email'       => $this->faker->unique()->safeEmail(),
            'phone'       => '0812' . $this->faker->numerify('########'),
            'address'     => $this->faker->address(),
        ];
    }
}
