# Marketing Target v2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Upgrade marketing targets from monthly-per-individual to **date-range targets** with active/expired + overdue status, paid-commission tracking, individual/all scope, a marketing "Target Saya" page, period+status on the dashboard, and notifications on assign + commission-paid.

**Architecture:** Alter the (empty, unmerged) `tb_marketing_targets` to a date-range schema. `MarketingTargetService` is rewritten to compute progress per target row. The marketing dashboard, admin page, a new marketing page, and `Notifier` all consume it.

**Tech Stack:** PHP 8.2 / Laravel 11, Spatie roles, Blade + Bootstrap 5 + DataTables, PHPUnit (`php artisan test`).

**Spec:** `docs/superpowers/specs/2026-06-22-marketing-target-v2-design.md`

**IMPORTANT — env & sequencing notes:**
- Tests use the SEPARATE test DB via `.env.testing` (`APP_ENV=testing`). If a DB connection error appears, MySQL/XAMPP may be down — start it: run `& "C:\xampp\mysql\bin\mysqld.exe" --defaults-file="C:\xampp\mysql\bin\my.ini"` in the BACKGROUND, wait ~5s, retry.
- This is a v2 rewrite of an UNMERGED v1. **Task 1 drops the v1 `year`/`month` columns**, which makes the v1 marketing-target tests fail until they are rewritten in Tasks 3–5. That is expected; each task verifies its OWN filter, and Task 6 restores full-suite green. Do not try to keep v1 marketing tests green.
- After the work, run `php artisan migrate` on dev/prod (this alter must be applied or pages 500).

---

## File Structure

**Create:** alter migration `database/migrations/2026_06_22_000002_update_tb_marketing_targets_to_date_range.php`; `resources/views/target/me.blade.php`; `tests/Feature/MarketingTargetDashboardTest.php`, `tests/Feature/MarketingTargetAdminTest.php`, `tests/Feature/MarketingTargetMeTest.php`.

**Modify:** `app/Models/MarketingTarget.php`; `app/Services/MarketingTargetService.php` (rewrite); `app/Services/Notifier.php` (+2 methods); `app/Http/Controllers/Pages/MarketingTargetController.php` (rewrite); `app/Services/MarketingDashboardService.php` (target key); `resources/views/marketing-target/index.blade.php` (rewrite); `resources/views/dashboard/partials/marketing.blade.php` (target section); `resources/views/layouts/sidebar.blade.php` (menu); `resources/views/layouts/partials/notifications.blade.php` + `resources/views/notifications/index.blade.php` (icon map); `routes/web.php` (routes); `tests/Unit/MarketingTargetServiceTest.php` (rewrite).

**Delete:** `tests/Feature/MarketingTargetTest.php` (v1 — replaced by the three new feature test files).

---

## Task 1: Migration (date-range) + Model + drop v1 feature test

**Files:**
- Create: `database/migrations/2026_06_22_000002_update_tb_marketing_targets_to_date_range.php`
- Modify: `app/Models/MarketingTarget.php`
- Delete: `tests/Feature/MarketingTargetTest.php`

- [ ] **Step 1: Create the alter migration**

Create `database/migrations/2026_06_22_000002_update_tb_marketing_targets_to_date_range.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_marketing_targets', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'year', 'month']);
            $table->dropColumn(['year', 'month']);

            $table->date('start_date')->nullable()->after('user_id');
            $table->date('end_date')->nullable()->after('start_date');
            $table->uuid('batch_id')->nullable()->after('commission_rate');
            $table->boolean('commission_paid')->default(false)->after('batch_id');
            $table->timestamp('commission_paid_at')->nullable()->after('commission_paid');
            $table->foreignId('commission_paid_by')->nullable()->after('commission_paid_at')->constrained('users')->nullOnDelete();

            $table->index(['user_id', 'start_date', 'end_date']);
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('tb_marketing_targets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('commission_paid_by');
            $table->dropIndex(['user_id', 'start_date', 'end_date']);
            $table->dropIndex(['batch_id']);
            $table->dropColumn(['start_date', 'end_date', 'batch_id', 'commission_paid', 'commission_paid_at']);

            $table->smallInteger('year')->after('user_id');
            $table->unsignedTinyInteger('month')->after('year');
            $table->unique(['user_id', 'year', 'month']);
        });
    }
};
```

- [ ] **Step 2: Update the model**

Replace `$fillable`, `$casts`, and relations in `app/Models/MarketingTarget.php` so the class body is:

```php
    protected $table = 'tb_marketing_targets';

    protected $fillable = [
        'user_id', 'start_date', 'end_date', 'target_amount', 'commission_rate',
        'batch_id', 'commission_paid', 'commission_paid_at', 'commission_paid_by',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'start_date'         => 'date',
        'end_date'           => 'date',
        'target_amount'      => 'integer',
        'commission_rate'    => 'decimal:2',
        'commission_paid'    => 'boolean',
        'commission_paid_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function paidBy()
    {
        return $this->belongsTo(User::class, 'commission_paid_by');
    }
```

(Keep the `<?php`, namespace, `use` lines, `class MarketingTarget extends Model`, and `use HasFactory;` at the top.)

- [ ] **Step 3: Delete the v1 feature test (replaced by new files in later tasks)**

```
git rm tests/Feature/MarketingTargetTest.php
```

- [ ] **Step 4: Verify the migration applies (via an unrelated RefreshDatabase test)**

Run: `php artisan test --filter=PaymentBookCleanupTest`
Expected: PASS — `RefreshDatabase` runs the create + alter migration cleanly. (This test does not touch marketing targets, so it isolates "is the migration valid".)

> Note: `MarketingTargetServiceTest` (v1 unit) is now red because it uses `year`/`month`; it is rewritten in Task 3. Do not run it here.

- [ ] **Step 5: Commit**

