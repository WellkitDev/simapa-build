# Finalisasi & Retensi Papan Manuskrip Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Kunci naskah final (`terbit`/`publish`) dari perubahan kecuali oleh superadmin, dan hilangkan naskah final dari papan setelah 30 hari (tetap di arsip).

**Architecture:** (C) tambah cek `TitleProgress::isFinal($current)` di dua guard otorisasi (`TitleProgressService::authorizeChange` artikel, `ChapterManuscriptService::authorize` buku/bab) — superadmin lolos, lainnya ditolak. (D) filter query papan (`ManuscriptTrackerController::index` `$details`) mengecualikan final yang `started_at`-nya >30 hari. UI (`card.blade.php`) mencerminkan kunci. (B) sudah ada via `needs_review` — tanpa kode.

**Tech Stack:** Laravel 11, Eloquent, Blade + SortableJS. Test: PHPUnit feature via `.env.testing`, `GoogleDriveService` di-mock. Tanpa migrasi.

---

## File Structure

- `app/Models/TitleProgress.php` (**modify**) — konstanta `BOARD_RETENTION_DAYS`.
- `app/Services/TitleProgressService.php` (**modify**) — `authorizeChange` + kunci final.
- `app/Services/ChapterManuscriptService.php` (**modify**) — `authorize` restruktur + kunci final.
- `app/Http/Controllers/Pages/ManuscriptTrackerController.php` (**modify**) — filter retensi di `$details`.
- `resources/views/manuscript/partials/card.blade.php` (**modify**) — `data-no-drag` final, sembunyi aksi bab final, badge "🔒 Final".
- `tests/Feature/ManuscriptFinalizeTest.php` (**create**).

---

## Konteks untuk implementer

- `TitleProgress::FINAL_STAGES = ['terbit','publish']`; `TitleProgress::isFinal($s): bool`; `getHandlerForStatus($s)`. `BOOK_STAGES` (…,isbn,cetak,terbit), `ARTICLE_STAGES` (…,submit,loa,publish).
- Artikel dipindah lewat `POST manuscript.move` (name `manuscript.move`) → `TitleProgressService::changeGroupStatus` → `authorizeChange($canonical)`. Bab lewat `POST chapter.advance` → `ChapterManuscriptService::changeStatus` → `authorize($current)`.
- Papan dimuat `ManuscriptTrackerController::index`; kartu dibangun dari query `$details` (baris ~61). `TitleProgress.started_at` di-set `now()` tiap ganti status (jadi = waktu mencapai tahap kini).
- Fixture pola dari `tests/Feature/ChapterProgressControllerTest.php` (Order+OrderDetail+TitleProgress; `ensureChapters` untuk bab).
- Board middleware: `role:superadmin|manager|production`. Manager scope default `all`.

---

### Task 1: (C) Kunci final di server + konstanta retensi

**Files:**
- Modify: `app/Models/TitleProgress.php`, `app/Services/TitleProgressService.php`, `app/Services/ChapterManuscriptService.php`
- Create: `tests/Feature/ManuscriptFinalizeTest.php`

- [ ] **Step 1: Tulis feature test kunci final (gagal dulu)**

