<?php

namespace Tests\Feature;

use App\Models\ServiceInvoice;
use App\Support\ServiceInvoiceNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceInvoiceNumberTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function numbers_run_in_sequence_within_a_month(): void
    {
        $aug = \Carbon\Carbon::parse('2026-08-11');

        $first = ServiceInvoiceNumber::next($aug);
        $this->assertSame('INV-JS-202608-0001', $first);

        ServiceInvoice::factory()->create(['invoice_no' => $first, 'issued_at' => $aug->toDateString()]);
        $this->assertSame('INV-JS-202608-0002', ServiceInvoiceNumber::next($aug));
    }

    /** @test */
    public function sequence_restarts_each_month(): void
    {
        $aug = \Carbon\Carbon::parse('2026-08-11');
        $sep = \Carbon\Carbon::parse('2026-09-01');

        ServiceInvoice::factory()->create(['invoice_no' => 'INV-JS-202608-0007', 'issued_at' => $aug->toDateString()]);

        $this->assertSame('INV-JS-202608-0008', ServiceInvoiceNumber::next($aug));
        $this->assertSame('INV-JS-202609-0001', ServiceInvoiceNumber::next($sep));
    }

    /** @test */
    public function deleted_invoice_numbers_are_never_reused(): void
    {
        $aug = \Carbon\Carbon::parse('2026-08-11');

        $inv = ServiceInvoice::factory()->create(['invoice_no' => 'INV-JS-202608-0001', 'issued_at' => $aug->toDateString()]);
        $inv->delete();   // soft delete

        $this->assertSoftDeleted('tb_service_invoices', ['id' => $inv->id]);
        $this->assertSame('INV-JS-202608-0002', ServiceInvoiceNumber::next($aug));
    }

    /** @test */
    public function retrying_does_not_repeat_non_duplicate_errors(): void
    {
        $calls = 0;

        // expectException() TIDAK dipakai di sini: baris setelah pemanggilan yang
        // melempar tidak akan pernah jalan, sehingga assertion jumlah panggilannya
        // jadi mati diam-diam. try/catch membuat keduanya benar-benar diperiksa.
        try {
            ServiceInvoiceNumber::retrying(function () use (&$calls) {
                $calls++;
                throw new \RuntimeException('bukan galat duplikat');
            });
            $this->fail('Galat non-duplikat seharusnya dilempar apa adanya.');
        } catch (\RuntimeException $e) {
            $this->assertSame('bukan galat duplikat', $e->getMessage());
        }

        $this->assertSame(1, $calls);
    }
}
