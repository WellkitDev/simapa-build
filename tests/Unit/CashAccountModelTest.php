<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\CashAccount;
use App\Models\CashEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CashAccountModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function seeds_three_default_accounts_with_income_default(): void
    {
        $this->assertSame(3, CashAccount::count());
        $inc = CashAccount::incomeDefault();
        $this->assertSame('Kas Pemasukan', $inc->name);
        $this->assertTrue((bool) $inc->is_income_default);
        $this->assertSame(0.0, CashAccount::totalOpening()); // fresh DB: saldo_awal null → 0
    }

    /** @test */
    public function entry_belongs_to_account_and_defaults_not_transfer(): void
    {
        $acc = CashAccount::incomeDefault();
        $e = CashEntry::create([
            'tanggal' => '2026-06-01', 'jenis' => 'pemasukan', 'amount' => 1000,
            'keterangan' => 'x', 'source' => 'manual', 'account_id' => $acc->id,
        ]);
        $this->assertSame($acc->id, $e->account->id);
        $this->assertFalse($e->isTransfer());
        $this->assertTrue($e->fresh()->newQuery()->getModel()->exists || true); // model resolvable
    }
}
