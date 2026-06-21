# Notification System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** In-app notifikasi (lonceng navbar + halaman daftar) yang dipicu event aksi: approval pembayaran, tagihan, dan perubahan tahap naskah; tiap user hanya melihat notifikasinya sendiri.

**Architecture:** Notifikasi database bawaan Laravel (`notifications` table, `User` sudah `Notifiable`). Satu kelas amplop `DatabaseNotification` (`via=['database']`). Satu service `Notifier` memegang seluruh logika "siapa dapat apa" (method per-event) dan dipanggil **setelah commit** dari controller/service. UI: partial lonceng + halaman index + View Composer; mark-as-read via route POST.

**Tech Stack:** PHP 8.2 / Laravel 11, Spatie laravel-permission (`User::role()`), Blade + Bootstrap, feather icons, PHPUnit (`php artisan test`).

**Spec:** `docs/superpowers/specs/2026-06-21-notification-system-design.md`

**Catatan test:** `APP_ENV=testing` (phpunit.xml) → `.env.testing` → DB `avidpedi_simapa_test`. `RefreshDatabase` menjalankan migrasi (termasuk tabel `notifications` baru). Filter satu test: `php artisan test --filter=<NamaMethod>`.

---

## File Structure

**Dibuat:**
- `database/migrations/2026_06_21_000001_create_notifications_table.php` — tabel notifikasi standar Laravel.
- `app/Notifications/DatabaseNotification.php` — amplop generik (payload publik).
- `app/Services/Notifier.php` — pusat logika notifikasi (method per-event).
- `app/Http/Controllers/NotificationController.php` — index / read / readAll.
- `resources/views/layouts/partials/notifications.blade.php` — partial lonceng navbar.
- `resources/views/notifications/index.blade.php` — halaman daftar.
- `tests/Unit/NotifierTest.php`, `tests/Feature/NotificationUiTest.php`, `tests/Feature/NotificationHooksTest.php`.

**Dimodifikasi:**
- `app/Providers/AppServiceProvider.php` — View Composer + paginator Bootstrap 5.
- `resources/views/layouts/header.blade.php` — include partial lonceng.
- `routes/web.php` — 3 route notifikasi (grup `auth`).
- `app/Http/Controllers/Pages/PaymentBookController.php` — wiring store/approve/reject.
- `app/Http/Controllers/Pages/TagihanController.php` — wiring store/update/approve/reject.
- `app/Services/TitleProgressService.php` — wiring changeStatus & changeGroupStatus.

---

## Task 1: Tabel `notifications` + amplop `DatabaseNotification`

**Files:**
- Create: `database/migrations/2026_06_21_000001_create_notifications_table.php`
- Create: `app/Notifications/DatabaseNotification.php`

- [ ] **Step 1: Buat migrasi**

Create `database/migrations/2026_06_21_000001_create_notifications_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
```

- [ ] **Step 2: Buat kelas amplop**

Create `app/Notifications/DatabaseNotification.php`:

```php
<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class DatabaseNotification extends Notification
{
    /** @param array{category:string,title:string,message:string,url:string,icon:string} $payload */
    public function __construct(public array $payload) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload;
    }
}
```

- [ ] **Step 3: Verifikasi migrasi sehat**

Run: `php artisan test --filter=MarketingDashboardServiceTest`
Expected: PASS (RefreshDatabase memigrasi tabel `notifications` baru tanpa error). Jika gagal migrasi, perbaiki sintaks migrasi.

- [ ] **Step 4: Commit**

