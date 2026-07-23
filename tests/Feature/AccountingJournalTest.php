<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\CashCategory;
use App\Models\CashEntry;
use App\Models\CashAccount;
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
        $this->assertSame(CashAccount::incomeDefault()->id, $e->account_id); // entri manual → akun income-default

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
        ])->assertRedirect()->assertSessionHas('error');
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

    /** @test */
    public function accounting_sets_account_opening(): void
    {
        $acc = CashAccount::incomeDefault();
        $this->actingAs($this->user('accounting'))->put(route('accounting.account.update', $acc->id), [
            'name' => $acc->name, 'purpose' => $acc->purpose, 'opening_balance' => 50000000, 'is_income_default' => 1, 'active' => 1,
        ])->assertRedirect();

        $this->assertSame('50000000.00', $acc->fresh()->opening_balance);
    }

    /** @test */
    public function store_creates_new_category_inline_from_name(): void
    {
        $this->actingAs($this->user('accounting'))->post(route('accounting.entry.store'), [
            'tanggal' => '2026-06-05', 'jenis' => 'pemasukan',
            'cash_category_id' => 'Konsultasi', // nama baru, bukan id
            'amount' => 400000, 'produk' => 'jasa editing', 'keterangan' => 'Fee konsultasi',
        ])->assertRedirect();

        $cat = CashCategory::where('name', 'Konsultasi')->where('jenis', 'pemasukan')->first();
        $this->assertNotNull($cat, 'Kategori baru dibuat inline.');

        $e = CashEntry::where('keterangan', 'Fee konsultasi')->first();
        $this->assertSame($cat->id, $e->cash_category_id);
        $this->assertSame('jasa editing', $e->produk, 'Produk kustom tersimpan apa adanya.');
    }

    /** @test */
    public function store_reuses_existing_category_when_id_given(): void
    {
        $cat = CashCategory::where('jenis', 'pemasukan')->first();
        $before = CashCategory::count();

        $this->actingAs($this->user('accounting'))->post(route('accounting.entry.store'), [
            'tanggal' => '2026-06-05', 'jenis' => 'pemasukan',
            'cash_category_id' => (string) $cat->id, 'amount' => 100000, 'keterangan' => 'Pakai kategori ada',
        ])->assertRedirect();

        $this->assertSame($before, CashCategory::count(), 'Tak membuat kategori baru untuk id yang ada.');
        $this->assertSame($cat->id, CashEntry::where('keterangan', 'Pakai kategori ada')->first()->cash_category_id);
    }

    /** @test */
    public function store_reuses_inline_category_on_repeat_name(): void
    {
        $payload = [
            'tanggal' => '2026-06-05', 'jenis' => 'pemasukan',
            'cash_category_id' => 'Sponsorship', 'amount' => 100000, 'keterangan' => 'x',
        ];
        $this->actingAs($this->user('accounting'))->post(route('accounting.entry.store'), $payload)->assertRedirect();
        $countAfterFirst = CashCategory::where('name', 'Sponsorship')->count();
        $this->actingAs($this->user('accounting'))->post(route('accounting.entry.store'), $payload + ['keterangan' => 'y'])->assertRedirect();

        $this->assertSame(1, $countAfterFirst, 'Dibuat sekali.');
        $this->assertSame(1, CashCategory::where('name', 'Sponsorship')->count(), 'Nama sama tak menggandakan kategori.');
    }

    /** @test */
    public function edit_action_renders_only_for_manual_non_transfer_entries(): void
    {
        $manual = CashEntry::create(['tanggal' => '2026-06-05', 'jenis' => 'pemasukan', 'amount' => 100000, 'keterangan' => 'Manual', 'source' => 'manual', 'is_transfer' => false]);
        $auto = CashEntry::create(['tanggal' => '2026-06-06', 'jenis' => 'pemasukan', 'amount' => 200000, 'keterangan' => 'Auto', 'source' => 'payment', 'is_transfer' => false]);
        $transfer = CashEntry::create(['tanggal' => '2026-06-07', 'jenis' => 'pengeluaran', 'amount' => 300000, 'keterangan' => 'Transfer', 'source' => 'manual', 'is_transfer' => true]);

        $html = $this->actingAs($this->user('accounting'))
            ->get(route('accounting.journal', ['year' => 2026, 'month' => 6]))
            ->assertOk()->getContent();

        // NB: route update & destroy menghasilkan URL sama (beda verb), jadi
        // dibandingkan lewat atribut `data-action` yang hanya dimiliki tombol edit.
        $this->assertStringContainsString('data-action="' . route('accounting.entry.update', $manual->id) . '"', $html, 'Entri manual wajib punya aksi edit.');
        $this->assertStringNotContainsString('data-action="' . route('accounting.entry.update', $auto->id) . '"', $html, 'Entri auto (payment) tetap read-only.');
        $this->assertStringNotContainsString('data-action="' . route('accounting.entry.update', $transfer->id) . '"', $html, 'Transfer tak bisa diedit (hapus+buat ulang).');
    }

    /** @test */
    public function edit_handler_is_delegated_so_it_survives_datatables_paging(): void
    {
        // Regresi: ikatan langsung $('[data-edit-entry]').on(...) tidak terpasang
        // pada baris di luar halaman aktif DataTables (pageLength 25) -> tombol mati.
        $html = $this->actingAs($this->user('accounting'))->get(route('accounting.journal'))->assertOk()->getContent();

        $this->assertStringContainsString("\$(document).on('click', '[data-edit-entry]'", $html, 'Handler edit harus didelegasikan ke document.');
    }
}
