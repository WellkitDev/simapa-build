<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OrderDetail>
 */
class OrderDetailFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->sentence(4);

        return [
            'order_id'         => Order::factory(),
            'type'             => 'bk_mandiri',
            'title'            => $title,
            'slug'             => Str::slug($title),
            'naskah_type'      => 'mandiri',
            'publication_type' => 'regular',
            'cost_amount'      => 1000000,
        ];
    }
}
