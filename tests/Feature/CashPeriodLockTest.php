<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\CashCategory;
use App\Models\CashEntry;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\User;
use App\Services\CashPeriodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Kunci periode menjaga JALUR MANUSIA (controller). Sinkron payment (observer)
 * sengaja tembus — lihat spec §Keputusan — tapi wajib tercatat.
 */
class CashPeriodLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin', 'accounting'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function superadmin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    private function entri(string $tanggal, string $source = 'manual'): CashEntry
    {
        return CashEntry::create([
            'tanggal'          => $tanggal,
            'kode'             => 'K' . uniqid(),
            'keterangan'       => 'Uji',
            'jenis'            => 'pengeluaran',
            'amount'           => 500_000,
            'cash_category_id' => CashCategory::where('jenis', 'pengeluaran')->first()?->id,
            'account_id'       => CashAccount::first()?->id,
            'source'           => $source,
            'is_transfer'      => false,
        ]);
    }

    private function payloadEntri(string $tanggal): array
    {
        return [
            'tanggal'          => $tanggal,
            'keterangan'       => 'Biaya uji',
            'jenis'            => 'pengeluaran',
            'amount'           => 250_000,
            'cash_category_id' => CashCategory::where('jenis', 'pengeluaran')->first()?->id,
            'account_id'       => CashAccount::first()?->id,
        ];
    }

    private function kunci(int $year, int $month): void
    {
        app(CashPeriodService::class)->lock($year, $month, null);
    }

    /** @test */
    public function manual_entry_in_locked_period_is_refused(): void
    {
        $this->kunci(2026, 6);

        $this->actingAs($this->superadmin())
            ->post(route('accounting.entry.store'), $this->payloadEntri('2026-06-10'))
            ->assertSessionHas('error');

        $this->assertSame(0, CashEntry::count(), 'Entri tak boleh tercipta di periode terkunci.');
    }

    /** @test */
    public function update_into_locked_period_is_refused(): void
    {
        $this->kunci(2026, 6);
        $entry = $this->entri('2026-07-10');

        $this->actingAs($this->superadmin())
            ->put(route('accounting.entry.update', $entry->id), $this->payloadEntri('2026-06-10'))
            ->assertSessionHas('error');

        $this->assertSame('2026-07-10', $entry->fresh()->tanggal->format('Y-m-d'), 'Entri tak boleh diseret ke bulan terkunci.');
    }

    /** @test */
    public function update_out_of_locked_period_is_refused(): void
    {
        $this->kunci(2026, 6);
        $entry = $this->entri('2026-06-10');

        $this->actingAs($this->superadmin())
            ->put(route('accounting.entry.update', $entry->id), $this->payloadEntri('2026-07-10'))
            ->assertSessionHas('error');

        $this->assertSame('2026-06-10', $entry->fresh()->tanggal->format('Y-m-d'), 'Entri beku tak boleh dikeluarkan dari bulan terkunci.');
    }

    /** @test */
    public function destroy_in_locked_period_is_refused(): void
    {
        $this->kunci(2026, 6);
        $entry = $this->entri('2026-06-10');

        $this->actingAs($this->superadmin())
            ->delete(route('accounting.entry.destroy', $entry->id))
            ->assertSessionHas('error');

        $this->assertNotNull(CashEntry::find($entry->id));
    }

    /** @test */
    public function transfer_in_locked_period_is_refused(): void
    {
        $this->kunci(2026, 6);
        $akun = CashAccount::orderBy('id')->take(2)->pluck('id');

        $this->actingAs($this->superadmin())->post(route('accounting.transfer.store'), [
            'from_account_id' => $akun[0], 'to_account_id' => $akun[1],
            'amount' => 1_000_000, 'tanggal' => '2026-06-10',
        ])->assertSessionHas('error');

        $this->assertSame(0, CashEntry::count(), 'Transfer tak boleh membuat SATU sisi pun.');
    }

    /** @test */
    public function unlock_restores_permission(): void
    {
        $this->kunci(2026, 6);
        app(CashPeriodService::class)->unlock(2026, 6, null);

        $this->actingAs($this->superadmin())
            ->post(route('accounting.entry.store'), $this->payloadEntri('2026-06-10'))
            ->assertSessionHas('success');

        $this->assertSame(1, CashEntry::count());
    }

    /** @test */
    public function only_superadmin_can_lock(): void
    {
        $acc = User::factory()->create();
        $acc->assignRole('accounting');

        $this->actingAs($acc)->post(route('accounting.period.lock'), ['year' => 2026, 'month' => 6])
            ->assertForbidden();

        $this->actingAs($this->superadmin())->post(route('accounting.period.lock'), ['year' => 2026, 'month' => 6]);
        $this->assertTrue(app(CashPeriodService::class)->isLocked(2026, 6));
    }

    /** @test */
    public function payment_sync_passes_lock(): void
    {
        // Kompromi yang DISENGAJA: jurnal cuma cerminan tb_payments.
        $this->kunci(2026, 6);

        $owner = User::factory()->create();
        $owner->assignRole('marketing');
        $order = Order::create(['code_order' => 'ORD-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
        OrderDetail::create([
            'order_id' => $order->id, 'type' => 'bk_mandiri', 'title' => 'Judul', 'slug' => 's-' . uniqid(),
            'chapters' => 1, 'cost_amount' => 5_000_000, 'naskah_type' => 'mandiri', 'publication_type' => 'regular',
        ]);
        Payment::create(['order_id' => $order->id, 'payment_type' => 'dp', 'amount' => 5_000_000, 'status' => 'paid', 'paid_at' => '2026-06-15']);

        $this->assertSame(1, CashEntry::where('source', 'payment')->count(), 'Sinkron payment harus tetap tembus kunci.');
    }

    /** @test */
    public function auto_entry_cannot_be_deleted(): void
    {
        // Lubang yang ditemukan lewat probe: UI menyembunyikan tombol,
        // server tak menegakkan apa pun. Kini jadi penjaga permanen.
        $entry = $this->entri('2026-07-10', 'payment');

        $this->actingAs($this->superadmin())
            ->delete(route('accounting.entry.destroy', $entry->id))
            ->assertSessionHas('error');

        $this->assertNotNull(CashEntry::find($entry->id), 'Entri auto tak boleh dihapus dari jurnal.');
    }

    /** @test */
    public function auto_entry_cannot_be_updated(): void
    {
        $entry = $this->entri('2026-07-10', 'payment');

        $this->actingAs($this->superadmin())
            ->put(route('accounting.entry.update', $entry->id), $this->payloadEntri('2026-07-11'))
            ->assertSessionHas('error');

        $this->assertSame(500_000.0, (float) $entry->fresh()->amount, 'Nilai entri auto tak boleh berubah dari jurnal.');
    }
}