```
git add database/migrations/2026_06_22_000002_update_tb_marketing_targets_to_date_range.php app/Models/MarketingTarget.php tests/Feature/MarketingTargetTest.php
git commit -m "feat(target-v2): date-range schema + model; drop v1 feature test

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 2: Notifier methods for target events

**Files:**
- Modify: `app/Services/Notifier.php`
- Modify: `resources/views/layouts/partials/notifications.blade.php`
- Modify: `resources/views/notifications/index.blade.php`
- Test: `tests/Unit/NotifierTest.php`

- [ ] **Step 1: Add failing unit tests**

Add these two methods to `tests/Unit/NotifierTest.php` (inside the class, after the last test). `MarketingTarget` needs importing — add `use App\Models\MarketingTarget;` to the top use-block of that test file if not present.

```php
    /** @test */
    public function target_assigned_notifies_marketing_not_actor(): void
    {
        Notification::fake();
        $mkt   = $this->user('marketing');
        $actor = $this->user('superadmin');
        $target = MarketingTarget::create([
            'user_id' => $mkt->id, 'start_date' => now()->toDateString(), 'end_date' => now()->addMonth()->toDateString(),
            'target_amount' => 10000000, 'commission_rate' => 5,
        ]);

        $this->notifier->targetAssigned($target, $actor);

        Notification::assertSentTo($mkt, DatabaseNotification::class, fn ($n) => $n->payload['category'] === 'target');
        Notification::assertNotSentTo($actor, DatabaseNotification::class);
    }

    /** @test */
    public function commission_paid_notifies_marketing(): void
    {
        Notification::fake();
        $mkt   = $this->user('marketing');
        $actor = $this->user('superadmin');
        $target = MarketingTarget::create([
            'user_id' => $mkt->id, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->subDay()->toDateString(),
            'target_amount' => 10000000, 'commission_rate' => 5, 'commission_paid' => true,
        ]);

        $this->notifier->commissionPaid($target, $actor);

        Notification::assertSentTo($mkt, DatabaseNotification::class, fn ($n) => $n->payload['category'] === 'target');
    }
```

(The `NotifierTest` already has `private Notifier $notifier;` set in `setUp` and a `user(string $role)` helper from Spec B.)

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter="target_assigned_notifies_marketing_not_actor|commission_paid_notifies_marketing"`
Expected: FAIL — `Call to undefined method App\Services\Notifier::targetAssigned()`.

- [ ] **Step 3: Add the two methods to `app/Services/Notifier.php`**

Add `use App\Models\MarketingTarget;` to the top use-block. Then add these two public methods (e.g. after `naskahNeedsReview`):

```php
    public function targetAssigned(MarketingTarget $target, User $actor): void
    {
        $target->loadMissing('user');
        $this->toOwner($target->user, $actor, [
            'category' => 'target',
            'title'    => 'Target baru ditetapkan',
            'message'  => 'Periode ' . $target->start_date->format('d M') . ' – ' . $target->end_date->format('d M Y')
                          . ' • Target Rp ' . $this->rp($target->target_amount),
            'url'      => route('marketing-target.me'),
            'icon'     => 'target',
        ]);
    }

    public function commissionPaid(MarketingTarget $target, User $actor): void
    {
        $target->loadMissing('user');
        $this->toOwner($target->user, $actor, [
            'category' => 'target',
            'title'    => 'Komisi target ditandai dibayar',
            'message'  => 'Periode ' . $target->start_date->format('d M') . ' – ' . $target->end_date->format('d M Y'),
            'url'      => route('marketing-target.me'),
            'icon'     => 'check-circle',
        ]);
    }
```

(`toOwner`, `rp`, and `send` already exist as private helpers in `Notifier`.)

- [ ] **Step 4: Add `target` to the two notification icon maps**

In `resources/views/layouts/partials/notifications.blade.php` AND `resources/views/notifications/index.blade.php`, change the line:

```blade
@php $iconMap = ['payment' => 'credit-card', 'tagihan' => 'file-text', 'naskah' => 'book-open']; @endphp
```

to:

```blade
@php $iconMap = ['payment' => 'credit-card', 'tagihan' => 'file-text', 'naskah' => 'book-open', 'target' => 'target']; @endphp
```

- [ ] **Step 5: Run, confirm PASS**

Run: `php artisan test --filter=NotifierTest`
Expected: PASS (all NotifierTest cases, including the 2 new ones).

- [ ] **Step 6: Commit**

