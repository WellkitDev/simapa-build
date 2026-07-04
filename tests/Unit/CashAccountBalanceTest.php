<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\CashAccount;
use App\Models\CashEntry;
use App\Services\CashJournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CashAccountBalanceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function account_balances_reflect_opening_entries_and_transfers(): void
    {
        $A = CashAccount::incomeDefault();
        $A->update(['opening_balance' => 1000000]);
        $B = CashAccount::where('purpose', 'operational')->first();

        CashEntry::create(['tanggal' => '2026-06-01', 'jenis' => 'pemasukan', 'amount' => 200000, 'keterangan' => 'x', 'source' => 'manual', 'account_id' => $A->id]);
        CashEntry::create(['tanggal' => '2026-06-02', 'jenis' => 'pengeluaran', 'amount' => 300000, 'keterangan' => 't', 'source' => 'manual', 'account_id' => $A->id, 'is_transfer' => true, 'transfer_group' => 'g1']);
        CashEntry::create(['tanggal' => '2026-06-02', 'jenis' => 'pemasukan', 'amount' => 300000, 'keterangan' => 't', 'source' => 'manual', 'account_id' => $B->id, 'is_transfer' => true, 'transfer_group' => 'g1']);

        $bal = (new CashJournalService())->accountBalances();
        $by = collect($bal['rows'])->keyBy(fn ($r) => $r['account']->id);

        $this->assertSame(900000.0, $by[$A->id]['saldo']);  // 1.000.000 + 200.000 - 300.000
        $this->assertSame(300000.0, $by[$B->id]['saldo']);
        $this->assertSame(1200000.0, $bal['total']);         // + Harta 0
    }
}
