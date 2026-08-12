<?php

namespace Tests\Feature;

use App\Models\ServiceInvoice;
use App\Support\ServiceInvoiceNumber;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
    public function malformed_last_number_fails_loudly_instead_of_colliding(): void
    {
        $aug = \Carbon\Carbon::parse('2026-08-11');
        ServiceInvoice::factory()->create([
            'invoice_no' => 'INV-JS-202608-REV', 'issued_at' => $aug->toDateString(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/tidak berformat angka/');

        ServiceInvoiceNumber::next($aug);
    }

    /** @test */
    public function running_out_of_numbers_in_a_month_fails_legibly(): void
    {
        $aug = \Carbon\Carbon::parse('2026-08-11');
        ServiceInvoice::factory()->create([
            'invoice_no' => 'INV-JS-202608-9999', 'issued_at' => $aug->toDateString(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/habis/');

        ServiceInvoiceNumber::next($aug);
    }

    /** @test */
    public function retrying_recovers_from_a_real_duplicate_number(): void
    {
        ServiceInvoice::factory()->create(['invoice_no' => 'INV-JS-202608-0001']);

        $attempts = 0;

        $result = ServiceInvoiceNumber::retrying(function () use (&$attempts) {
            $attempts++;
            // Percobaan pertama sengaja memakai nomor yang sudah terpakai, jadi
            // duplikatnya datang dari unique index sungguhan — bukan pengecualian
            // yang dirakit tangan.
            $no = $attempts === 1 ? 'INV-JS-202608-0001' : 'INV-JS-202608-0002';

            return ServiceInvoice::factory()->create(['invoice_no' => $no]);
        });

        $this->assertSame(2, $attempts, 'Duplikat harus diulang sekali lalu berhasil.');
        $this->assertSame('INV-JS-202608-0002', $result->invoice_no);
    }

    /** @test */
    public function retrying_gives_up_after_the_configured_attempts(): void
    {
        ServiceInvoice::factory()->create(['invoice_no' => 'INV-JS-202608-0001']);

        $attempts = 0;

        try {
            ServiceInvoiceNumber::retrying(function () use (&$attempts) {
                $attempts++;

                return ServiceInvoice::factory()->create(['invoice_no' => 'INV-JS-202608-0001']);
            });
            $this->fail('Tabrakan yang tak kunjung reda harus akhirnya dilempar.');
        } catch (QueryException $e) {
            $this->assertSame('23000', (string) $e->errorInfo[0]);
        }

        $this->assertSame(3, $attempts);
    }

    /** @test */
    public function retrying_does_not_repeat_unrelated_database_errors(): void
    {
        $attempts = 0;

        try {
            ServiceInvoiceNumber::retrying(function () use (&$attempts) {
                $attempts++;

                DB::table('tabel_yang_tidak_ada')->insert(['x' => 1]);
            });
            $this->fail('Galat non-balapan harus dilempar apa adanya.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('tabel_yang_tidak_ada', $e->getMessage());
        }

        $this->assertSame(1, $attempts, 'Galat non-balapan tidak boleh diulang.');
    }

    /** @test */
    public function deadlock_is_treated_as_a_race_and_retried(): void
    {
        // Deadlock TIDAK bisa dipicu dari satu koneksi tes, jadi pengecualiannya
        // dirakit dengan bentuk errorInfo yang sama persis seperti yang dilempar
        // MySQL. Ini satu-satunya tes di berkas ini yang memakai galat rakitan —
        // dan memang harus, karena jalur inilah yang membuat invoice PERTAMA tiap
        // bulan tidak berakhir 500.
        $attempts = 0;

        $result = ServiceInvoiceNumber::retrying(function () use (&$attempts) {
            $attempts++;

            if ($attempts === 1) {
                $pdoException = new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock');
                $pdoException->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];

                throw new QueryException(
                    'mysql',
                    'insert into `tb_service_invoices` (`invoice_no`) values (?)',
                    ['INV-JS-202608-0001'],
                    $pdoException
                );
            }

            return 'berhasil';
        });

        $this->assertSame(2, $attempts, 'Deadlock adalah balapan murni dan harus diulang.');
        $this->assertSame('berhasil', $result);
    }
}