```
git add app/Services/Notifier.php resources/views/layouts/partials/notifications.blade.php resources/views/notifications/index.blade.php tests/Unit/NotifierTest.php
git commit -m "feat(target-v2): Notifier targetAssigned + commissionPaid

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 3: Service rewrite + dashboard wiring (TDD)

**Files:**
- Modify: `app/Services/MarketingTargetService.php` (rewrite)
- Modify: `app/Services/MarketingDashboardService.php` (target key)
- Modify: `resources/views/dashboard/partials/marketing.blade.php` (target section)
- Rewrite: `tests/Unit/MarketingTargetServiceTest.php`
- Create: `tests/Feature/MarketingTargetDashboardTest.php`

- [ ] **Step 1: Rewrite `tests/Unit/MarketingTargetServiceTest.php` with exactly:**

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\Payment;
use App\Models\MarketingTarget;
use App\Services\MarketingTargetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class MarketingTargetServiceTest extends TestCase
{
    use RefreshDatabase;

    private MarketingTargetService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
        $this->svc = new MarketingTargetService();
    }

    private function marketing(): User
    {
        $u = User::factory()->create();
        $u->assignRole('marketing');
        return $u;
    }

    private function paidOn(User $u, int $amount, $date): void
    {
        $order = Order::factory()->create(['user_id' => $u->id]);
        Payment::create(['order_id' => $order->id, 'payment_type' => 'dp', 'amount' => $amount, 'paid_at' => $date, 'status' => 'paid']);
    }

    private function target(User $u, array $attrs = []): MarketingTarget
    {
        return MarketingTarget::create(array_merge([
            'user_id' => $u->id,
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'target_amount' => 10000000, 'commission_rate' => 5,
        ], $attrs));
    }

    /** @test */
    public function progress_uses_date_range_and_derives_status_and_commission(): void
    {
        $u = $this->marketing();
        $t = $this->target($u);
        $this->paidOn($u, 6000000, now());                          // dalam rentang
        $this->paidOn($u, 9000000, now()->subMonths(2));            // di luar rentang → tidak ikut

        $p = $this->svc->progressFor($t);

        $this->assertSame(6000000, $p['realisasi']);
        $this->assertSame(60.0, $p['capaian_persen']);
        $this->assertSame(300000, $p['komisi']);            // 5% of 6jt
        $this->assertSame(4000000, $p['sisa']);
        $this->assertSame('aktif', $p['status']);
        $this->assertFalse($p['commission_paid']);
        $this->assertFalse($p['tertunggak']);
    }

    /** @test */
    public function expired_and_overdue_flags(): void
    {
        $u = $this->marketing();
        // berakhir 10 hari lalu, komisi belum dibayar → tertunggak
        $t = $this->target($u, ['start_date' => now()->subDays(40)->toDateString(), 'end_date' => now()->subDays(10)->toDateString()]);

        $p = $this->svc->progressFor($t);

        $this->assertSame('berakhir', $p['status']);
        $this->assertTrue($p['tertunggak']);
    }

    /** @test */
    public function paid_commission_is_not_overdue(): void
    {
        $u = $this->marketing();
        $t = $this->target($u, [
            'start_date' => now()->subDays(40)->toDateString(), 'end_date' => now()->subDays(10)->toDateString(),
            'commission_paid' => true,
        ]);

        $p = $this->svc->progressFor($t);
        $this->assertTrue($p['commission_paid']);
        $this->assertFalse($p['tertunggak']);
    }

    /** @test */
    public function current_for_marketing_picks_active_target(): void
    {
        $u = $this->marketing();
        $this->target($u, ['start_date' => now()->subMonths(2)->toDateString(), 'end_date' => now()->subMonth()->toDateString()]); // berakhir
        $active = $this->target($u); // aktif (bulan ini)

        $cur = $this->svc->currentForMarketing($u);
        $this->assertNotNull($cur);
        $this->assertSame($active->id, $cur['id']);

        $other = $this->marketing();
        $this->assertNull($this->svc->currentForMarketing($other));
    }

    /** @test */
    public function create_target_individual_and_all(): void
    {
        $a = $this->marketing();
        $b = $this->marketing();
        $actor = $this->user_superadmin();

        // individu untuk $a
        $this->svc->createTarget('individual', [$a->id], 5000000, 4, now()->toDateString(), now()->addMonth()->toDateString(), $actor);
        $this->assertSame(1, MarketingTarget::where('user_id', $a->id)->count());
        $this->assertNull(MarketingTarget::where('user_id', $a->id)->first()->batch_id);

        // semua → 1 baris per marketing (a & b), batch_id sama
        $this->svc->createTarget('all', [], 7000000, 6, now()->toDateString(), now()->addMonth()->toDateString(), $actor);
        $batch = MarketingTarget::whereNotNull('batch_id')->pluck('batch_id')->unique();
        $this->assertCount(1, $batch);
        $this->assertSame(2, MarketingTarget::whereNotNull('batch_id')->count());
    }

    /** @test */
    public function mark_commission_paid_sets_fields(): void
    {
        $u = $this->marketing();
        $t = $this->target($u);
        $actor = $this->user_superadmin();

        $this->svc->markCommissionPaid($t, $actor);

        $t->refresh();
        $this->assertTrue($t->commission_paid);
        $this->assertNotNull($t->commission_paid_at);
        $this->assertSame($actor->id, $t->commission_paid_by);
    }

    private function user_superadmin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');
        return $u;
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=MarketingTargetServiceTest`
Expected: FAIL — `progressFor` signature mismatch / undefined methods (`currentForMarketing`, `createTarget`, `markCommissionPaid`).

- [ ] **Step 3: Replace `app/Services/MarketingTargetService.php` entirely with:**