```
git add database/migrations/2026_06_21_000001_create_notifications_table.php app/Notifications/DatabaseNotification.php
git commit -m "feat(notif): notifications table + DatabaseNotification envelope

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 2: Service `Notifier` (TDD)

**Files:**
- Create: `app/Services/Notifier.php`
- Test: `tests/Unit/NotifierTest.php`

- [ ] **Step 1: Tulis unit test yang gagal**

Create `tests/Unit/NotifierTest.php`:

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Tagihan;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Notifications\DatabaseNotification;
use App\Services\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

class NotifierTest extends TestCase
{
    use RefreshDatabase;

    private Notifier $notifier;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'manager', 'superadmin', 'production'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
        $this->notifier = new Notifier();
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    private function payment(User $owner, int $amount = 500000, string $status = 'paid'): Payment
    {
        $order = Order::factory()->create(['user_id' => $owner->id]);
        return Payment::create(['order_id' => $order->id, 'payment_type' => 'dp', 'amount' => $amount, 'paid_at' => now(), 'status' => $status]);
    }

    /** @test */
    public function payment_approved_notifies_owner_not_actor(): void
    {
        Notification::fake();
        $owner = $this->user('marketing');
        $actor = $this->user('superadmin');

        $this->notifier->paymentApproved($this->payment($owner), $actor);

        Notification::assertSentTo($owner, DatabaseNotification::class, fn ($n) =>
            $n->payload['category'] === 'payment' && str_contains($n->payload['title'], 'disetujui'));
        Notification::assertNotSentTo($actor, DatabaseNotification::class);
    }

    /** @test */
    public function payment_submitted_notifies_managers_and_superadmins_only(): void
    {
        Notification::fake();
        $marketing = $this->user('marketing');
        $manager   = $this->user('manager');
        $admin     = $this->user('superadmin');

        $this->notifier->paymentSubmitted($this->payment($marketing), $marketing);

        Notification::assertSentTo($manager, DatabaseNotification::class);
        Notification::assertSentTo($admin, DatabaseNotification::class);
        Notification::assertNotSentTo($marketing, DatabaseNotification::class);
    }

    /** @test */
    public function tagihan_approved_notifies_creator(): void
    {
        Notification::fake();
        $creator = $this->user('marketing');
        $actor   = $this->user('superadmin');
        $tagihan = Tagihan::factory()->create(['created_by' => $creator->id, 'status' => 'disetujui']);

        $this->notifier->tagihanApproved($tagihan, $actor);

        Notification::assertSentTo($creator, DatabaseNotification::class, fn ($n) => $n->payload['category'] === 'tagihan');
        Notification::assertNotSentTo($actor, DatabaseNotification::class);
    }

    /** @test */
    public function naskah_stage_changed_notifies_owner_with_deep_link(): void
    {
        Notification::fake();
        $owner = $this->user('marketing');
        $actor = $this->user('production');
        $order = Order::factory()->create(['user_id' => $owner->id]);
        $detail = OrderDetail::factory()->create(['order_id' => $order->id, 'type' => 'bk_mandiri', 'title' => 'Naskah X']);
        $progress = TitleProgress::create(['order_detail_id' => $detail->id, 'status' => 'editing', 'assigned_role' => 'production', 'started_at' => now()]);

        $this->notifier->naskahStageChanged($progress, $actor, 'editing', 'layout');

        Notification::assertSentTo($owner, DatabaseNotification::class, fn ($n) =>
            $n->payload['category'] === 'naskah' && str_contains($n->payload['url'], (string) $progress->order_detail_id));
        Notification::assertNotSentTo($actor, DatabaseNotification::class);
    }

    /** @test */
    public function nothing_sent_when_no_recipients(): void
    {
        Notification::fake();
        $marketing = $this->user('marketing'); // tak ada manager/superadmin
        $this->notifier->paymentSubmitted($this->payment($marketing), $marketing);
        Notification::assertNothingSent();
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=NotifierTest`
Expected: FAIL — `Class "App\Services\Notifier" not found`.

- [ ] **Step 3: Implement `Notifier`**

Create `app/Services/Notifier.php`:

```php
<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Tagihan;
use App\Models\TitleProgress;
use App\Models\User;
use App\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class Notifier
{
    public function paymentSubmitted(Payment $payment, User $actor): void
    {
        $payment->loadMissing('order.user');
        $this->send($this->roleUsers(['manager', 'superadmin'], $actor), [
            'category' => 'payment',
            'title'    => 'Pembayaran menunggu persetujuan',
            'message'  => 'Rp ' . $this->rp($payment->amount) . ' dari ' . ($payment->order?->user?->name ?? '—'),
            'url'      => route('payment.index'),
            'icon'     => 'credit-card',
        ]);
    }

    public function paymentApproved(Payment $payment, User $actor): void
    {
        $payment->loadMissing('order.user');
        $this->toOwner($payment->order?->user, $actor, [
            'category' => 'payment',
            'title'    => 'Pembayaran disetujui',
            'message'  => 'Rp ' . $this->rp($payment->amount),
            'url'      => route('payment.index'),
            'icon'     => 'check-circle',
        ]);
    }

    public function paymentRejected(Payment $payment, User $actor): void
    {
        $payment->loadMissing('order.user');
        $this->toOwner($payment->order?->user, $actor, [
            'category' => 'payment',
            'title'    => 'Pembayaran ditolak',
            'message'  => 'Rp ' . $this->rp($payment->amount),
            'url'      => route('payment.index'),
            'icon'     => 'x-circle',
        ]);
    }

    public function tagihanSubmitted(Tagihan $tagihan, User $actor): void
    {
        $this->send($this->roleUsers(['superadmin'], $actor), [
            'category' => 'tagihan',
            'title'    => 'Tagihan menunggu persetujuan',
            'message'  => $tagihan->title . ' • Rp ' . $this->rp($tagihan->amount),
            'url'      => route('tagihan.show', $tagihan->id),
            'icon'     => 'file-text',
        ]);
    }

    public function tagihanApproved(Tagihan $tagihan, User $actor): void
    {
        $tagihan->loadMissing('creator');
        $this->toOwner($tagihan->creator, $actor, [
            'category' => 'tagihan',
            'title'    => 'Tagihan disetujui',
            'message'  => $tagihan->title,
            'url'      => route('tagihan.show', $tagihan->id),
            'icon'     => 'check-circle',
        ]);
    }

    public function tagihanRejected(Tagihan $tagihan, User $actor): void
    {
        $tagihan->loadMissing('creator');
        $this->toOwner($tagihan->creator, $actor, [
            'category' => 'tagihan',
            'title'    => 'Tagihan ditolak',
            'message'  => $tagihan->title . ($tagihan->reject_note ? ' — ' . $tagihan->reject_note : ''),
            'url'      => route('tagihan.show', $tagihan->id),
            'icon'     => 'x-circle',
        ]);
    }

    public function naskahStageChanged(TitleProgress $progress, User $actor, string $from, string $to): void
    {
        $progress->loadMissing('orderDetail.order.user');
        $tahap = Str::title(str_replace('_', ' ', $to));
        $this->toOwner($progress->orderDetail?->order?->user, $actor, [
            'category' => 'naskah',
            'title'    => 'Naskah maju ke ' . $tahap,
            'message'  => $progress->orderDetail?->title ?? 'Naskah',
            'url'      => route('order.indexJudul.progress', $progress->order_detail_id),
            'icon'     => 'book-open',
        ]);
    }

    public function naskahNeedsReview(TitleProgress $progress, User $actor): void
    {
        $progress->loadMissing('orderDetail');
        $this->send($this->roleUsers(['manager', 'superadmin'], $actor), [
            'category' => 'naskah',
            'title'    => 'Naskah perlu ditinjau',
            'message'  => $progress->orderDetail?->title ?? 'Naskah',
            'url'      => route('order.indexJudul.progress', $progress->order_detail_id),
            'icon'     => 'alert-triangle',
        ]);
    }

    private function rp(int|string|null $amount): string
    {
        return number_format((int) $amount, 0, ',', '.');
    }

    /** Users dengan salah satu role, kecuali aktor. */
    private function roleUsers(array $roles, User $actor): Collection
    {
        return User::role($roles)->get()->reject(fn (User $u) => $u->id === $actor->id)->values();
    }

    private function toOwner(?User $owner, User $actor, array $payload): void
    {
        if (! $owner || $owner->id === $actor->id) {
            return;
        }
        $this->send(collect([$owner]), $payload);
    }

    private function send(Collection $recipients, array $payload): void
    {
        if ($recipients->isEmpty()) {
            return;
        }
        try {
            Notification::send($recipients, new DatabaseNotification($payload));
        } catch (\Throwable $e) {
            Log::warning('Notifier gagal mengirim notifikasi: ' . $e->getMessage());
        }
    }
}
```

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=NotifierTest`
Expected: PASS (5 test).

- [ ] **Step 5: Commit**

```
git add app/Services/Notifier.php tests/Unit/NotifierTest.php
git commit -m "feat(notif): Notifier service with per-event recipient logic

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 3: Controller + routes + UI (lonceng, index, composer) (TDD)

