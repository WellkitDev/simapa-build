# Idempotency Menyeluruh untuk Form Mutasi — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mencegah *double-entry* pada semua form mutasi (double-click, retry jaringan, refresh/back+submit, tab ganda) lewat token idempotency server-side + guard klien, sehingga satu maksud pengguna hanya menghasilkan satu penulisan data.

**Architecture:** Middleware `EnforceIdempotency` (di grup `web`, fail-open) mengklaim token secara atomik lewat `INSERT` ke tabel ber-`unique index`; request duplikat di-*short-circuit* dengan redirect + flash `info` sebelum menyentuh controller. Klaim dilepas bila request pertama gagal (validasi/error/exception). Token disuntikkan ke tiap form oleh `public/js/idempotency.js` (global) dan, untuk form keuangan kritis, oleh directive `@idempotent` server-side sebagai lapis ke-2.

**Tech Stack:** Laravel 10, Blade, MySQL (tabel `tb_`), PHPUnit (`RefreshDatabase`, DB uji `avidpedi_simapa_test` via `.env.testing`), Spatie Permission (superadmin bypass via `Gate::before`).

**Spec:** `docs/superpowers/specs/2026-07-21-idempotency-forms-design.md`

---

## File Structure

| File | Aksi | Tanggung jawab |
|---|---|---|
| `database/migrations/2026_07_21_000001_create_tb_idempotency_keys_table.php` | Create | Skema tabel klaim (unique pada `key`) |
| `app/Models/IdempotencyKey.php` | Create | Model + akses tabel |
| `app/Http/Middleware/EnforceIdempotency.php` | Create | Klaim atomik, short-circuit duplikat, lepas klaim saat gagal |
| `app/Http/Kernel.php` | Modify | Daftarkan middleware di grup `web` |
| `app/Providers/AppServiceProvider.php` | Modify | Daftarkan Blade directive `@idempotent` |
| `public/js/idempotency.js` | Create | Auto-inject token ke form + disable tombol + header AJAX |
| `resources/views/layouts/master.blade.php` | Modify | Muat `idempotency.js` + handler `session('info')` |
| `app/Console/Commands/PruneIdempotencyKeys.php` | Create | Command hapus klaim kedaluwarsa |
| `app/Console/Kernel.php` | Modify | Jadwalkan prune harian |
| `resources/views/payments/book/create.blade.php` dan 4 form kritis lain | Modify | Tambah `@idempotent` (lapis ke-2) |
| `tests/Feature/IdempotencyKeyModelTest.php` | Create | Uji unique constraint + prune |
| `tests/Feature/EnforceIdempotencyTest.php` | Create | Uji middleware (dedupe, fail-open, rilis klaim) |
| `tests/Feature/IdempotencyIntegrationTest.php` | Create | Uji E2E lewat route nyata `accounting.entry.store` + directive |

---

## Task 1: Model & migrasi `tb_idempotency_keys`

**Files:**
- Create: `database/migrations/2026_07_21_000001_create_tb_idempotency_keys_table.php`
- Create: `app/Models/IdempotencyKey.php`
- Test: `tests/Feature/IdempotencyKeyModelTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/IdempotencyKeyModelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\IdempotencyKey;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdempotencyKeyModelTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function key_column_is_unique(): void
    {
        IdempotencyKey::create(['key' => 'dup-token', 'method' => 'POST', 'path' => 'x']);

        $this->expectException(QueryException::class);
        IdempotencyKey::create(['key' => 'dup-token', 'method' => 'POST', 'path' => 'y']);
    }

    /** @test */
    public function created_at_is_set_automatically(): void
    {
        $row = IdempotencyKey::create(['key' => 'tok-1', 'method' => 'POST', 'path' => 'x']);

        $this->assertNotNull($row->fresh()->created_at);
        $this->assertInstanceOf(\Carbon\Carbon::class, $row->fresh()->created_at);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test tests/Feature/IdempotencyKeyModelTest.php`
Expected: FAIL — "Class 'App\Models\IdempotencyKey' not found".

- [ ] **Step 3: Buat migrasi**