```php
<?php

namespace App\Services;

use App\Models\MarketingTarget;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MarketingTargetService
{
    /** Progres + status untuk satu target. */
    public function progressFor(MarketingTarget $target): array
    {
        $target->loadMissing('user');
        $today = Carbon::today();
        $start = $target->start_date;
        $end   = $target->end_date;

        $realisasi = (int) Payment::approved()->forOrdersOf($target->user)
            ->whereBetween('paid_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->sum('amount');

        $t    = (int) $target->target_amount;
        $rate = (float) $target->commission_rate;

        $status = $today->lt($start) ? 'akan_datang' : ($today->gt($end) ? 'berakhir' : 'aktif');
        $tertunggak = $today->gt($end->copy()->addDays(7)) && ! $target->commission_paid;

        return [
            'id'                 => $target->id,
            'user_id'            => $target->user_id,
            'scope'              => $target->batch_id ? 'all' : 'individual',
            'batch_id'           => $target->batch_id,
            'target'             => $t,
            'rate'               => $rate,
            'realisasi'          => $realisasi,
            'capaian_persen'     => $t > 0 ? round($realisasi / $t * 100, 1) : 0.0,
            'komisi'             => (int) round($rate / 100 * $realisasi),
            'sisa'               => max($t - $realisasi, 0),
            'start_date'         => $start->format('Y-m-d'),
            'end_date'           => $end->format('Y-m-d'),
            'status'             => $status,
            'commission_paid'    => (bool) $target->commission_paid,
            'commission_paid_at' => optional($target->commission_paid_at)->format('Y-m-d'),
            'tertunggak'         => $tertunggak,
        ];
    }

    /** Target aktif (hari ini dalam rentang) untuk kartu dashboard; null bila tak ada. */
    public function currentForMarketing(User $marketing): ?array
    {
        $today = Carbon::today();
        $target = MarketingTarget::where('user_id', $marketing->id)
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->orderBy('end_date')
            ->first();

        return $target ? $this->progressFor($target) : null;
    }

    /** Semua target milik satu marketing (untuk halaman Target Saya). */
    public function listForMarketing(User $marketing): Collection
    {
        return MarketingTarget::where('user_id', $marketing->id)
            ->orderByDesc('start_date')->get()
            ->map(fn (MarketingTarget $t) => $this->progressFor($t))
            ->values();
    }

    /** Semua target + nama marketing untuk halaman admin (filter status opsional). */
    public function adminList(?string $status = null): Collection
    {
        $rows = MarketingTarget::with('user')->orderByDesc('start_date')->get()
            ->map(function (MarketingTarget $t) {
                $p = $this->progressFor($t);
                $p['name'] = $t->user?->name ?? '—';
                return $p;
            });

        if ($status) {
            $rows = $rows->where('status', $status);
        }

        return $rows->values();
    }

    /** Buat target: individual (untuk $userIds) atau all (1 baris per marketing aktif, batch_id sama). */
    public function createTarget(string $scope, array $userIds, int $amount, float $rate, string $start, string $end, User $actor): void
    {
        $marketingIds = User::role('marketing')->pluck('id')->all();
        $batchId = null;

        if ($scope === 'all') {
            $userIds = $marketingIds;
            $batchId = (string) Str::uuid();
        } else {
            $userIds = array_values(array_intersect(array_map('intval', $userIds), $marketingIds));
        }

        if (empty($userIds)) {
            return;
        }

        $created = [];
        DB::transaction(function () use ($userIds, $amount, $rate, $start, $end, $actor, $batchId, &$created) {
            foreach ($userIds as $uid) {
                $created[] = MarketingTarget::create([
                    'user_id'         => $uid,
                    'start_date'      => $start,
                    'end_date'        => $end,
                    'target_amount'   => $amount,
                    'commission_rate' => $rate,
                    'batch_id'        => $batchId,
                    'created_by'      => $actor->id,
                    'updated_by'      => $actor->id,
                ]);
            }
        });

        foreach ($created as $target) {
            app(Notifier::class)->targetAssigned($target, $actor);
        }
    }

    /** Tandai komisi target sudah dibayar. */
    public function markCommissionPaid(MarketingTarget $target, User $actor): void
    {
        $target->update([
            'commission_paid'    => true,
            'commission_paid_at' => now(),
            'commission_paid_by' => $actor->id,
        ]);

        app(Notifier::class)->commissionPaid($target, $actor);
    }
}
```

- [ ] **Step 4: Update `app/Services/MarketingDashboardService.php` target key**

In `forUser()`, change the line:

```php
            'target'          => app(\App\Services\MarketingTargetService::class)->progressFor($user, $today->year, $today->month),
```

to:

```php
            'target'          => app(\App\Services\MarketingTargetService::class)->currentForMarketing($user),
```

- [ ] **Step 5: Update the dashboard partial target section**

In `resources/views/dashboard/partials/marketing.blade.php`, replace the whole "Target Bulan Ini" block (the `<h6 ...>Target Bulan Ini</h6>` heading through its closing `</div>` before the "Statistik Order & Tagihan" heading) with:

```blade
<h6 class="text-muted mb-2 mt-2">Target Berjalan</h6>
<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            @if($mkt['target'])
                @php
                    $t = $mkt['target'];
                    $tcls = $t['capaian_persen'] >= 100 ? 'bg-success' : ($t['capaian_persen'] >= 75 ? 'bg-warning' : 'bg-danger');
                @endphp
                <div class="d-flex justify-content-between align-items-center mb-1 flex-wrap">
                    <span>Capaian: <strong>{{ $t['capaian_persen'] }}%</strong>
                        <span class="badge bg-info ms-1">Aktif</span></span>
                    <span class="text-muted">Periode: <strong>{{ \Illuminate\Support\Carbon::parse($t['start_date'])->format('d M') }} – {{ \Illuminate\Support\Carbon::parse($t['end_date'])->format('d M Y') }}</strong></span>
                </div>
                <div class="progress mb-3" style="height:18px">
                    <div class="progress-bar {{ $tcls }}" role="progressbar" style="width: {{ min($t['capaian_persen'], 100) }}%">{{ $t['capaian_persen'] }}%</div>
                </div>
                <div class="row text-center">
                    <div class="col-md-3"><small class="text-muted d-block">Target</small><strong>Rp {{ number_format($t['target'], 0, ',', '.') }}</strong></div>
                    <div class="col-md-3"><small class="text-muted d-block">Realisasi</small><strong class="text-primary">Rp {{ number_format($t['realisasi'], 0, ',', '.') }}</strong></div>
                    <div class="col-md-3"><small class="text-muted d-block">Sisa</small><strong class="{{ $t['sisa'] > 0 ? 'text-danger' : 'text-success' }}">Rp {{ number_format($t['sisa'], 0, ',', '.') }}</strong></div>
                    <div class="col-md-3"><small class="text-muted d-block">Komisi</small><strong class="text-success">Rp {{ number_format($t['komisi'], 0, ',', '.') }}</strong></div>
                </div>
                <div class="text-end mt-2"><a href="{{ route('marketing-target.me') }}" class="small">Lihat semua target →</a></div>
            @else
                <p class="text-muted mb-0">Tidak ada target berjalan saat ini. <a href="{{ route('marketing-target.me') }}" class="small">Lihat riwayat target →</a></p>
            @endif
        </div></div>
    </div>
</div>
```

