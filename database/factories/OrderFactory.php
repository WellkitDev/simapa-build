<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code_order' => 'ORD-' . strtoupper(Str::random(8)),
            'user_id'    => User::factory(),
            'status'     => 'pending',
            'note'       => null,
            'ordered_at' => now(),
        ];
    }
}