Buat `database/migrations/2026_07_21_000001_create_tb_idempotency_keys_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key', 191)->unique();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('method', 10);
            $table->string('path', 255);
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_idempotency_keys');
    }
};
```

- [ ] **Step 4: Buat model**

Buat `app/Models/IdempotencyKey.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    protected $table = 'tb_idempotency_keys';

    /** Baris klaim tidak pernah di-update; hanya butuh created_at (default DB). */
    public $timestamps = false;

    protected $fillable = ['key', 'user_id', 'method', 'path'];

    protected $casts = ['created_at' => 'datetime'];

    /** Klaim lebih tua dari $hours jam (untuk prune). */
    public function scopeStale($query, int $hours = 24)
    {
        return $query->where('created_at', '<', now()->subHours($hours));
    }
}
```

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `php artisan test tests/Feature/IdempotencyKeyModelTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_21_000001_create_tb_idempotency_keys_table.php app/Models/IdempotencyKey.php tests/Feature/IdempotencyKeyModelTest.php
git commit -m "feat(idempotency): model & migrasi tb_idempotency_keys"
```

---

## Task 2: Middleware `EnforceIdempotency`

**Files:**
- Create: `app/Http/Middleware/EnforceIdempotency.php`
- Modify: `app/Http/Kernel.php` (grup `web`)
- Test: `tests/Feature/EnforceIdempotencyTest.php`

Middleware ini didaftarkan di grup `web` sehingga jalan **setelah** `VerifyCsrfToken` (CSRF divalidasi dulu) dan sebelum controller. `auth()->id()` tetap teresolusi karena sesi sudah dimulai di grup `web`. Test memakai route sementara yang dibuat via facade `Route` di dalam grup `web`.

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/EnforceIdempotencyTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\CashCategory;
use App\Models\IdempotencyKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EnforceIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Route uji berada di grup `web` agar middleware EnforceIdempotency ikut jalan.
        // Sukses → buat 1 CashCategory lalu redirect. Gagal → redirect back dgn error.
        Route::middleware('web')->group(function () {
            Route::post('/__idem_ok', function () {
                CashCategory::create(['name' => 'X', 'jenis' => 'pemasukan']);
                return redirect('/origin')->with('success', 'ok');
            });
            Route::post('/__idem_fail', function () {
                return redirect('/origin')->withErrors(['x' => 'bad']);
            });
        });
    }

    /** @test */
    public function duplicate_token_is_short_circuited_and_writes_once(): void
    {
        $user = User::factory()->create();
        $token = 'tok-abc';

        $first = $this->actingAs($user)->from('/origin')
            ->post('/__idem_ok', ['_idempotency_key' => $token]);
        $first->assertRedirect('/origin');

        $second = $this->actingAs($user)->from('/origin')
            ->post('/__idem_ok', ['_idempotency_key' => $token]);
        $second->assertRedirect('/origin');
        $second->assertSessionHas('info');

        $this->assertSame(1, CashCategory::where('name', 'X')->count());
        $this->assertSame(1, IdempotencyKey::where('key', $token)->count());
    }

    /** @test */
    public function requests_without_token_are_not_deduped_fail_open(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->from('/origin')->post('/__idem_ok');
        $this->actingAs($user)->from('/origin')->post('/__idem_ok');

        $this->assertSame(2, CashCategory::where('name', 'X')->count());
        $this->assertSame(0, IdempotencyKey::count());
    }

    /** @test */
    public function failed_first_request_releases_claim_so_retry_works(): void
    {
        $user = User::factory()->create();
        $token = 'tok-retry';

        // Request pertama gagal (validasi) → klaim dilepas.
        $this->actingAs($user)->from('/origin')
            ->post('/__idem_fail', ['_idempotency_key' => $token]);
        $this->assertSame(0, IdempotencyKey::where('key', $token)->count());

        // Retry token sama ke endpoint sukses → tetap tereksekusi.
        $this->actingAs($user)->from('/origin')
            ->post('/__idem_ok', ['_idempotency_key' => $token]);
        $this->assertSame(1, CashCategory::where('name', 'X')->count());
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test tests/Feature/EnforceIdempotencyTest.php`
Expected: FAIL — middleware belum ada, sehingga request kedua tetap menulis (count = 2) dan tak ada flash `info`.

- [ ] **Step 3: Buat middleware**

Buat `app/Http/Middleware/EnforceIdempotency.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnforceIdempotency
{
    /** Method yang aman/idempoten secara HTTP — tak perlu dedupe. */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        // Fail-open: hanya bekerja bila token hadir (form field atau header AJAX).
        $token = $request->input('_idempotency_key') ?: $request->header('Idempotency-Key');
        if (! $token) {
            return $next($request);
        }
        $token = Str::limit((string) $token, 191, '');

        // Klaim atomik: unique index pada `key`. Dua request paralel → hanya satu
        // yang berhasil INSERT; yang kalah menabrak duplicate-key → short-circuit.
        try {
            IdempotencyKey::create([
                'key'     => $token,
                'user_id' => optional($request->user())->id,
                'method'  => $request->method(),
                'path'    => Str::limit($request->path(), 255, ''),
            ]);
        } catch (QueryException $e) {
            if ($this->isDuplicate($e)) {
                return redirect()->back()
                    ->with('info', 'Permintaan sudah diproses, data tidak digandakan.');
            }
            throw $e;
        }

        // Klaim tuntas hanya bila sukses: bila request pertama gagal/lempar
        // exception, lepas klaim agar user bisa submit ulang token yang sama.
        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            IdempotencyKey::where('key', $token)->delete();
            throw $e;
        }

        if ($this->isFailure($request, $response)) {
            IdempotencyKey::where('key', $token)->delete();
        }

        return $response;
    }

    private function isDuplicate(QueryException $e): bool
    {
        // SQLSTATE 23000 = integrity constraint violation (unique).
        return (string) ($e->errorInfo[0] ?? $e->getCode()) === '23000';
    }

    private function isFailure(Request $request, Response $response): bool
    {
        if ($response->getStatusCode() >= 400) {
            return true;
        }
        $session = $request->session();
        if ($session->has('errors') && optional($session->get('errors'))->any()) {
            return true;
        }
        if ($session->has('error')) {
            return true;
        }
        return false;
    }
}
```

- [ ] **Step 4: Daftarkan di grup `web`**

Di `app/Http/Kernel.php`, tambah baris terakhir grup `web` (setelah `SubstituteBindings::class`):

```php
        'web' => [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\EnforceIdempotency::class,
        ],