`tests/Feature/ManuscriptFinalizeTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Models\ChapterProgress;
use App\Services\ChapterManuscriptService;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class ManuscriptFinalizeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
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

    private function articleAt(string $status, ?\Carbon\Carbon $startedAt = null): TitleProgress
    {
        $owner = $this->user('production');
        $title = Title::create(['title' => 'Artikel ' . uniqid(), 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $order = Order::create(['code_order' => 'ORD-FA-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
        $detail = OrderDetail::create(['order_id' => $order->id, 'title_id' => $title->id, 'type' => 'at_mandiri', 'title' => $title->title, 'slug' => 'a-' . uniqid(), 'chapters' => 0, 'cost_amount' => 0, 'naskah_type' => 'mandiri', 'publication_type' => 'regular']);
        return TitleProgress::create(['order_detail_id' => $detail->id, 'status' => $status, 'assigned_role' => TitleProgress::getHandlerForStatus($status), 'started_at' => $startedAt ?? now()]);
    }

    private function bookChapterAt(string $status): ChapterProgress
    {
        $owner = $this->user('production');
        $book = Title::create(['title' => 'Buku ' . uniqid(), 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $order = Order::create(['code_order' => 'ORD-FB-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
        $detail = OrderDetail::create(['order_id' => $order->id, 'title_id' => $book->id, 'type' => 'bk_mandiri', 'title' => $book->title, 'slug' => 'b-' . uniqid(), 'chapters' => 1, 'cost_amount' => 0, 'naskah_type' => 'mandiri', 'publication_type' => 'regular']);
        TitleProgress::create(['order_detail_id' => $detail->id, 'status' => 'editing', 'assigned_role' => 'production', 'started_at' => now()]);
        app(ChapterManuscriptService::class)->ensureChapters($book);
        $cp = $book->chapters()->with('progress')->first()->progress;
        $cp->update(['status' => $status]);
        return $cp;
    }

    /** @test */
    public function manager_cannot_change_final_article_but_superadmin_can(): void
    {
        $tp = $this->articleAt('publish');
        $this->actingAs($this->user('manager'))
            ->postJson(route('manuscript.move', $tp->id), ['status' => 'loa', 'note' => 'koreksi'])
            ->assertStatus(403);

        $tp2 = $this->articleAt('publish');
        $this->actingAs($this->user('superadmin'))
            ->postJson(route('manuscript.move', $tp2->id), ['status' => 'loa', 'note' => 'koreksi'])
            ->assertOk();
        $this->assertSame('loa', $tp2->fresh()->status);
    }

    /** @test */
    public function production_and_manager_cannot_change_final_chapter_but_superadmin_can(): void
    {
        foreach (['production', 'manager'] as $role) {
            $cp = $this->bookChapterAt('terbit');
            $this->actingAs($this->user($role))
                ->postJson(route('chapter.advance', $cp->id), ['status' => 'cetak', 'note' => 'koreksi'])
                ->assertStatus(403);
            $this->assertSame('terbit', $cp->fresh()->status);
        }

        $cp = $this->bookChapterAt('terbit');
        $this->actingAs($this->user('superadmin'))
            ->postJson(route('chapter.advance', $cp->id), ['status' => 'cetak', 'note' => 'koreksi'])
            ->assertOk();
        $this->assertSame('cetak', $cp->fresh()->status);
    }
}
```

- [ ] **Step 2: Jalankan — diharapkan GAGAL (belum ada kunci final)**

Run: `php artisan test --env=testing tests/Feature/ManuscriptFinalizeTest.php`
Expected: FAIL — manager/production TIDAK ditolak (dapat 200, bukan 403).

- [ ] **Step 3: Konstanta di `app/Models/TitleProgress.php`** — setelah `const FINAL_STAGES = ['terbit', 'publish'];` (baris ~56) tambahkan:

```php
    const BOARD_RETENTION_DAYS = 30;
```

- [ ] **Step 4: Kunci final di `app/Services/TitleProgressService.php`** — ganti method `authorizeChange`:

```php
    private function authorizeChange(User $actor, string $current): void
    {
        if ($actor->hasRole('superadmin')) {
            return; // bebas: maju, mundur, lompat
        }
        if (TitleProgress::isFinal($current)) {
            throw new AuthorizationException('Naskah sudah final dan terkunci.');
        }
        if ($actor->hasRole('manager')) {
            return; // oversight: stage apa pun (non-final)
        }
        if ($actor->hasRole('production') && TitleProgress::getHandlerForStatus($current) === 'production') {
            return; // hanya kartu yang sedang jadi domain production
        }
        throw new AuthorizationException('Anda tidak berhak memindahkan naskah pada tahap ini.');
    }
```

- [ ] **Step 5: Kunci final di `app/Services/ChapterManuscriptService.php`** — ganti method `authorize`:

```php
    private function authorize(User $actor, string $current): void
    {
        if ($actor->hasRole('superadmin')) {
            return;
        }
        if (TitleProgress::isFinal($current)) {
            throw new AuthorizationException('Bab sudah final dan terkunci.');
        }
        if ($actor->hasRole('manager')) {
            return;
        }
        if ($actor->hasRole('production') && TitleProgress::getHandlerForStatus($current) === 'production') {
            return;
        }
        throw new AuthorizationException('Anda tidak berhak memindahkan bab pada tahap ini.');
    }
```

- [ ] **Step 6: Jalankan — diharapkan PASS**

Run: `php artisan test --env=testing tests/Feature/ManuscriptFinalizeTest.php`
Expected: 2 passed.

- [ ] **Step 7: Commit**

