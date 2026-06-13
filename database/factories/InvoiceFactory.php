<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id'   => Order::factory(),
            'payment_id' => null,
            'invoice_no' => 'INV-' . $this->faker->unique()->numerify('######'),
            'type'       => 'regular',
            'status'     => 'draft',
            'issued_at'  => now(),
            'due_at'     => now()->addDays(14),
        ];
    }
}