```

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `php artisan test tests/Feature/EnforceIdempotencyTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Middleware/EnforceIdempotency.php app/Http/Kernel.php tests/Feature/EnforceIdempotencyTest.php
git commit -m "feat(idempotency): middleware EnforceIdempotency di grup web (fail-open)"
```

---

## Task 3: Blade directive `@idempotent`

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/IdempotencyIntegrationTest.php` (metode `directive_*`)

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/IdempotencyIntegrationTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class IdempotencyIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function idempotent_directive_renders_hidden_token_field(): void
    {
        $html = Blade::render('<form>@idempotent</form>');

        $this->assertStringContainsString('name="_idempotency_key"', $html);
        $this->assertStringContainsString('type="hidden"', $html);
        // Ada value UUID yang terisi (bukan kosong).
        $this->assertMatchesRegularExpression(
            '/name="_idempotency_key"\s+value="[0-9a-f-]{36}"/',
            $html
        );
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test tests/Feature/IdempotencyIntegrationTest.php`
Expected: FAIL — directive `@idempotent` tak dikenal, HTML masih memuat teks `@idempotent`.

- [ ] **Step 3: Daftarkan directive**

Di `app/Providers/AppServiceProvider.php`, tambahkan `use Illuminate\Support\Facades\Blade;` di atas dan sisipkan di awal `boot()` (setelah baris `useBootstrapFive()`):

```php
        Blade::directive('idempotent', function () {
            return '<input type="hidden" name="_idempotency_key" value="<?php echo e(\Illuminate\Support\Str::uuid()); ?>">';
        });
```

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php artisan test tests/Feature/IdempotencyIntegrationTest.php`
Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
git add app/Providers/AppServiceProvider.php tests/Feature/IdempotencyIntegrationTest.php
git commit -m "feat(idempotency): Blade directive @idempotent (token server-side)"
```

---

## Task 4: Guard klien `idempotency.js` + wiring master

**Files:**
- Create: `public/js/idempotency.js`
- Modify: `resources/views/layouts/master.blade.php`

Tidak ada test otomatis untuk JS di repo ini; verifikasi manual di Task 7. Test feature hanya memastikan aset & handler `session('info')` ter-render (Step 4).

- [ ] **Step 1: Buat `public/js/idempotency.js`**

```javascript
/**
 * Guard idempotency sisi klien.
 * - Stempel setiap <form> non-GET dengan hidden _idempotency_key (UUID) saat DOM siap,
 *   sehingga submit apa pun (termasuk form.submit() programatik dari data-confirm) membawanya.
 * - Nonaktifkan tombol submit setelah submit dimulai (cegah double-click); re-enable saat bfcache.
 * - Tambah header Idempotency-Key ke request AJAX non-GET (jQuery & fetch).
 * Server tetap fail-open: token hanya dedupe bila hadir.
 */
(function () {
    'use strict';

    function uuid() {
        if (window.crypto && crypto.randomUUID) {
            return crypto.randomUUID();
        }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = (Math.random() * 16) | 0;
            var v = c === 'x' ? r : (r & 0x3) | 0x8;
            return v.toString(16);
        });
    }

    function isMutating(form) {
        var m = (form.getAttribute('method') || 'get').toLowerCase();
        return m !== 'get';
    }

    function stamp(form) {
        if (!isMutating(form)) return;
        if (form.querySelector('input[name="_idempotency_key"]')) return; // sudah ada (mis. @idempotent)
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = '_idempotency_key';
        input.value = uuid();
        form.appendChild(input);
    }

    function stampAll() {
        var forms = document.querySelectorAll('form');
        for (var i = 0; i < forms.length; i++) stamp(forms[i]);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', stampAll);
    } else {
        stampAll();
    }

    // Form yang ditambahkan dinamis: stempel saat submit (fallback).
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form && form.tagName === 'FORM') {
            stamp(form);
            // Nonaktifkan tombol submit setelah event ini selesai (agar nilainya tetap terkirim).
            var btns = form.querySelectorAll('button[type="submit"], input[type="submit"]');
            setTimeout(function () {
                for (var i = 0; i < btns.length; i++) btns[i].disabled = true;
            }, 0);
        }
    }, false);

    // Kembali via tombol back (bfcache): aktifkan lagi tombol submit.
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        var btns = document.querySelectorAll('button[type="submit"][disabled], input[type="submit"][disabled]');
        for (var i = 0; i < btns.length; i++) btns[i].disabled = false;
    });

    // Header untuk AJAX jQuery (non-GET).
    if (window.jQuery) {
        jQuery(document).ajaxSend(function (event, xhr, settings) {
            var m = (settings.type || 'GET').toUpperCase();
            if (m !== 'GET' && m !== 'HEAD') {
                xhr.setRequestHeader('Idempotency-Key', uuid());
            }
        });
    }

    // Header untuk fetch (non-GET).
    if (window.fetch) {
        var _fetch = window.fetch;
        window.fetch = function (input, init) {
            init = init || {};
            var m = (init.method || 'GET').toUpperCase();
            if (m !== 'GET' && m !== 'HEAD') {
                var headers = new Headers(init.headers || {});
                if (!headers.has('Idempotency-Key')) {
                    headers.set('Idempotency-Key', uuid());
                    init.headers = headers;
                }
            }
            return _fetch(input, init);
        };
    }
})();
```

- [ ] **Step 2: Muat skrip di master**

Di `resources/views/layouts/master.blade.php`, setelah baris `<script src="{{ asset('assets/js/template.js') }}"></script>` (blok "common js", sekitar baris 80), tambah:

```blade
    <!-- idempotency guard -->
    <script src="{{ asset('js/idempotency.js') }}"></script>
