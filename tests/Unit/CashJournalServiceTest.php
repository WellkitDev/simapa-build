<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\CashAccount;
use App\Models\CashEntry;
use App\Models\CashSetting;
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
    public function saldo_awal_seeds_the_running_balance(): void
    {
        CashAccount::incomeDefault()->update(['opening_balance' => 50000000]);
        $this->entry('2026-06-05', 'pemasukan', 500000);

        $r = (new CashJournalService())->compute(2026, 6, null);

        $this->assertSame(50000000.0, $r['saldoAwal']);
        $this->assertSame(50000000.0, $r['opening']);          // saldo awal jadi basis
        $this->assertSame(50500000.0, $r['entries']->first()->saldo); // berlanjut dari saldo awal
        $this->assertSame(50500000.0, $r['saldoAkhir']);
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

    /** @test */
    public function compute_scopes_to_account_and_excludes_transfer_from_totals(): void
    {
        $A = CashAccount::incomeDefault();
        $B = CashAccount::where('purpose', 'operational')->first();

        CashEntry::create(['tanggal' => '2026-06-05', 'jenis' => 'pemasukan', 'amount' => 500000, 'keterangan' => 'in', 'source' => 'manual', 'account_id' => $A->id]);
        // transfer A->B 200rb (dua kaki)
        CashEntry::create(['tanggal' => '2026-06-06', 'jenis' => 'pengeluaran', 'amount' => 200000, 'keterangan' => 't', 'source' => 'manual', 'account_id' => $A->id, 'is_transfer' => true, 'transfer_group' => 'g']);
        CashEntry::create(['tanggal' => '2026-06-06', 'jenis' => 'pemasukan', 'amount' => 200000, 'keterangan' => 't', 'source' => 'manual', 'account_id' => $B->id, 'is_transfer' => true, 'transfer_group' => 'g']);

        // Semua akun: totalIn/out kecualikan transfer; saldoAkhir dari running (transfer net-nol)
        $all = (new CashJournalService())->compute(2026, 6, null, null);
        $this->assertSame(500000.0, $all['totalIn']);   // transfer-in 200rb tidak dihitung
        $this->assertSame(0.0, $all['totalOut']);        // transfer-out 200rb tidak dihitung
        $this->assertSame(500000.0, $all['saldoAkhir']); // 0 + 500 -200 +200 = 500rb

        // Difilter akun A: saldo A turun oleh transfer keluar
        $a = (new CashJournalService())->compute(2026, 6, null, $A->id);
        $this->assertSame(300000.0, $a['saldoAkhir']);   // 500rb - 200rb transfer keluar
        $this->assertSame(500000.0, $a['totalIn']);
        $this->assertSame(0.0, $a['totalOut']);

        // Difilter akun B: hanya transfer masuk
        $b = (new CashJournalService())->compute(2026, 6, null, $B->id);
        $this->assertSame(200000.0, $b['saldoAkhir']);
        $this->assertSame(0.0, $b['totalIn']);           // transfer dikecualikan dari totalIn
    }
}
