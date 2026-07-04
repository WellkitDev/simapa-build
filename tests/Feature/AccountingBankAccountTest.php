<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\CashAccount;
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
}