(This references `route('marketing-target.me')`, defined in Task 5. Routes are registered app-wide before any request in tests; since Task 5 adds it, run Step 7 only after the full plan — but the dashboard test in Step 6 below needs it, so this task assumes Task 5's route exists. To keep Task 3 self-contained, ALSO add the `me` route now — see Step 5b.)

- [ ] **Step 5b: Add the `marketing-target.me` route now (so the dashboard link resolves)**

In `routes/web.php`, inside the `Route::middleware('auth')->group(...)`, add (near the other marketing-target routes / income group):

```php
    Route::get('target', [\App\Http\Controllers\Pages\MarketingTargetController::class, 'me'])
        ->name('marketing-target.me')
        ->middleware('role:marketing|manager|superadmin');
```

And add a minimal `me` method to `app/Http/Controllers/Pages/MarketingTargetController.php` (the full admin rewrite happens in Task 4; this stub keeps the route working):

```php
    public function me(\App\Services\MarketingTargetService $service)
    {
        $rows = $service->listForMarketing(\Illuminate\Support\Facades\Auth::user());
        return view('target.me', compact('rows'));
    }
```

Create a minimal `resources/views/target/me.blade.php` (full version in Task 5):

```blade
@extends('layouts.master')
@section('title', 'Target Saya - SiMAPA')
@section('content')
<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <h6 class="card-title">Target Saya</h6>
    <p class="text-muted">Daftar target akan tampil di sini.</p>
</div></div></div></div>
@endsection
```

- [ ] **Step 6: Create `tests/Feature/MarketingTargetDashboardTest.php`:**

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\MarketingTarget;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class MarketingTargetDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'manager', 'superadmin', 'production'] as $r) {
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
    public function dashboard_shows_active_target_with_period(): void
    {
        $mkt = $this->user('marketing');
        MarketingTarget::create([
            'user_id' => $mkt->id,
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'target_amount' => 10000000, 'commission_rate' => 5,
        ]);

        $this->actingAs($mkt)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Target Berjalan')
            ->assertSee('Periode')
            ->assertSee('Capaian');
    }

    /** @test */
    public function dashboard_shows_empty_state_without_active_target(): void
    {
        $mkt = $this->user('marketing');
        $this->actingAs($mkt)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Tidak ada target berjalan');
    }
}
```

- [ ] **Step 7: Run, confirm PASS**

Run: `php artisan test --filter=MarketingTargetServiceTest`
Then: `php artisan test --filter=MarketingTargetDashboardTest`
Then (no regression): `php artisan test --filter=MarketingDashboardTest`
Expected: all PASS.

- [ ] **Step 8: Commit**

```
git add app/Services/MarketingTargetService.php app/Services/MarketingDashboardService.php resources/views/dashboard/partials/marketing.blade.php resources/views/target/me.blade.php app/Http/Controllers/Pages/MarketingTargetController.php routes/web.php tests/Unit/MarketingTargetServiceTest.php tests/Feature/MarketingTargetDashboardTest.php
git commit -m "feat(target-v2): service rewrite (date-range) + dashboard period/status

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 4: Admin page rework (TDD)

**Files:**
- Modify: `app/Http/Controllers/Pages/MarketingTargetController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/marketing-target/index.blade.php` (rewrite)
- Test: `tests/Feature/MarketingTargetAdminTest.php`