```

- [ ] **Step 3: Tambah handler `session('info')`**

Di `resources/views/layouts/master.blade.php`, di dalam blok `<script>` SweetAlert (sekitar baris 118-121), tambahkan fungsi `swalInfo` dan pemicu `session('info')`:

```blade
        window.swalError = function (msg) { Swal.fire({ icon: 'error', title: 'Gagal', text: msg }); };
        window.swalSuccess = function (msg) { Swal.fire({ icon: 'success', title: 'Berhasil', text: msg, timer: 2000, showConfirmButton: false }); };
        window.swalInfo = function (msg) { Swal.fire({ icon: 'info', title: 'Info', text: msg }); };
        @if(session('success')) window.swalSuccess(@json(session('success'))); @endif
        @if(session('error')) window.swalError(@json(session('error'))); @endif
        @if(session('info')) window.swalInfo(@json(session('info'))); @endif
```

- [ ] **Step 4: Tambah test bahwa aset & handler info ter-render**

Tambahkan metode ini ke `tests/Feature/IdempotencyIntegrationTest.php`:

```php
    /** @test */
    public function master_layout_loads_guard_and_handles_info_flash(): void
    {
        $user = \App\Models\User::factory()->create();
        $user->assignRole('superadmin');

        $res = $this->actingAs($user)
            ->withSession(['info' => 'Permintaan sudah diproses, data tidak digandakan.'])
            ->get(route('dashboard'));

        $res->assertOk();
        $res->assertSee('js/idempotency.js', false);
        $res->assertSee('window.swalInfo', false);
    }
