<?php

namespace Database\Factories;

use App\Models\ServiceInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceInvoiceFactory extends Factory
{
    protected $model = ServiceInvoice::class;

    public function definition(): array
    {
        static $seq = 0;
        $seq++;

        return [
            'invoice_no'         => 'INV-JS-' . now()->format('Ym') . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
            'service_client_id'  => null,
            'client_name'        => $this->faker->name(),
            'client_institution' => 'Universitas ' . $this->faker->city(),
            'client_email'       => $this->faker->unique()->safeEmail(),
            'client_phone'       => '0812' . $this->faker->numerify('########'),
            'issued_at'          => now()->toDateString(),
            'due_at'             => now()->addDays(14)->toDateString(),
            'work_status'        => 'belum',
            'payment_status'     => 'belum',
            'discount'           => 0,
        ];
    }
}
