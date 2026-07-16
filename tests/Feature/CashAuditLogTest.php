<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\CashCategory;
use App\Models\CashEntry;
use App\Models\CashLog;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\User;
use App\Services\CashPeriodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Jejak perubahan entri kas — ditulis dari observer agar SEMUA jalur tertangkap. */
class CashAuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin', 'accounting'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function entri(string $tanggal = '2026-07-10', string $source = 'manual'): CashEntry
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

    /** @test */
    public function create_update_delete_are_logged(): void
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');
        $this->actingAs($u);

        $entry = $this->entri();
        $entry->update(['amount' => 700_000]);
        $entry->delete();

        $this->assertSame(1, CashLog::where('action', 'created')->count());
        $this->assertSame(1, CashLog::where('action', 'updated')->count());
        $this->assertSame(1, CashLog::where('action', 'deleted')->count());
        $this->assertSame($u->id, CashLog::where('action', 'created')->first()->user_id);
    }

    /** @test */
    public function update_log_records_before_and_after(): void
    {
        $entry = $this->entri();
        $entry->update(['amount' => 700_000]);

        $log = CashLog::where('action', 'updated')->firstOrFail();

        $this->assertSame(500_000.0, (float) $log->changes['before']['amount']);
        $this->assertSame(700_000.0, (float) $log->changes['after']['amount']);
    }

    /** @test */
    public function deleted_log_survives_the_entry(): void
    {
        // Membuktikan keputusan "tanpa FK": bukti tak boleh ikut terhapus.
        $entry = $this->entri();
        $id = $entry->id;
        $entry->delete();

        $log = CashLog::where('action', 'deleted')->where('cash_entry_id', $id)->firstOrFail();

        $this->assertNull(CashEntry::find($id));
        $this->assertSame(500_000.0, (float) $log->changes['amount'], 'Log harus tetap memuat nominalnya.');
    }

    /** @test */
    public function lock_and_unlock_are_logged(): void
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        app(CashPeriodService::class)->lock(2026, 6, $u);
        app(CashPeriodService::class)->unlock(2026, 6, $u);

        $this->assertSame(1, CashLog::where('action', 'locked')->count());
        $this->assertSame(1, CashLog::where('action', 'unlocked')->count());
        $this->assertSame($u->id, CashLog::where('action', 'locked')->first()->user_id);
    }

    /** @test */
    public function system_actor_is_null(): void
    {
        // Tanpa actingAs: tak ada user login (mis. sinkron dari console/migrasi).
        $this->entri();

        $log = CashLog::where('action', 'created')->firstOrFail();

        $this->assertNull($log->user_id);
        $this->assertSame('sistem', $log->actorName());
    }

    /** @test */
    public function payment_sync_into_locked_period_is_noted(): void
    {
        // Kompromi yang disengaja HARUS terlihat di log.
        app(CashPeriodService::class)->lock(2026, 6, null);

        $owner = User::factory()->create();
        $owner->assignRole('marketing');
        $order = Order::create(['code_order' => 'ORD-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
        OrderDetail::create([
            'order_id' => $order->id, 'type' => 'bk_mandiri', 'title' => 'Judul', 'slug' => 's-' . uniqid(),
            'chapters' => 1, 'cost_amount' => 5_000_000, 'naskah_type' => 'mandiri', 'publication_type' => 'regular',
        ]);
        Payment::create(['order_id' => $order->id, 'payment_type' => 'dp', 'amount' => 5_000_000, 'status' => 'paid', 'paid_at' => '2026-06-15']);

        $log = CashLog::where('action', 'created')->latest('id')->firstOrFail();

        $this->assertSame('periode terkunci', $log->note);
    }

    /** @test */
    public function audit_page_renders(): void
    {
        $this->entri();

        $sa = User::factory()->create();
        $sa->assignRole('superadmin');
        $this->actingAs($sa)->get(route('accounting.audit', ['year' => now()->year]))
            ->assertOk()->assertSee('Riwayat Perubahan');

        $acc = User::factory()->create();
        $acc->assignRole('accounting');
        $this->actingAs($acc)->get(route('accounting.audit'))->assertOk();

        $mk = User::factory()->create();
        $mk->assignRole('marketing');
        $this->actingAs($mk)->get(route('accounting.audit'))->assertForbidden();
    }
}