```

Catatan: `superadmin` di-assign agar melewati `EnforcePermission`. Role dibuat otomatis (listener `Role::created` di TestCase menjalankan `AccessMatrixSeeder`).

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `php artisan test tests/Feature/IdempotencyIntegrationTest.php`
Expected: PASS (2 tests: directive + master layout).

- [ ] **Step 6: Commit**

```bash
git add public/js/idempotency.js resources/views/layouts/master.blade.php tests/Feature/IdempotencyIntegrationTest.php
git commit -m "feat(idempotency): guard klien idempotency.js + handler flash info"
```

---

## Task 5: Lapis ke-2 pada form kritis + uji E2E route nyata

**Files:**
- Modify: `resources/views/payments/book/create.blade.php`
- Modify: `resources/views/payments/refunds/refund_form.blade.php`
- Modify: `resources/views/accounting/journal.blade.php` (form tambah transaksi & form transfer)
- Modify: `resources/views/orders/book/create.blade.php` (form buat order)
- Test: `tests/Feature/IdempotencyIntegrationTest.php` (metode `entry_store_*`)

Untuk tiap form, tambahkan `@idempotent` tepat setelah `@csrf`. Contoh untuk `payments/book/create.blade.php` (baris 17-18):

```blade
    <form method="POST" action="{{ route('payment.store', $order->code_order) }}" enctype="multipart/form-data">
        @csrf
        @idempotent
```

- [ ] **Step 1: Tulis test E2E yang gagal (dedupe via `accounting.entry.store`)**

Route `accounting.entry.store` (`CashEntryController@store`) menulis `CashEntry` tanpa dependensi eksternal — ideal untuk uji integrasi end-to-end. Tambahkan ke `tests/Feature/IdempotencyIntegrationTest.php`:

```php
    private function superadmin(): \App\Models\User
    {
        $u = \App\Models\User::factory()->create();
        $u->assignRole('superadmin');
        return $u;
    }

    private function entryPayload(string $token = null): array
    {
        $p = [
            'tanggal'    => now()->toDateString(),
            'jenis'      => 'pengeluaran',
            'amount'     => 50000,
            'keterangan' => 'Uji idempotency',
        ];
        if ($token !== null) $p['_idempotency_key'] = $token;
        return $p;
    }

    /** @test */
    public function entry_store_dedupes_double_submit_via_real_route(): void
    {
        $sa = $this->superadmin();
        $token = 'entry-tok-1';

        $this->actingAs($sa)->from(route('accounting.journal'))
            ->post(route('accounting.entry.store'), $this->entryPayload($token));
        $this->actingAs($sa)->from(route('accounting.journal'))
            ->post(route('accounting.entry.store'), $this->entryPayload($token))
            ->assertSessionHas('info');

        $this->assertSame(1, \App\Models\CashEntry::where('keterangan', 'Uji idempotency')->count());
    }

    /** @test */
    public function entry_store_without_token_is_not_deduped(): void
    {
        $sa = $this->superadmin();

        $this->actingAs($sa)->from(route('accounting.journal'))
            ->post(route('accounting.entry.store'), $this->entryPayload());
        $this->actingAs($sa)->from(route('accounting.journal'))
            ->post(route('accounting.entry.store'), $this->entryPayload());

        $this->assertSame(2, \App\Models\CashEntry::where('keterangan', 'Uji idempotency')->count());
    }