**Files:**
- Create: `app/Http/Controllers/NotificationController.php`
- Create: `resources/views/layouts/partials/notifications.blade.php`
- Create: `resources/views/notifications/index.blade.php`
- Modify: `routes/web.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `resources/views/layouts/header.blade.php`
- Test: `tests/Feature/NotificationUiTest.php`

- [ ] **Step 1: Tulis feature test yang gagal**

Create `tests/Feature/NotificationUiTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Services\GoogleDriveService;
use App\Notifications\DatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class NotificationUiTest extends TestCase
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

    private function notify(User $u, array $payload = []): void
    {
        $u->notify(new DatabaseNotification(array_merge([
            'category' => 'payment', 'title' => 'Tes', 'message' => 'Pesan tes',
            'url' => route('dashboard'), 'icon' => 'bell',
        ], $payload)));
    }

    /** @test */
    public function bell_shows_notification_in_navbar(): void
    {
        $u = $this->user('marketing');
        $this->notify($u, ['title' => 'Notif Penting']);

        $this->actingAs($u)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Notif Penting');
    }

    /** @test */
    public function index_lists_only_own_notifications(): void
    {
        $me = $this->user('marketing');
        $other = $this->user('marketing');
        $this->notify($me, ['title' => 'Punya Saya']);
        $this->notify($other, ['title' => 'Punya Orang Lain']);

        $this->actingAs($me)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Punya Saya')
            ->assertDontSee('Punya Orang Lain');
    }

    /** @test */
    public function read_marks_as_read_and_redirects_to_url(): void
    {
        $u = $this->user('marketing');
        $this->notify($u, ['url' => route('profile')]);
        $id = $u->notifications()->first()->id;

        $this->actingAs($u)->post(route('notifications.read', $id))
            ->assertRedirect(route('profile'));

        $this->assertNotNull($u->fresh()->notifications()->first()->read_at);
    }

    /** @test */
    public function read_all_clears_unread(): void
    {
        $u = $this->user('marketing');
        $this->notify($u);
        $this->notify($u);

        $this->actingAs($u)->post(route('notifications.readAll'))->assertRedirect();

        $this->assertSame(0, $u->fresh()->unreadNotifications()->count());
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=NotificationUiTest`
Expected: FAIL — route `notifications.index` belum ada (RouteNotFoundException).

- [ ] **Step 3: Buat controller**

Create `app/Http/Controllers/NotificationController.php`:

```php
<?php

namespace App\Http\Controllers;

class NotificationController extends Controller
{
    public function index()
    {
        $notifications = auth()->user()->notifications()->latest()->paginate(20);
        return view('notifications.index', compact('notifications'));
    }

    public function read(string $id)
    {
        $notification = auth()->user()->notifications()->where('id', $id)->firstOrFail();
        $notification->markAsRead();
        return redirect($notification->data['url'] ?? url()->previous());
    }

    public function readAll()
    {
        auth()->user()->unreadNotifications->markAsRead();
        return back()->with('success', 'Semua notifikasi ditandai dibaca.');
    }
}
```

- [ ] **Step 4: Tambah route**

In `routes/web.php`, add the import near the other controller imports at the top:

```php
use App\Http\Controllers\NotificationController;
```

Inside the `Route::middleware('auth')->group(function () {` block (e.g. right after the `profile` prefix group), add:

```php
    //notifications
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.readAll');
```

- [ ] **Step 5: View Composer + paginator Bootstrap di `AppServiceProvider`**

Replace the whole `boot()` method in `app/Providers/AppServiceProvider.php` with:

```php
    public function boot(): void
    {
        \Illuminate\Pagination\Paginator::useBootstrapFive();

        \Illuminate\Support\Facades\View::composer('layouts.partials.notifications', function ($view) {
            $user = \Illuminate\Support\Facades\Auth::user();
            $view->with('navUnread', $user ? $user->unreadNotifications()->count() : 0);
            $view->with('navRecent', $user ? $user->notifications()->latest()->take(7)->get() : collect());
        });
    }
```

- [ ] **Step 6: Partial lonceng**

Create `resources/views/layouts/partials/notifications.blade.php`:

```blade
@php $iconMap = ['payment' => 'credit-card', 'tagihan' => 'file-text', 'naskah' => 'book-open']; @endphp
<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle position-relative" href="#" id="notifDropdown" role="button"
        data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
        <i data-feather="bell"></i>
        @if(($navUnread ?? 0) > 0)
            <span class="badge bg-danger rounded-pill"
                style="position:absolute;top:2px;right:0;font-size:9px">{{ $navUnread > 9 ? '9+' : $navUnread }}</span>
        @endif
    </a>
    <div class="dropdown-menu dropdown-menu-end p-0" aria-labelledby="notifDropdown" style="min-width:320px">
        <div class="px-3 py-2 border-bottom d-flex justify-content-between align-items-center">
            <h6 class="mb-0">Notifikasi</h6>
            @if(($navUnread ?? 0) > 0)
                <form action="{{ route('notifications.readAll') }}" method="POST" class="m-0">
                    @csrf
                    <button type="submit" class="btn btn-link btn-sm p-0 text-decoration-none">Tandai semua</button>
                </form>
            @endif
        </div>
        <div style="max-height:320px;overflow-y:auto">
            @forelse(($navRecent ?? collect()) as $n)
                <form action="{{ route('notifications.read', $n->id) }}" method="POST" class="m-0">
                    @csrf
                    <button type="submit"
                        class="dropdown-item d-flex align-items-start py-2 {{ is_null($n->read_at) ? 'bg-light' : '' }}"
                        style="white-space:normal">
                        <i data-feather="{{ $iconMap[$n->data['category'] ?? ''] ?? 'bell' }}" class="icon-sm me-2 mt-1"></i>
                        <span>
                            <span class="d-block fw-bold tx-13">{{ $n->data['title'] ?? 'Notifikasi' }}</span>
                            <span class="d-block text-muted tx-12">{{ \Illuminate\Support\Str::limit($n->data['message'] ?? '', 60) }}</span>
                            <span class="d-block text-muted" style="font-size:10px">{{ $n->created_at->diffForHumans() }}</span>
                        </span>
                    </button>
                </form>
            @empty
                <p class="text-muted text-center py-3 mb-0">Belum ada notifikasi.</p>
            @endforelse
        </div>
        <a href="{{ route('notifications.index') }}" class="dropdown-item text-center border-top py-2">Lihat semua</a>
    </div>
</li>
```

- [ ] **Step 7: Include lonceng di navbar**

In `resources/views/layouts/header.blade.php`, the navbar has `<ul class="navbar-nav">` containing one `<li class="nav-item dropdown">` (profil). Insert the bell partial as the FIRST child of that `<ul>`, immediately after the opening `<ul class="navbar-nav">` line:

```blade
        <ul class="navbar-nav">
            @include('layouts.partials.notifications')
            <li class="nav-item dropdown">
```

(Leave the rest of the profile `<li>` unchanged.)

- [ ] **Step 8: Halaman index**

Create `resources/views/notifications/index.blade.php`:

```blade
@extends('layouts.master')
@section('title', 'Notifikasi - SiMAPA')

@section('content')
@php $iconMap = ['payment' => 'credit-card', 'tagihan' => 'file-text', 'naskah' => 'book-open']; @endphp
<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="card-title mb-0">Semua Notifikasi</h6>
                @if(auth()->user()->unreadNotifications()->count() > 0)
                    <form action="{{ route('notifications.readAll') }}" method="POST" class="m-0">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-primary">Tandai semua dibaca</button>
                    </form>
                @endif
            </div>
            <div class="list-group list-group-flush">
                @forelse($notifications as $n)
                    <form action="{{ route('notifications.read', $n->id) }}" method="POST" class="m-0">
                        @csrf
                        <button type="submit"
                            class="list-group-item list-group-item-action d-flex align-items-start {{ is_null($n->read_at) ? 'bg-light' : '' }}">
                            <i data-feather="{{ $iconMap[$n->data['category'] ?? ''] ?? 'bell' }}" class="icon-sm me-3 mt-1"></i>
                            <span class="text-start">
                                <span class="d-block fw-bold">{{ $n->data['title'] ?? 'Notifikasi' }}</span>
                                <span class="d-block text-muted">{{ $n->data['message'] ?? '' }}</span>
                                <span class="d-block text-muted" style="font-size:11px">{{ $n->created_at->diffForHumans() }}</span>
                            </span>
                        </button>
                    </form>
                @empty
                    <p class="text-muted text-center py-4 mb-0">Belum ada notifikasi.</p>
                @endforelse
            </div>
            <div class="mt-3">{{ $notifications->links() }}</div>
        </div></div>
    </div>
</div>
@endsection
```

- [ ] **Step 9: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=NotificationUiTest`
Expected: PASS (4 test).

- [ ] **Step 10: Commit**

```
git add app/Http/Controllers/NotificationController.php resources/views/layouts/partials/notifications.blade.php resources/views/notifications/index.blade.php routes/web.php app/Providers/AppServiceProvider.php resources/views/layouts/header.blade.php tests/Feature/NotificationUiTest.php
git commit -m "feat(notif): navbar bell, index page, mark-as-read routes + composer

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 4: Wiring titik picu (TDD)

**Files:**
- Modify: `app/Http/Controllers/Pages/PaymentBookController.php`
- Modify: `app/Http/Controllers/Pages/TagihanController.php`
- Modify: `app/Services/TitleProgressService.php`
- Test: `tests/Feature/NotificationHooksTest.php`

- [ ] **Step 1: Tulis feature test yang gagal**

Create `tests/Feature/NotificationHooksTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Tagihan;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Models\PaymentApproval;
use App\Services\GoogleDriveService;
use App\Notifications\DatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

class NotificationHooksTest extends TestCase
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
    public function approving_payment_notifies_owner(): void
    {
        $owner = $this->user('marketing');
        $admin = $this->user('superadmin');
        $order = Order::factory()->create(['user_id' => $owner->id]);
        $payment = Payment::create(['order_id' => $order->id, 'payment_type' => 'dp', 'amount' => 500000, 'paid_at' => now(), 'status' => 'pending']);
        PaymentApproval::create(['payment_id' => $payment->id, 'status' => 'pending']);

        Notification::fake();
        $this->actingAs($admin)->post(route('payment.approve', $payment->id));

        Notification::assertSentTo($owner, DatabaseNotification::class);
    }

    /** @test */
    public function approving_tagihan_notifies_creator(): void
    {
        $creator = $this->user('marketing');
        $admin = $this->user('superadmin');
        $tagihan = Tagihan::factory()->create(['created_by' => $creator->id, 'status' => 'diajukan']);

        Notification::fake();
        $this->actingAs($admin)->post(route('tagihan.approve', $tagihan->id));

        Notification::assertSentTo($creator, DatabaseNotification::class);
    }

    /** @test */
    public function advancing_naskah_notifies_owner(): void
    {
        $owner = $this->user('marketing');
        $manager = $this->user('manager');
        $order = Order::factory()->create(['user_id' => $owner->id]);
        $detail = OrderDetail::factory()->create(['order_id' => $order->id, 'type' => 'bk_mandiri', 'title' => 'Naskah Z']);
        $progress = TitleProgress::create(['order_detail_id' => $detail->id, 'status' => 'menunggu_proses', 'assigned_role' => 'marketing', 'started_at' => now()]);

        Notification::fake();
        $this->actingAs($manager)->post(route('title.progress.update', $progress->id), ['status' => 'editing']);

        Notification::assertSentTo($owner, DatabaseNotification::class);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=NotificationHooksTest`
Expected: FAIL — `assertSentTo` gagal (notifikasi belum dikirim; wiring belum ada).

- [ ] **Step 3: Wire `PaymentBookController`**

Add import near the top use-block:

```php
use App\Services\Notifier;
```

**store():** ubah awal blok `try` agar `$payment` bisa diakses setelah transaksi. Ganti:

```php
        try {
            $invoiceId = DB::transaction(function () use ($validate, $order, $strukUrl, $emailRequested) {
                $payment = Payment::create([
```

menjadi:

```php
        try {
            $payment = null;
            $invoiceId = DB::transaction(function () use ($validate, $order, $strukUrl, $emailRequested, &$payment) {
                $payment = Payment::create([
```

dan setelah blok transaksi, ganti:

```php
            });
            return redirect()->route('order.book.create')
                ->with('success', 'Pembayaran berhasil diajukan, menunggu approval');
```

menjadi:

```php
            });
            app(Notifier::class)->paymentSubmitted($payment, Auth::user());
            return redirect()->route('order.book.create')
                ->with('success', 'Pembayaran berhasil diajukan, menunggu approval');
```

**approve():** setelah blok transaksi + dispatch email, ganti:

```php
            if (!empty($invoiceToEmail)) {
                SendInvoiceJob::dispatch($invoiceToEmail);
            }

            return redirect()->route('payment.index')->with('success', 'Pembayaran berhasil disetujui.');
```

menjadi:

```php
            if (!empty($invoiceToEmail)) {
                SendInvoiceJob::dispatch($invoiceToEmail);
            }

            app(Notifier::class)->paymentApproved($payment, Auth::user());
            return redirect()->route('payment.index')->with('success', 'Pembayaran berhasil disetujui.');
```

**reject():** pindahkan `findOrFail` keluar transaksi & notifikasi setelah commit. Ganti seluruh isi `try { ... }` (sebelum `} catch`) menjadi:

```php
        try {
            $payment = Payment::with('approval', 'order.user')->findOrFail($id);

            DB::transaction(function () use ($payment) {
                $payment->update(['status' => 'rejected']);

                $payment->approval()->update([
                    'status'      => 'rejected',
                    'note'        => 'Data tidak valid',
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                ]);

                if ($payment->order) {
                    $payment->order->update(['status' => 'pending']);
                }
            });

            app(Notifier::class)->paymentRejected($payment, Auth::user());
            return redirect()->route('payment.index')->with('warning', 'Pembayaran telah ditolak.');
```

- [ ] **Step 4: Wire `TagihanController`**

Add import near the top use-block:

```php
use App\Services\Notifier;
```

**store():** setelah blok `$id = DB::transaction(...)`, ganti:

```php
        return redirect()->route('tagihan.show', $id)->with('success', 'Tagihan dibuat & diajukan.');
```

menjadi:

```php
        app(Notifier::class)->tagihanSubmitted(Tagihan::find($id), Auth::user());
        return redirect()->route('tagihan.show', $id)->with('success', 'Tagihan dibuat & diajukan.');
```

**update():** tangkap `$resubmit` ke luar transaksi & notifikasi bila resubmit. Ganti:

```php
        $data = $this->validateData($request);
        DB::transaction(function () use ($tagihan, $data) {
            $resubmit = $tagihan->status === 'ditolak';
            $tagihan->update([...$data, 'status' => $resubmit ? 'diajukan' : $tagihan->status]);
            if ($resubmit) {
                $this->log($tagihan, 'ditolak', 'diajukan', 'Diajukan ulang setelah revisi.');
            }
        });

        return redirect()->route('tagihan.show', $tagihan->id)->with('success', 'Tagihan diperbarui.');
```

menjadi:

```php
        $data = $this->validateData($request);
        $resubmit = false;
        DB::transaction(function () use ($tagihan, $data, &$resubmit) {
            $resubmit = $tagihan->status === 'ditolak';
            $tagihan->update([...$data, 'status' => $resubmit ? 'diajukan' : $tagihan->status]);
            if ($resubmit) {
                $this->log($tagihan, 'ditolak', 'diajukan', 'Diajukan ulang setelah revisi.');
            }
        });

        if ($resubmit) {
            app(Notifier::class)->tagihanSubmitted($tagihan, Auth::user());
        }
        return redirect()->route('tagihan.show', $tagihan->id)->with('success', 'Tagihan diperbarui.');
```

**approve():** ganti:

```php
        return back()->with('success', 'Tagihan disetujui.');
```

menjadi:

```php
        app(Notifier::class)->tagihanApproved($tagihan, Auth::user());
        return back()->with('success', 'Tagihan disetujui.');
```

**reject():** ganti:

```php
        return back()->with('warning', 'Tagihan ditolak.');
```

menjadi:

```php
        app(Notifier::class)->tagihanRejected($tagihan, Auth::user());
        return back()->with('warning', 'Tagihan ditolak.');
```

- [ ] **Step 5: Wire `TitleProgressService`**

`Notifier` berada di namespace `App\Services` yang sama — cukup pakai `app(Notifier::class)` tanpa import tambahan.

**changeStatus():** ganti:

```php
        return DB::transaction(fn () => $this->applyStatus($progress, $current, $target, $actor, $note, $isCorrection));
    }
```

menjadi:

```php
        $result = DB::transaction(fn () => $this->applyStatus($progress, $current, $target, $actor, $note, $isCorrection));

        app(Notifier::class)->naskahStageChanged($result, $actor, $current, $target);
        if ($result->needs_review) {
            app(Notifier::class)->naskahNeedsReview($result, $actor);
        }

        return $result;
    }
```

**changeGroupStatus():** kumpulkan progress yang berubah, lalu notifikasi setelah commit. Ganti:

```php
        DB::transaction(function () use ($progresses, $stages, $target, $targetIdx, $actor, $note, $isCorrection) {
            foreach ($progresses as $p) {
                $idx = array_search($p->status, $stages, true);
                // Maju: hanya varian di belakang target. Koreksi: seluruh varian.
                if (($isCorrection || $idx < $targetIdx) && $p->status !== $target) {
                    $this->applyStatus($p, $p->status, $target, $actor, $note, $isCorrection);
                }
            }
        });
    }
```

menjadi:

```php
        $changed = [];
        DB::transaction(function () use ($progresses, $stages, $target, $targetIdx, $actor, $note, $isCorrection, &$changed) {
            foreach ($progresses as $p) {
                $idx = array_search($p->status, $stages, true);
                // Maju: hanya varian di belakang target. Koreksi: seluruh varian.
                if (($isCorrection || $idx < $targetIdx) && $p->status !== $target) {
                    $from = $p->status;
                    $this->applyStatus($p, $from, $target, $actor, $note, $isCorrection);
                    $changed[] = [$p, $from];
                }
            }
        });

        foreach ($changed as [$p, $from]) {
            app(Notifier::class)->naskahStageChanged($p, $actor, $from, $target);
            if ($p->needs_review) {
                app(Notifier::class)->naskahNeedsReview($p, $actor);
            }
        }
    }
```

- [ ] **Step 6: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=NotificationHooksTest`
Expected: PASS (3 test).

- [ ] **Step 7: Commit**

```
git add app/Http/Controllers/Pages/PaymentBookController.php app/Http/Controllers/Pages/TagihanController.php app/Services/TitleProgressService.php tests/Feature/NotificationHooksTest.php
git commit -m "feat(notif): fire notifications on payment/tagihan/naskah events

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 5: Verifikasi penuh

**Files:** none (verification only)

- [ ] **Step 1: Jalankan seluruh suite**

Run: `php artisan test`
Expected: PASS semua (suite 180 sebelumnya + NotifierTest 5 + NotificationUiTest 4 + NotificationHooksTest 3; total 192). Tidak ada yang merah. Test lama (TitleProgressTest, TagihanLifecycleTest, dll.) tetap hijau — Notifier dipanggil di dalamnya tetapi `send()` guard + recipient kosong/owner valid tidak menimbulkan error.

- [ ] **Step 2: Smoke manual (opsional)**

Login sebagai `superadmin` (`super`/`password`): pastikan ikon lonceng tampil di navbar. Login sebagai `ika` (marketing), buat pembayaran → login `super`, lihat lonceng ada "Pembayaran menunggu persetujuan"; approve → login `ika`, lonceng ada "Pembayaran disetujui". Klik notifikasi → diarahkan ke halaman terkait & tertandai dibaca. Buka `/notifications` → daftar + "Tandai semua dibaca".

---

## Catatan & Risiko

- Test berjalan di DB test (`avidpedi_simapa_test` via `.env.testing`); migrasi `notifications` baru ikut ter-migrate oleh `RefreshDatabase`. Di produksi, jalankan `php artisan migrate` saat rilis.
- Notifikasi dikirim **setelah** commit transaksi (bukan `DB::afterCommit`) supaya tetap terkirim & teruji di bawah `RefreshDatabase`.
- `Notifier::send` dibungkus `try/catch` + `Log::warning`; kegagalan notifikasi tidak pernah membatalkan aksi inti.
- Tidak memfilter `is_active` saat resolusi penerima (kolom tak selalu terisi; bukan kebutuhan inti).
- `App\Notifications\DatabaseNotification` (kelas pengirim) berbeda namespace dari model baris `Illuminate\Notifications\DatabaseNotification` — tidak bentrok.