```bash
git add app/Models/TitleProgress.php app/Services/TitleProgressService.php app/Services/ChapterManuscriptService.php tests/Feature/ManuscriptFinalizeTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(manuscript): kunci naskah final (terbit/publish) kecuali superadmin

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 2: (D) Retensi 30 hari di query papan

**Files:**
- Modify: `app/Http/Controllers/Pages/ManuscriptTrackerController.php`
- Modify: `tests/Feature/ManuscriptFinalizeTest.php`

- [ ] **Step 1: Tambah test retensi (gagal dulu)**

Tambahkan di `ManuscriptFinalizeTest` (sebelum kurung tutup kelas):

```php
    /** @test */
    public function final_older_than_30_days_drops_off_board_recent_stays(): void
    {
        $old    = $this->articleAt('publish', now()->subDays(31));
        $recent = $this->articleAt('publish', now()->subDays(29));

        $res = $this->actingAs($this->user('manager'))->get(route('manuscript.board', ['tipe' => 'artikel']))->assertOk();
        $res->assertDontSee($old->orderDetail->title);
        $res->assertSee($recent->orderDetail->title);
    }

    /** @test */
    public function non_final_old_manuscript_stays_on_board(): void
    {
        $editing = $this->articleAt('editing', now()->subDays(60));
        $this->actingAs($this->user('manager'))->get(route('manuscript.board', ['tipe' => 'artikel']))
            ->assertOk()->assertSee($editing->orderDetail->title);
    }
```

- [ ] **Step 2: Jalankan — diharapkan GAGAL**

Run: `php artisan test --env=testing tests/Feature/ManuscriptFinalizeTest.php::final_older_than_30_days_drops_off_board_recent_stays`
Expected: FAIL — naskah final 31 hari masih tampil (retensi belum ada).

- [ ] **Step 3: Filter retensi di `ManuscriptTrackerController::index`** — pada query `$details`, tepat setelah baris `->whereHas('titleProgress')` (baris ~63), sisipkan klausa berikut sebelum `->where($typeFilter)`:

```php
            ->whereHas('titleProgress', function ($t) {
                $t->where(function ($q) {
                    $q->whereNotIn('status', TitleProgress::FINAL_STAGES)
                      ->orWhere('started_at', '>=', now()->subDays(TitleProgress::BOARD_RETENTION_DAYS));
                });
            })
```

Sehingga awal query menjadi:

```php
        $details = OrderDetail::query()
            ->with([...])
            ->whereHas('titleProgress')
            ->whereHas('titleProgress', function ($t) {
                $t->where(function ($q) {
                    $q->whereNotIn('status', TitleProgress::FINAL_STAGES)
                      ->orWhere('started_at', '>=', now()->subDays(TitleProgress::BOARD_RETENTION_DAYS));
                });
            })
            ->where($typeFilter)
            ->when(...)
            ...
            ->get();
```

- [ ] **Step 4: Jalankan — diharapkan PASS**

Run: `php artisan test --env=testing tests/Feature/ManuscriptFinalizeTest.php`
Expected: 4 passed.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Pages/ManuscriptTrackerController.php tests/Feature/ManuscriptFinalizeTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(manuscript): retensi 30 hari — naskah final lepas dari papan (tetap di arsip)

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 3: (C) UI kunci final di kartu papan

**Files:**
- Modify: `resources/views/manuscript/partials/card.blade.php`
- Modify: `tests/Feature/ManuscriptFinalizeTest.php`

- [ ] **Step 1: Tambah test UI (gagal dulu)**

Tambahkan di `ManuscriptFinalizeTest`:

```php
    /** @test */
    public function final_article_card_is_not_draggable_for_manager_only(): void
    {
        $this->articleAt('publish'); // started_at now → tetap di papan

        $this->actingAs($this->user('manager'))->get(route('manuscript.board', ['tipe' => 'artikel']))
            ->assertOk()->assertSee('data-no-drag', false);

        $this->actingAs($this->user('superadmin'))->get(route('manuscript.board', ['tipe' => 'artikel']))
            ->assertOk()->assertDontSee('data-no-drag', false);
    }
```

- [ ] **Step 2: Jalankan — diharapkan GAGAL**

Run: `php artisan test --env=testing tests/Feature/ManuscriptFinalizeTest.php::final_article_card_is_not_draggable_for_manager_only`
Expected: FAIL — artikel final belum `data-no-drag` (papan artikel tak punya sumber data-no-drag lain).

- [ ] **Step 3: Hitung `$finalLocked` + kunci kartu di `resources/views/manuscript/partials/card.blade.php`**

Pada blok `@php … @endphp` di atas (setelah baris `$chapters = …;`), tambahkan:

```php
    $finalLocked = \App\Models\TitleProgress::isFinal($p->status) && ! auth()->user()->hasRole('superadmin');