```

- [ ] **Step 2: Jalankan test, pastikan status awal**

Run: `php artisan test tests/Feature/IdempotencyIntegrationTest.php`
Expected: `entry_store_dedupes_*` sudah **PASS** (middleware dari Task 2 sudah aktif di grup web) — ini mengonfirmasi integrasi route nyata. `entry_store_without_token_*` juga PASS. Jika `dedupes` gagal (count = 2), berarti middleware belum aktif di path ini — investigasi urutan middleware.

- [ ] **Step 3: Tambah `@idempotent` ke 5 form kritis**

Di masing-masing view berikut, sisipkan `@idempotent` tepat setelah `@csrf`:
- `resources/views/payments/book/create.blade.php` (form `payment.store`)
- `resources/views/payments/refunds/refund_form.blade.php` (form `refund.store`)
- `resources/views/accounting/journal.blade.php` — dua form: tambah transaksi (`accounting.entry.store`) dan transfer (`accounting.transfer.store`)
- `resources/views/orders/book/create.blade.php` (form `order.book.store`)

Verifikasi tiap form kini memuat `@csrf` diikuti `@idempotent`. (Path di atas sudah dikonfirmasi ada di repo per 2026-07-21.)

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php artisan test tests/Feature/IdempotencyIntegrationTest.php`
Expected: PASS (semua metode: directive, master layout, entry dedupe, entry no-token).

- [ ] **Step 5: Commit**

```bash
git add resources/views/payments/book/create.blade.php resources/views/payments/refunds/refund_form.blade.php resources/views/accounting/journal.blade.php resources/views/orders/book/create.blade.php tests/Feature/IdempotencyIntegrationTest.php
git commit -m "feat(idempotency): @idempotent pada form kritis + uji E2E accounting.entry"
```

---

## Task 6: Command prune + penjadwalan

**Files:**
- Create: `app/Console/Commands/PruneIdempotencyKeys.php`
- Modify: `app/Console/Kernel.php`
- Test: `tests/Feature/IdempotencyKeyModelTest.php` (metode `prune_*`)

- [ ] **Step 1: Tulis test yang gagal**

Tambahkan ke `tests/Feature/IdempotencyKeyModelTest.php`:

```php
    /** @test */
    public function prune_command_deletes_keys_older_than_24_hours(): void
    {
        $old = IdempotencyKey::create(['key' => 'old', 'method' => 'POST', 'path' => 'x']);
        $old->forceFill(['created_at' => now()->subHours(25)])->save();

        IdempotencyKey::create(['key' => 'fresh', 'method' => 'POST', 'path' => 'x']); // created_at = now

        $this->artisan('idempotency:prune')->assertExitCode(0);

        $this->assertNull(IdempotencyKey::where('key', 'old')->first());
        $this->assertNotNull(IdempotencyKey::where('key', 'fresh')->first());
    }
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test tests/Feature/IdempotencyKeyModelTest.php`
Expected: FAIL — "Command 'idempotency:prune' is not defined".

- [ ] **Step 3: Buat command**

