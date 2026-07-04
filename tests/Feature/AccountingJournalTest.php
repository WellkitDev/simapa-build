<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\CashCategory;
use App\Models\CashEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class AccountingJournalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
        // role 'accounting' berasal dari migrasi 2026_07_04_000001
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    /** @test */
    public function accounting_and_superadmin_can_store_entry(): void
    {
        $cat = CashCategory::where('jenis', 'pemasukan')->first();
        $this->actingAs($this->user('accounting'))->post(route('accounting.entry.store'), [
            'tanggal' => '2026-06-05', 'jenis' => 'pemasukan', 'cash_category_id' => $cat->id,
            'amount' => 500000, 'produk' => 'artikel', 'keterangan' => 'Order test',
        ])->assertRedirect();

        $e = CashEntry::where('keterangan', 'Order test')->first();
        $this->assertNotNull($e);
        $this->assertSame('B626', $e->kode);
        $this->assertSame('manual', $e->source);

        $expCat = CashCategory::where('jenis', 'pengeluaran')->first();
        $this->actingAs($this->user('superadmin'))->post(route('accounting.entry.store'), [
            'tanggal' => '2026-06-10', 'jenis' => 'pengeluaran', 'cash_category_id' => $expCat->id,
            'amount' => 200000, 'keterangan' => 'Bayar APC',
        ])->assertRedirect();
        $this->assertSame(1, CashEntry::where('jenis', 'pengeluaran')->count());
    }

    /** @test */
    public function marketing_cannot_access(): void
    {
        $this->actingAs($this->user('marketing'))->get(route('accounting.journal'))->assertForbidden();
        $this->actingAs($this->user('marketing'))->post(route('accounting.entry.store'), [
            'tanggal' => '2026-06-05', 'jenis' => 'pemasukan', 'amount' => 1, 'keterangan' => 'x',
        ])->assertForbidden();
    }

    /** @test */
    public function index_shows_entries_and_summary(): void
    {
        CashEntry::create(['tanggal' => '2026-06-05', 'jenis' => 'pemasukan', 'amount' => 500000, 'keterangan' => 'Masuk Juni', 'source' => 'manual']);
        $this->actingAs($this->user('accounting'))->get(route('accounting.journal', ['year' => 2026, 'month' => 6]))
            ->assertOk()->assertSee('Masuk Juni');
    }

    /** @test */
    public function category_crud(): void
    {
        $sa = $this->user('superadmin');
        $this->actingAs($sa)->post(route('accounting.category.store'), ['name' => 'Kategori Baru', 'jenis' => 'pengeluaran'])->assertRedirect();
        $c = CashCategory::where('name', 'Kategori Baru')->first();
        $this->assertNotNull($c);
        $this->actingAs($sa)->put(route('accounting.category.update', $c->id), ['name' => 'Kategori Baru', 'jenis' => 'pengeluaran'])->assertRedirect();
        $this->assertFalse($c->fresh()->active); // tanpa checkbox active → nonaktif
    }

    /** @test */
    public function update_and_delete_entry(): void
    {
        $e = CashEntry::create(['tanggal' => '2026-06-05', 'jenis' => 'pemasukan', 'amount' => 500000, 'keterangan' => 'A', 'source' => 'manual']);
        $this->actingAs($this->user('accounting'))->put(route('accounting.entry.update', $e->id), [
            'tanggal' => '2026-06-06', 'jenis' => 'pemasukan', 'amount' => 600000, 'keterangan' => 'A2',
        ])->assertRedirect();
        $this->assertSame('A2', $e->fresh()->keterangan);

        $this->actingAs($this->user('accounting'))->delete(route('accounting.entry.destroy', $e->id))->assertRedirect();
        $this->assertNull(CashEntry::find($e->id));
    }
}