```

Ubah pembuka kartu (baris `<div class="card mb-2 mt-card" …>`) dari:

```blade
<div class="card mb-2 mt-card" data-id="{{ $p->id }}" data-status="{{ $p->status }}" @if($isBook) data-no-drag @endif>
```

menjadi:

```blade
<div class="card mb-2 mt-card" data-id="{{ $p->id }}" data-status="{{ $p->status }}" @if($isBook || $finalLocked) data-no-drag @endif>
```

- [ ] **Step 4: Badge "🔒 Final" + sembunyikan "Ubah…" bab final**

Di area badge kartu (dalam `<div class="d-flex gap-1">`, dekat badge `⚑ tinjau`), tambahkan setelah blok `@if($p->needs_review) … @endif`:

```blade
                @if(\App\Models\TitleProgress::isFinal($p->status))
                    <span class="badge bg-success" title="Naskah final — terkunci">🔒 Final</span>
                @endif
```

Pada panel bab, tombol "Ubah…" (`data-chapter-edit`) — bungkus agar tersembunyi saat bab final & non-superadmin. Ganti baris tombol:

```blade
                                <button type="button" class="btn btn-xs btn-outline-secondary py-0"
                                        data-chapter-edit data-cp="{{ $cp->id }}"
                                        data-current="{{ $cstatus }}" data-next="{{ $cnext ?? '' }}"
                                        data-judul="{{ $ch->urutan }}. {{ $ch->judul }}"
                                        style="font-size:10px">Ubah…</button>
```

menjadi:

```blade
                                @unless(\App\Models\TitleProgress::isFinal($cstatus) && ! auth()->user()->hasRole('superadmin'))
                                <button type="button" class="btn btn-xs btn-outline-secondary py-0"
                                        data-chapter-edit data-cp="{{ $cp->id }}"
                                        data-current="{{ $cstatus }}" data-next="{{ $cnext ?? '' }}"
                                        data-judul="{{ $ch->urutan }}. {{ $ch->judul }}"
                                        style="font-size:10px">Ubah…</button>
                                @endunless
```

(Tombol "Maju → next" bab sudah otomatis tersembunyi saat final karena `$cnext` null.)

- [ ] **Step 5: Jalankan test + view:cache**

Run: `php artisan test --env=testing tests/Feature/ManuscriptFinalizeTest.php`
Expected: 5 passed.
Run: `php artisan view:cache && php artisan view:clear`
Expected: sukses tanpa error.

- [ ] **Step 6: Commit**

```bash
git add resources/views/manuscript/partials/card.blade.php tests/Feature/ManuscriptFinalizeTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(manuscript): UI kunci final — kartu non-draggable + sembunyi aksi + badge 🔒 Final

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 4: Verifikasi menyeluruh

- [ ] **Step 1: Seluruh suite**

Run: `php artisan test --env=testing`
Expected: semua PASS (baseline 408 + 5 test baru = 413 passed). Perhatikan khusus regresi `ChapterProgressControllerTest`, `ChapterStageJumpTest`, `ManuscriptTrackerTest`, `TitleProgressTest` — pastikan tak ada fixture yang memindahkan naskah **dari** tahap final oleh non-superadmin (bila ada yang gagal karena kunci final, itu penemuan sah: sesuaikan fixture ke tahap non-final atau aktor superadmin).

- [ ] **Step 2: Kompilasi view bersih**

Run: `php artisan view:cache && php artisan view:clear`
Expected: sukses tanpa error.

---

## Self-Review (penulis plan)

**1. Spec coverage:**
- §1.1 (C) kunci final server + superadmin escape → Task 1 (dua guard) + test `manager_cannot_change_final_article…`, `production_and_manager_cannot_change_final_chapter…`. ✓
- §1.1 (C) UI cegah drag/aksi → Task 3 (data-no-drag + sembunyi Ubah… + badge) + test `final_article_card_is_not_draggable_for_manager_only`. ✓
- §1.2 (D) retensi 30 hari papan, tetap di arsip → Task 2 filter `$details` + test `final_older_than_30_days…`, `non_final_old…`. ✓
- §1.3 (B) existing needs_review → didokumentasikan, tanpa kode. ✓
- §2 server (dua service) → Task 1. §3 UI → Task 3. §4 query → Task 2. §5 test → semua task. ✓

**2. Placeholder scan:** tak ada TBD/TODO; semua langkah berisi kode/perintah nyata.

**3. Type/nama konsistensi:** `TitleProgress::isFinal`/`FINAL_STAGES`/`BOARD_RETENTION_DAYS`/`getHandlerForStatus` konsisten model↔service↔controller↔view↔test. `authorizeChange`(artikel)/`authorize`(bab) sama-sama sisip cek final setelah bypass superadmin. `$finalLocked` di card.blade dipakai pada `data-no-drag`. Rute `manuscript.move`/`chapter.advance`/`manuscript.board` konsisten test. Tanpa migrasi.