Buat `app/Console/Commands/PruneIdempotencyKeys.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\IdempotencyKey;
use Illuminate\Console\Command;

class PruneIdempotencyKeys extends Command
{
    protected $signature = 'idempotency:prune {--hours=24 : Umur maksimum klaim dalam jam}';

    protected $description = 'Hapus idempotency key yang lebih tua dari N jam (default 24).';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $deleted = IdempotencyKey::stale($hours)->delete();
        $this->info("Menghapus {$deleted} idempotency key kedaluwarsa (> {$hours} jam).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Jadwalkan harian**

Di `app/Console/Kernel.php`, di dalam `schedule()`, ganti komentar contoh dengan:

```php
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('idempotency:prune')->daily();
    }
```

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `php artisan test tests/Feature/IdempotencyKeyModelTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/PruneIdempotencyKeys.php app/Console/Kernel.php tests/Feature/IdempotencyKeyModelTest.php
git commit -m "feat(idempotency): command idempotency:prune + jadwal harian"
```

---

## Task 7: Verifikasi menyeluruh + migrasi DB dev

**Files:** — (tidak ada perubahan kode; langkah verifikasi)

- [ ] **Step 1: Jalankan seluruh suite**

Run: `php artisan test`
Expected: PASS semua (593+ test lama tetap hijau + test idempotency baru). Bila ada test lama yang menabrak double-submit sengaja/AJAX yang kini kena, tinjau — mestinya tidak, karena fail-open dan test lama tidak mengirim `_idempotency_key`/header.

- [ ] **Step 2: Migrasi DB dev (bukan hanya DB test)**

Aplikasi live membaca DB dev `avidpedi_simapa`. Tanpa migrasi, request apa pun ber-token akan 500 karena tabel hilang.

Run: `php artisan migrate`
Expected: "Migrating: 2026_07_21_000001_create_tb_idempotency_keys_table" → "Migrated".

- [ ] **Step 3: Verifikasi manual di browser (double-submit)**

1. Login sebagai superadmin, buka Akuntansi → Jurnal Kas, isi form tambah transaksi.
2. Klik "Simpan" lalu segera klik lagi cepat (double-click) → tombol harus non-aktif setelah klik pertama; hanya **1 baris** transaksi terbentuk.
3. Cek View Source form: harus ada `<input type="hidden" name="_idempotency_key" ...>`.
4. Submit form, lalu tekan tombol "Kembali" browser dan submit lagi → muncul SweetAlert info "Permintaan sudah diproses, data tidak digandakan." dan tidak ada baris ganda.

- [ ] **Step 4: Commit (bila ada penyesuaian)**

Jika Step 1-3 memaksa penyesuaian kecil, commit terpisah. Jika bersih, tidak ada commit tambahan.

---

## Catatan operasional & kepatuhan repo

- **Atribusi commit:** author `WellkitDev <rahmatpurnomo808@gmail.com>`, co-author `Mira <admin@avidpedia.com>` — jangan "Claude"/Anthropic. Contoh:
  ```bash
  git -c user.name="WellkitDev" -c user.email="rahmatpurnomo808@gmail.com" \
    commit -m "$(printf 'feat(idempotency): ...\n\nCo-Authored-By: Mira <admin@avidpedia.com>')"
  ```
- **git add eksplisit** — jangan `git add -A`/`.` (ada berkas lokal yang tak boleh di-commit: `avidpedi_simapa.sql`, `template-web/`, `.gitignore`, dll).
- **Testing** memakai `.env.testing` → `avidpedi_simapa_test`; jangan sentuh DB asli. Tanpa `Payment` factory (bangun `Payment` langsung bila perlu).
- **Sudah dipertimbangkan (bukan bug):**
  - Fail-open disengaja — endpoint AJAX/board lama tanpa token tetap jalan.
  - Header `Idempotency-Key` AJAX memakai UUID per-panggilan (plumbing maju; belum men-dedupe retry AJAX karena kunci berbeda tiap kirim). Risiko double-entry AJAX rendah & sebagian idempoten alami; tidak diperluas (YAGNI).
  - Heuristik `isFailure` sengaja "melepas lebih longgar" (flash `error` melepas klaim) agar retry sah tidak terblokir.