- [ ] **Step 1: Create `tests/Feature/MarketingTargetAdminTest.php`:**

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\MarketingTarget;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class MarketingTargetAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'manager', 'superadmin', 'production'] as $r) {
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
    public function manager_can_view_index_and_create_individual_target(): void
    {
        $manager = $this->user('manager');
        $mkt = $this->user('marketing');
        $mkt->update(['name' => 'MARKETING SATU']);

        $this->actingAs($manager)->get(route('marketing-target.index'))
            ->assertOk()->assertSee('MARKETING SATU');

        $this->actingAs($manager)->post(route('marketing-target.store'), [
            'scope' => 'individual', 'user_ids' => [$mkt->id],
            'target_amount' => 9000000, 'commission_rate' => 5,
            'start_date' => now()->toDateString(), 'end_date' => now()->addMonth()->toDateString(),
        ])->assertRedirect();

        $this->assertDatabaseHas('tb_marketing_targets', [
            'user_id' => $mkt->id, 'target_amount' => 9000000, 'batch_id' => null,
        ]);
    }

    /** @test */
    public function create_all_makes_one_row_per_marketing(): void
    {
        $manager = $this->user('manager');
        $this->user('marketing');
        $this->user('marketing');

        $this->actingAs($manager)->post(route('marketing-target.store'), [
            'scope' => 'all',
            'target_amount' => 5000000, 'commission_rate' => 4,
            'start_date' => now()->toDateString(), 'end_date' => now()->addMonth()->toDateString(),
        ])->assertRedirect();

        $this->assertSame(2, MarketingTarget::whereNotNull('batch_id')->count());
    }

    /** @test */
    public function mark_paid_and_delete(): void
    {
        $manager = $this->user('manager');
        $mkt = $this->user('marketing');
        $t = MarketingTarget::create([
            'user_id' => $mkt->id, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->subDay()->toDateString(),
            'target_amount' => 1000000, 'commission_rate' => 5,
        ]);

        $this->actingAs($manager)->post(route('marketing-target.paid', $t->id))->assertRedirect();
        $this->assertTrue($t->fresh()->commission_paid);

        $this->actingAs($manager)->delete(route('marketing-target.destroy', $t->id))->assertRedirect();
        $this->assertDatabaseMissing('tb_marketing_targets', ['id' => $t->id]);
    }

    /** @test */
    public function marketing_cannot_access_admin(): void
    {
        $this->actingAs($this->user('marketing'))
            ->get(route('marketing-target.index'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=MarketingTargetAdminTest`
Expected: FAIL — route `marketing-target.store` not defined.

- [ ] **Step 3: Rewrite `app/Http/Controllers/Pages/MarketingTargetController.php`** (replace the whole class body; keep `<?php`, namespace, and add the imports shown):

```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\MarketingTarget;
use App\Services\MarketingTargetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MarketingTargetController extends Controller
{
    public function index(Request $request, MarketingTargetService $service)
    {
        $status = $request->input('status'); // null | aktif | berakhir
        $rows = $service->adminList($status);
        $marketers = \App\Models\User::role('marketing')->orderBy('name')->get(['id', 'name']);

        return view('marketing-target.index', compact('rows', 'status', 'marketers'));
    }

    public function store(Request $request, MarketingTargetService $service)
    {
        $data = $request->validate([
            'scope'           => 'required|in:individual,all',
            'user_ids'        => 'required_if:scope,individual|array',
            'user_ids.*'      => 'integer',
            'target_amount'   => 'required|numeric|min:0',
            'commission_rate' => 'required|numeric|min:0|max:100',
            'start_date'      => 'required|date',
            'end_date'        => 'required|date|after_or_equal:start_date',
        ]);

        $service->createTarget(
            $data['scope'],
            $data['user_ids'] ?? [],
            (int) $data['target_amount'],
            (float) $data['commission_rate'],
            $data['start_date'],
            $data['end_date'],
            Auth::user(),
        );

        return redirect()->route('marketing-target.index')->with('success', 'Target dibuat.');
    }

    public function paid(int $id, MarketingTargetService $service)
    {
        $target = MarketingTarget::findOrFail($id);
        $service->markCommissionPaid($target, Auth::user());

        return back()->with('success', 'Komisi ditandai dibayar.');
    }

    public function destroy(int $id)
    {
        $target = MarketingTarget::findOrFail($id);

        if ($target->batch_id) {
            MarketingTarget::where('batch_id', $target->batch_id)->delete();
        } else {
            $target->delete();
        }

        return back()->with('success', 'Target dihapus.');
    }

    public function me(MarketingTargetService $service)
    {
        $rows = $service->listForMarketing(Auth::user());
        return view('target.me', compact('rows'));
    }
}
```

- [ ] **Step 4: Replace the admin routes in `routes/web.php`**

Find the v1 marketing-target route group:

```php
    Route::middleware('role:manager|superadmin')->group(function () {
        Route::get('marketing-target', [MarketingTargetController::class, 'index'])->name('marketing-target.index');
        Route::post('marketing-target', [MarketingTargetController::class, 'save'])->name('marketing-target.save');
    });
```

Replace it with:

```php
    Route::middleware('role:manager|superadmin')->group(function () {
        Route::get('marketing-target', [MarketingTargetController::class, 'index'])->name('marketing-target.index');
        Route::post('marketing-target', [MarketingTargetController::class, 'store'])->name('marketing-target.store');
        Route::post('marketing-target/{id}/paid', [MarketingTargetController::class, 'paid'])->name('marketing-target.paid');
        Route::delete('marketing-target/{id}', [MarketingTargetController::class, 'destroy'])->name('marketing-target.destroy');
    });
```

(Leave the `marketing-target.me` route added in Task 3 as-is.)

- [ ] **Step 5: Rewrite `resources/views/marketing-target/index.blade.php` with:**

```blade
@extends('layouts.master')
@section('title', 'Target Marketing - SiMAPA')

@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@php
    $statusBadge = ['aktif' => 'bg-info', 'berakhir' => 'bg-secondary', 'akan_datang' => 'bg-light text-dark'];
@endphp
<div class="row">
    <div class="col-md-4 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Buat Target</h6>
            <form method="POST" action="{{ route('marketing-target.store') }}">
                @csrf
                <div class="mb-2">
                    <label class="form-label">Cakupan</label>
                    <select name="scope" id="scopeSelect" class="form-select form-select-sm">
                        <option value="individual">Individu</option>
                        <option value="all">Semua marketing</option>
                    </select>
                </div>
                <div class="mb-2" id="userWrap">
                    <label class="form-label">Marketing</label>
                    <select name="user_ids[]" class="form-select form-select-sm" multiple size="4">
                        @foreach($marketers as $m)
                            <option value="{{ $m->id }}">{{ $m->name }}</option>
                        @endforeach
                    </select>
                    <small class="text-muted">Ctrl/Cmd untuk pilih beberapa.</small>
                </div>
                <div class="mb-2"><label class="form-label">Target Pemasukan (Rp)</label>
                    <input type="number" name="target_amount" min="0" step="1000" class="form-control form-control-sm" required></div>
                <div class="mb-2"><label class="form-label">Rate Komisi (%)</label>
                    <input type="number" name="commission_rate" min="0" max="100" step="0.5" class="form-control form-control-sm" required></div>
                <div class="row">
                    <div class="col-6 mb-2"><label class="form-label">Mulai</label>
                        <input type="date" name="start_date" class="form-control form-control-sm" value="{{ now()->toDateString() }}" required></div>
                    <div class="col-6 mb-2"><label class="form-label">Selesai</label>
                        <input type="date" name="end_date" class="form-control form-control-sm" value="{{ now()->addMonth()->toDateString() }}" required></div>
                </div>
                <button type="submit" class="btn btn-primary btn-sm mt-1">Simpan Target</button>
            </form>
        </div></div>
    </div>

    <div class="col-md-8 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="card-title mb-0">Daftar Target</h6>
                <div class="btn-group btn-group-sm">
                    <a href="{{ route('marketing-target.index') }}" class="btn btn-outline-primary {{ !$status ? 'active' : '' }}">Semua</a>
                    <a href="{{ route('marketing-target.index', ['status' => 'aktif']) }}" class="btn btn-outline-primary {{ $status === 'aktif' ? 'active' : '' }}">Berjalan</a>
                    <a href="{{ route('marketing-target.index', ['status' => 'berakhir']) }}" class="btn btn-outline-primary {{ $status === 'berakhir' ? 'active' : '' }}">History</a>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover datatable" style="width:100%">
                    <thead><tr>
                        <th>Marketing</th><th>Periode</th><th>Target</th><th>Realisasi</th><th>Capaian</th>
                        <th>Komisi</th><th>Status</th><th>Komisi Bayar</th><th>Aksi</th>
                    </tr></thead>
                    <tbody>
                        @forelse($rows as $r)
                            @php $ccls = $r['capaian_persen'] >= 100 ? 'bg-success' : ($r['capaian_persen'] >= 75 ? 'bg-warning text-dark' : 'bg-danger'); @endphp
                            <tr>
                                <td>{{ $r['name'] }} @if($r['scope'] === 'all')<span class="badge bg-dark">Semua</span>@endif</td>
                                <td><small>{{ \Illuminate\Support\Carbon::parse($r['start_date'])->format('d/m/y') }} – {{ \Illuminate\Support\Carbon::parse($r['end_date'])->format('d/m/y') }}</small></td>
                                <td>Rp {{ number_format($r['target'], 0, ',', '.') }}</td>
                                <td>Rp {{ number_format($r['realisasi'], 0, ',', '.') }}</td>
                                <td><span class="badge {{ $ccls }}">{{ $r['capaian_persen'] }}%</span></td>
                                <td>Rp {{ number_format($r['komisi'], 0, ',', '.') }}</td>
                                <td>
                                    <span class="badge {{ $statusBadge[$r['status']] ?? 'bg-secondary' }}">{{ ucfirst(str_replace('_', ' ', $r['status'])) }}</span>
                                    @if($r['tertunggak'])<span class="badge bg-danger">Tertunggak</span>@endif
                                </td>
                                <td>
                                    @if($r['commission_paid'])
                                        <span class="badge bg-success">Dibayar</span>
                                    @else
                                        <form action="{{ route('marketing-target.paid', $r['id']) }}" method="POST" class="m-0">
                                            @csrf
                                            <button class="btn btn-xs btn-outline-success">Tandai dibayar</button>
                                        </form>
                                    @endif
                                </td>
                                <td>
                                    <form action="{{ route('marketing-target.destroy', $r['id']) }}" method="POST" class="m-0" onsubmit="return confirm('Hapus target ini?');">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-xs btn-outline-danger">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-center text-muted py-3">Belum ada target.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div></div>
    </div>
</div>
@endsection

@push('plugin-scripts')
    <script src="{{ URL::asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
    $(function () {
        $('.datatable').DataTable({ pageLength: 10, order: [] });
        $('#scopeSelect').on('change', function () {
            $('#userWrap').toggle(this.value === 'individual');
        });
    });
</script>
@endpush
```

- [ ] **Step 6: Run, confirm PASS**

Run: `php artisan test --filter=MarketingTargetAdminTest`
Expected: PASS (4 tests).

- [ ] **Step 7: Commit**

```
git add app/Http/Controllers/Pages/MarketingTargetController.php routes/web.php resources/views/marketing-target/index.blade.php tests/Feature/MarketingTargetAdminTest.php
git commit -m "feat(target-v2): admin page (create date-range targets, mark paid, delete)

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 5: Marketing "Target Saya" page (TDD)

**Files:**
- Modify: `resources/views/target/me.blade.php` (full version)
- Modify: `resources/views/layouts/sidebar.blade.php` (menu)
- Test: `tests/Feature/MarketingTargetMeTest.php`

- [ ] **Step 1: Create `tests/Feature/MarketingTargetMeTest.php`:**

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\MarketingTarget;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class MarketingTargetMeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'manager', 'superadmin', 'production'] as $r) {
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
    public function marketing_sees_only_their_own_targets(): void
    {
        $me = $this->user('marketing');
        $other = $this->user('marketing');

        MarketingTarget::create(['user_id' => $me->id, 'start_date' => now()->startOfMonth()->toDateString(), 'end_date' => now()->endOfMonth()->toDateString(), 'target_amount' => 10000000, 'commission_rate' => 5]);
        MarketingTarget::create(['user_id' => $other->id, 'start_date' => now()->startOfMonth()->toDateString(), 'end_date' => now()->endOfMonth()->toDateString(), 'target_amount' => 77000000, 'commission_rate' => 5]);

        $this->actingAs($me)->get(route('marketing-target.me'))
            ->assertOk()
            ->assertSee('Target Saya')
            ->assertSee('10.000.000')
            ->assertDontSee('77.000.000');
    }

    /** @test */
    public function production_cannot_access_target_me(): void
    {
        $this->actingAs($this->user('production'))
            ->get(route('marketing-target.me'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=MarketingTargetMeTest`
Expected: FAIL — `marketing_sees_only_their_own_targets` fails (the stub me.blade.php doesn't render targets) and/or `production_cannot_access` may already pass.

- [ ] **Step 3: Replace `resources/views/target/me.blade.php` with the full version:**

```blade
@extends('layouts.master')
@section('title', 'Target Saya - SiMAPA')

@section('content')
@php
    $berjalan = $rows->whereIn('status', ['aktif', 'akan_datang'])->values();
    $history  = $rows->where('status', 'berakhir')->values();
@endphp

<div class="d-flex justify-content-between align-items-center flex-wrap grid-margin">
    <h4 class="mb-0">Target Saya</h4>
</div>

<h6 class="text-muted mb-2">Berjalan</h6>
<div class="row">
    @forelse($berjalan as $t)
        @php $ccls = $t['capaian_persen'] >= 100 ? 'bg-success' : ($t['capaian_persen'] >= 75 ? 'bg-warning text-dark' : 'bg-danger'); @endphp
        <div class="col-md-6 grid-margin stretch-card">
            <div class="card"><div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-1 flex-wrap">
                    <span><strong>{{ \Illuminate\Support\Carbon::parse($t['start_date'])->format('d M') }} – {{ \Illuminate\Support\Carbon::parse($t['end_date'])->format('d M Y') }}</strong>
                        <span class="badge bg-info">{{ $t['status'] === 'aktif' ? 'Aktif' : 'Akan datang' }}</span></span>
                    <span class="text-muted">Komisi: <strong class="text-success">Rp {{ number_format($t['komisi'], 0, ',', '.') }}</strong></span>
                </div>
                <div class="progress mb-2" style="height:16px">
                    <div class="progress-bar {{ $ccls }}" style="width: {{ min($t['capaian_persen'], 100) }}%">{{ $t['capaian_persen'] }}%</div>
                </div>
                <div class="d-flex justify-content-between small">
                    <span class="text-muted">Target: Rp {{ number_format($t['target'], 0, ',', '.') }}</span>
                    <span class="text-primary">Realisasi: Rp {{ number_format($t['realisasi'], 0, ',', '.') }}</span>
                    <span class="{{ $t['sisa'] > 0 ? 'text-danger' : 'text-success' }}">Sisa: Rp {{ number_format($t['sisa'], 0, ',', '.') }}</span>
                </div>
            </div></div>
        </div>
    @empty
        <div class="col-12 grid-margin"><p class="text-muted">Tidak ada target berjalan.</p></div>
    @endforelse
</div>

<h6 class="text-muted mb-2 mt-2">History</h6>
<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead><tr><th>Periode</th><th>Target</th><th>Realisasi</th><th>Capaian</th><th>Komisi</th><th>Status Komisi</th></tr></thead>
                    <tbody>
                        @forelse($history as $t)
                            <tr @if($t['tertunggak']) class="table-warning" @endif>
                                <td><small>{{ \Illuminate\Support\Carbon::parse($t['start_date'])->format('d/m/y') }} – {{ \Illuminate\Support\Carbon::parse($t['end_date'])->format('d/m/y') }}</small></td>
                                <td>Rp {{ number_format($t['target'], 0, ',', '.') }}</td>
                                <td>Rp {{ number_format($t['realisasi'], 0, ',', '.') }}</td>
                                <td>{{ $t['capaian_persen'] }}%</td>
                                <td>Rp {{ number_format($t['komisi'], 0, ',', '.') }}</td>
                                <td>
                                    @if($t['commission_paid'])
                                        <span class="badge bg-success">Dibayar</span>
                                    @elseif($t['tertunggak'])
                                        <span class="badge bg-danger">Belum (tertunggak)</span>
                                    @else
                                        <span class="badge bg-secondary">Belum dibayar</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-3">Belum ada target berakhir.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div></div>
    </div>
</div>
@endsection
```

- [ ] **Step 4: Add the "Target Saya" sidebar menu (marketing)**

In `resources/views/layouts/sidebar.blade.php`, find the "Target Marketing" admin menu added in v1 (inside the Laporan block, gated `@role(['superadmin', 'manager'])`):

```blade
                @role(['superadmin', 'manager'])
                    <li class="nav-item {{ active_class(['marketing-target']) }}">
                        <a href="{{ route('marketing-target.index') }}" class="nav-link">
                            <i class="link-icon" data-feather="target"></i>
                            <span class="link-title">Target Marketing</span>
                        </a>
                    </li>
                @endrole
```

Immediately AFTER that `@endrole`, add a marketing-only menu:

```blade
                @role(['marketing'])
                    <li class="nav-item {{ active_class(['target']) }}">
                        <a href="{{ route('marketing-target.me') }}" class="nav-link">
                            <i class="link-icon" data-feather="target"></i>
                            <span class="link-title">Target Saya</span>
                        </a>
                    </li>
                @endrole
```

- [ ] **Step 5: Run, confirm PASS**

Run: `php artisan test --filter=MarketingTargetMeTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```
git add resources/views/target/me.blade.php resources/views/layouts/sidebar.blade.php tests/Feature/MarketingTargetMeTest.php
git commit -m "feat(target-v2): marketing 'Target Saya' page (berjalan + history)

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 6: Full suite verification

**Files:** none (verification only)

- [ ] **Step 1: Run the whole suite**

Run: `php artisan test`
Expected: PASS — previous suite (202) minus the 3 deleted v1 marketing-target tests, plus the new ones: MarketingTargetServiceTest (7), NotifierTest (+2), MarketingTargetDashboardTest (2), MarketingTargetAdminTest (4), MarketingTargetMeTest (2). No red.

- [ ] **Step 2: Smoke (optional)**

Login `manager` (`fitri`/`password`) → "Target Marketing": buat target individu & "semua" dengan rentang tanggal; tandai komisi dibayar; hapus. Login marketing (`ika`/`password`) → "Target Saya" (berjalan + history) + kartu "Target Berjalan" di dashboard dengan periode. Cek lonceng notifikasi marketing setelah target dibuat / komisi dibayar.

---

## Catatan & Risiko

- **Dev/prod: jalankan `php artisan migrate`** untuk alter ini (kalau tidak, dashboard/halaman target 500). Lihat [[migrate-dev-db-after-new-migration]].
- v1 belum di-merge & tabel kosong → alter aman tanpa migrasi data; v1 marketing tests sengaja dihapus/ditulis ulang.
- `adminList`/`listForMarketing` menghitung realisasi per target (rentang berbeda-beda); jumlah target ter-batas pada domain ini sehingga dapat diterima.
- Realisasi/komisi tetap turunan; menandai dibayar tidak membekukan nilai komisi (snapshot di luar scope).
- Target "semua" tidak retroaktif untuk marketing baru; edit "semua" = hapus + buat ulang (tidak ada edit massal di v2).
- Notifikasi dikirim setelah commit & ter-guard (`Notifier::send`), jadi kegagalan notifikasi tak membatalkan aksi.
