<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\CashAccount;
use App\Models\CashEntry;
use App\Services\CashJournalService;
use App\Services\CashRecapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class AccountingBankAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    /** @test */
    public function account_crud_with_single_income_default_and_delete_guards(): void
    {
        $sa = $this->user('superadmin');

        $this->actingAs($sa)->post(route('accounting.account.store'), [
            'name' => 'BCA', 'purpose' => 'operational', 'opening_balance' => 500000,
        ])->assertRedirect();
        $bca = CashAccount::where('name', 'BCA')->first();
        $this->assertNotNull($bca);
        $this->assertSame('500000.00', $bca->opening_balance);
        $this->assertFalse((bool) $bca->is_income_default);

        // jadikan income-default + ubah opening
        $this->actingAs($sa)->put(route('accounting.account.update', $bca->id), [
            'name' => 'BCA', 'purpose' => 'operational', 'opening_balance' => 750000, 'is_income_default' => 1, 'active' => 1,
        ])->assertRedirect();
        $bca->refresh();
        $this->assertTrue((bool) $bca->is_income_default);
        $this->assertSame('750000.00', $bca->opening_balance);
        $this->assertFalse((bool) CashAccount::where('name', 'Kas Pemasukan')->first()->is_income_default); // default lama ter-unset

        // hapus akun income-default → ditolak
        $this->actingAs($sa)->delete(route('accounting.account.destroy', $bca->id))->assertRedirect();
        $this->assertNotNull(CashAccount::find($bca->id));

        // hapus akun non-default tanpa transaksi → berhasil
        $harta = CashAccount::where('name', 'Harta')->first();
        $this->actingAs($sa)->delete(route('accounting.account.destroy', $harta->id))->assertRedirect();
        $this->assertNull(CashAccount::find($harta->id));
    }

    /** @test */
    public function marketing_cannot_manage_accounts(): void
    {
        $this->actingAs($this->user('marketing'))->post(route('accounting.account.store'), [
            'name' => 'X', 'opening_balance' => 0,
        ])->assertForbidden();
    }

    /** @test */
    public function transfer_creates_two_legs_and_moves_balance(): void
    {
        $A = CashAccount::incomeDefault();
        $A->update(['opening_balance' => 1000000]);
        $B = CashAccount::where('purpose', 'operational')->first();

        $this->actingAs($this->user('accounting'))->post(route('accounting.transfer.store'), [
            'from_account_id' => $A->id, 'to_account_id' => $B->id, 'amount' => 300000, 'tanggal' => '2026-06-10',
        ])->assertRedirect();

        $legs = CashEntry::where('is_transfer', true)->get();
        $this->assertSame(2, $legs->count());
        $this->assertSame(1, $legs->pluck('transfer_group')->unique()->count());
        $this->assertSame('pengeluaran', $legs->firstWhere('account_id', $A->id)->jenis);
        $this->assertSame('pemasukan', $legs->firstWhere('account_id', $B->id)->jenis);

        $by = collect((new CashJournalService())->accountBalances()['rows'])->keyBy(fn ($r) => $r['account']->id);
        $this->assertSame(700000.0, $by[$A->id]['saldo']);
        $this->assertSame(300000.0, $by[$B->id]['saldo']);
    }

    /** @test */
    public function transfer_excluded_from_profit(): void
    {
        $A = CashAccount::incomeDefault();
        $B = CashAccount::where('purpose', 'operational')->first();
        $this->actingAs($this->user('accounting'))->post(route('accounting.transfer.store'), [
            'from_account_id' => $A->id, 'to_account_id' => $B->id, 'amount' => 300000, 'tanggal' => '2026-06-10',
        ])->assertRedirect();

        $jun = (new CashRecapService())->monthlyRecap(2026)[5];
        $this->assertSame(0.0, $jun['totalIn']);
        $this->assertSame(0.0, $jun['totalOut']);
    }

    /** @test */
    public function deleting_a_transfer_leg_removes_both(): void
    {
        $A = CashAccount::incomeDefault();
        $B = CashAccount::where('purpose', 'operational')->first();
        $this->actingAs($this->user('accounting'))->post(route('accounting.transfer.store'), [
            'from_account_id' => $A->id, 'to_account_id' => $B->id, 'amount' => 300000, 'tanggal' => '2026-06-10',
        ])->assertRedirect();

        $leg = CashEntry::where('is_transfer', true)->first();
        $this->actingAs($this->user('accounting'))->delete(route('accounting.entry.destroy', $leg->id))->assertRedirect();
        $this->assertSame(0, CashEntry::where('is_transfer', true)->count());
    }

    /** @test */
    public function marketing_cannot_transfer(): void
    {
        $a = CashAccount::incomeDefault();
        $this->actingAs($this->user('marketing'))->post(route('accounting.transfer.store'), [
            'from_account_id' => $a->id, 'to_account_id' => $a->id, 'amount' => 1, 'tanggal' => '2026-06-01',
        ])->assertForbidden();
    }

    /** @test */
    public function journal_shows_account_cards_and_transfer_ui(): void
    {
        $this->actingAs($this->user('accounting'))->get(route('accounting.journal'))
            ->assertOk()
            ->assertSee('Kas Pemasukan')   // kartu saldo akun
            ->assertSee('Transfer Dana')   // tombol transfer
            ->assertSee('Kelola Akun');    // pengelolaan akun
    }

    /** @test */
    public function journal_can_filter_by_account(): void
    {
        $b = CashAccount::where('purpose', 'operational')->first();
        $this->actingAs($this->user('accounting'))
            ->get(route('accounting.journal', ['account' => $b->id]))
            ->assertOk();
    }
}
