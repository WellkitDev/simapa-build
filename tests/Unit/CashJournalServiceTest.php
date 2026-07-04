<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\CashEntry;
use App\Services\CashJournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

class CashJournalServiceTest extends TestCase
{
    use RefreshDatabase;

    private function entry(string $tanggal, string $jenis, $amount): CashEntry
    {
        return CashEntry::create(['tanggal' => $tanggal, 'jenis' => $jenis, 'amount' => $amount, 'keterangan' => 'x', 'source' => 'manual']);
    }

    /** @test */
    public function derive_kode_matches_data_format(): void
    {
        $svc = new CashJournalService();
        $this->assertSame('B1025', $svc->deriveKode(Carbon::create(2025, 10, 15)));
        $this->assertSame('B126', $svc->deriveKode(Carbon::create(2026, 1, 5)));
    }

    /** @test */
    public function compute_running_saldo_with_opening_and_summary(): void
    {
        $this->entry('2026-05-20', 'pemasukan', 1000000); // prior month
        $this->entry('2026-06-05', 'pemasukan', 500000);
        $this->entry('2026-06-10', 'pengeluaran', 200000);

        $r = (new CashJournalService())->compute(2026, 6, null);

        $this->assertSame(1000000.0, $r['opening']);
        $this->assertSame(500000.0, $r['totalIn']);
        $this->assertSame(200000.0, $r['totalOut']);
        $this->assertSame(1300000.0, $r['saldoAkhir']);
        $saldos = $r['entries']->pluck('saldo')->all();
        $this->assertSame([1500000.0, 1300000.0], $saldos); // running: 1jt+500rb, -200rb
    }

    /** @test */
    public function jenis_filter_keeps_summary_and_saldo(): void
    {
        $this->entry('2026-06-05', 'pemasukan', 500000);
        $this->entry('2026-06-10', 'pengeluaran', 200000);

        $r = (new CashJournalService())->compute(2026, 6, 'pemasukan');
        $this->assertSame(1, $r['entries']->count());        // hanya pemasukan tampil
        $this->assertSame(500000.0, $r['totalIn']);           // ringkasan tetap penuh
        $this->assertSame(200000.0, $r['totalOut']);
        $this->assertSame(500000.0, $r['entries']->first()->saldo); // saldo berjalan penuh
    }
}
