<?php

namespace Database\Factories;

use App\Models\ServiceCatalog;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceCatalogFactory extends Factory
{
    protected $model = ServiceCatalog::class;

    public function definition(): array
    {
        return [
            'category'  => 'instalasi',
            'name'      => 'Instalasi OJS ' . $this->faker->word(),
            'price'     => 750000,
            'price_max' => null,
            'unit'      => 'paket',
            'is_active' => true,
            'position'  => 0,
        ];
    }
}
