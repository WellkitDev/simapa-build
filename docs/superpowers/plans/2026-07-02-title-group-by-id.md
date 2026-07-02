# Title Directory Fase 2b-2 (Manuscript Group Key = title_id) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Jadikan `title_id` identitas grup manuskrip: `group_key` di-back `title_id` (`"title:{id}"`) bila ada, else turunan lama. Backfill `title_id` order lama. Kode grouping lain tak disentuh.

**Architecture:** Ubah *sumber* `group_key` di 2 titik (`OrderDetail` saving-hook + `TitleArchiveService::groupKey`). `TitleBackfillService` + migrasi data menaut order lama ke Title. Semua konsumen `group_key` (ManuscriptTracker, TitleProgressService, dashboard, Arsip) mengikuti otomatis.

**Tech Stack:** Laravel 11, Eloquent.

**Spec:** `docs/superpowers/specs/2026-07-02-title-group-by-id-design.md`

**Catatan env:** Tests `.env.testing` + `RefreshDatabase`; mock `GoogleDriveService`. DB mati → `Start-Process -FilePath "C:\xampp\mysql\bin\mysqld.exe" -ArgumentList '--defaults-file=C:\xampp\mysql\bin\my.ini' -WindowStyle Hidden` (PowerShell), tunggu ~6 dtk. Commit: `git add <path eksplisit>` + `Co-authored-by: Mira <admin@avidpedia.com>`, TANPA "Claude"/"Anthropic", jangan `git add .`. Migrasi terakhir: `2026_07_02_000009`; backfill baru = `2026_07_02_000010`. Setelah selesai: `php artisan migrate` di dev (menjalankan backfill).

**Fakta:** `OrderDetailFactory` set `type`/`title` tapi TIDAK `title_id` (→ fallback). `TitleArchiveService::groupKeyFor(type,title)` = `pipelineClass(type).'|'.normalizeTitle(title)` (`bk_*`→'buku', lainnya 'artikel'); `pipelineClass`/`groupKeyFor` public. `Title` fillable: title, code, jenis, indeksasi, tipe_naskah, scope_id, status, asal, created_by, ... `TitleCodeService::generate(string)`.

---

## Task 1: `group_key` source = title_id (TDD)

**Files:**
- Modify: `app/Models/OrderDetail.php` (saving hook), `app/Services/TitleArchiveService.php` (`groupKey`)
- Test: `tests/Unit/GroupKeyTitleIdTest.php`

- [ ] **Step 1: Write the failing test** — create `tests/Unit/GroupKeyTitleIdTest.php`:

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Title;
use App\Models\OrderDetail;
use App\Services\TitleArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class GroupKeyTitleIdTest extends TestCase
{
    use RefreshDatabase;

    private function title(string $jenis = 'buku'): Title
    {
        return Title::create(['title' => 'Judul X', 'jenis' => $jenis, 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
    }

    /** @test */
    public function detail_with_title_id_uses_title_prefixed_group_key(): void
    {
        $title = $this->title();
        $detail = OrderDetail::factory()->create(['title_id' => $title->id, 'type' => 'bk_mandiri', 'title' => 'Apa Saja']);

        $this->assertSame('title:' . $title->id, $detail->fresh()->group_key);
    }

    /** @test */
    public function same_title_id_different_title_string_share_group_key(): void
    {
        $title = $this->title();
        $a = OrderDetail::factory()->create(['title_id' => $title->id, 'type' => 'bk_mandiri', 'title' => 'Judul A']);
        $b = OrderDetail::factory()->create(['title_id' => $title->id, 'type' => 'bk_kolab', 'title' => 'Judul B Berbeda']);

        $this->assertSame($a->fresh()->group_key, $b->fresh()->group_key);
    }

    /** @test */
    public function detail_without_title_id_falls_back_to_derived(): void
    {
        $d = OrderDetail::factory()->create(['title_id' => null, 'type' => 'bk_mandiri', 'title' => 'Hello World']);
        $this->assertSame('buku|hello world', $d->fresh()->group_key);
    }

    /** @test */
    public function archive_group_key_uses_title_id_when_present(): void
    {
        $title = $this->title();
        $d = OrderDetail::factory()->create(['title_id' => $title->id, 'type' => 'bk_mandiri', 'title' => 'Z']);
        $this->assertSame('title:' . $title->id, (new TitleArchiveService())->groupKey($d));
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=GroupKeyTitleIdTest`
Expected: FAIL (group_key still derived even when title_id set).

- [ ] **Step 3: OrderDetail saving hook**

In `app/Models/OrderDetail.php`, replace the `booted()` saving closure body:
```php
        static::saving(function (OrderDetail $detail) {
            if ($detail->type !== null && $detail->title !== null) {
                $detail->group_key = (new \App\Services\TitleArchiveService())
                    ->groupKeyFor($detail->type, $detail->title);
            }
        });
```
with:
```php
        static::saving(function (OrderDetail $detail) {
            // Kunci grup = identitas Title bila tertaut; jika tidak, turunan (type + judul).
            if ($detail->title_id !== null) {
                $detail->group_key = 'title:' . $detail->title_id;
            } elseif ($detail->type !== null && $detail->title !== null) {
                $detail->group_key = (new \App\Services\TitleArchiveService())
                    ->groupKeyFor($detail->type, $detail->title);
            }
        });
```

- [ ] **Step 4: TitleArchiveService::groupKey**

In `app/Services/TitleArchiveService.php`, replace:
```php
    public function groupKey(OrderDetail $detail): string
    {
        return $this->groupKeyFor($detail->type, $detail->title);
    }
```
with:
```php
    public function groupKey(OrderDetail $detail): string
    {
        return $detail->title_id !== null
            ? 'title:' . $detail->title_id
            : $this->groupKeyFor($detail->type, $detail->title);
    }
```
(Leave `groupKeyFor()` unchanged — used by the fallback and the backfill.)

- [ ] **Step 5: Run, confirm PASS**

Run: `php artisan test --filter=GroupKeyTitleIdTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```
git add app/Models/OrderDetail.php app/Services/TitleArchiveService.php tests/Unit/GroupKeyTitleIdTest.php
git commit -m "feat(title-group): group_key backed by title_id (fallback to derived)

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 2: `TitleBackfillService` + backfill migration (TDD)

**Files:**
- Create: `app/Services/TitleBackfillService.php`, `database/migrations/2026_07_02_000010_backfill_order_title_id.php`
- Test: `tests/Unit/TitleBackfillServiceTest.php`

- [ ] **Step 1: Write the failing test** — create `tests/Unit/TitleBackfillServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Title;
use App\Models\OrderDetail;
use App\Services\TitleBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TitleBackfillServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function backfill_links_old_orders_of_same_title_to_one_title(): void
    {
        $d1 = OrderDetail::factory()->create(['title_id' => null, 'type' => 'bk_mandiri', 'title' => 'Buku Lama']);
        $d2 = OrderDetail::factory()->create(['title_id' => null, 'type' => 'bk_kolab', 'title' => 'buku lama']); // ternormalisasi sama, se-pipeline

        $n = (new TitleBackfillService())->run();

        $this->assertSame(2, $n);
        $this->assertNotNull($d1->fresh()->title_id);
        $this->assertSame($d1->fresh()->title_id, $d2->fresh()->title_id);

        $title = Title::find($d1->fresh()->title_id);
        $this->assertSame('order', $title->asal);
        $this->assertSame('disetujui', $title->status);
        $this->assertSame('buku', $title->jenis);
        $this->assertNotNull($title->code);
        $this->assertSame('title:' . $title->id, $d1->fresh()->group_key);
    }

    /** @test */
    public function backfill_separates_different_pipelines(): void
    {
        $buku = OrderDetail::factory()->create(['title_id' => null, 'type' => 'bk_mandiri', 'title' => 'Sama Judul']);
        $art  = OrderDetail::factory()->create(['title_id' => null, 'type' => 'at_mandiri', 'title' => 'Sama Judul']);

        (new TitleBackfillService())->run();

        $this->assertNotSame($buku->fresh()->title_id, $art->fresh()->title_id);
        $this->assertSame('artikel', Title::find($art->fresh()->title_id)->jenis);
    }

    /** @test */
    public function backfill_reuses_existing_matching_title(): void
    {
        $existing = Title::create(['title' => 'Judul Ada', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $d = OrderDetail::factory()->create(['title_id' => null, 'type' => 'bk_mandiri', 'title' => 'Judul Ada']);

        (new TitleBackfillService())->run();

        $this->assertSame($existing->id, $d->fresh()->title_id);
        $this->assertSame(1, Title::where('title', 'Judul Ada')->count());
    }

    /** @test */
    public function backfill_ignores_details_that_already_have_title_id(): void
    {
        $title = Title::create(['title' => 'Sudah', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        OrderDetail::factory()->create(['title_id' => $title->id, 'type' => 'bk_mandiri', 'title' => 'Sudah']);

        $this->assertSame(0, (new TitleBackfillService())->run());
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=TitleBackfillServiceTest`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement — create `app/Services/TitleBackfillService.php`**

```php
<?php

namespace App\Services;

use App\Models\OrderDetail;
use App\Models\Title;

class TitleBackfillService
{
    /** Tautkan order lama (title_id=null) ke Title (dibuat/ditemukan). Kembalikan jumlah detail ter-backfill. */
    public function run(): int
    {
        $archive = new TitleArchiveService();
        $codeSvc = new TitleCodeService();
        $count = 0;

        OrderDetail::whereNull('title_id')->with('scopes')->get()
            ->groupBy(fn (OrderDetail $d) => $archive->groupKeyFor($d->type, $d->title))
            ->each(function ($group) use ($archive, $codeSvc, &$count) {
                $repr  = $group->first();
                $jenis = $archive->pipelineClass($repr->type); // buku | artikel
                $tipe  = str_contains($repr->type, 'kolab') ? 'kolaborasi' : 'mandiri';

                $title = Title::where('title', $repr->title)->where('jenis', $jenis)->first()
                    ?? Title::create([
                        'title'       => $repr->title,
                        'code'        => $codeSvc->generate($repr->title),
                        'jenis'       => $jenis,
                        'tipe_naskah' => $tipe,
                        'indeksasi'   => $repr->indexation ?: null,
                        'scope_id'    => optional($repr->scopes->first())->id,
                        'status'      => 'disetujui',
                        'asal'        => 'order',
                        'created_by'  => null,
                    ]);

                foreach ($group as $detail) {
                    $detail->update(['title_id' => $title->id]); // saving hook → group_key = 'title:{id}'
                    $count++;
                }
            });

        return $count;
    }
}
```

- [ ] **Step 4: Backfill migration** — create `database/migrations/2026_07_02_000010_backfill_order_title_id.php`:

```php
<?php

use App\Services\TitleBackfillService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new TitleBackfillService())->run();
    }

    public function down(): void
    {
        // no-op: title_id dibiarkan saat rollback
    }
};
```

- [ ] **Step 5: Run, confirm PASS**

Run: `php artisan test --filter=TitleBackfillServiceTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```
git add app/Services/TitleBackfillService.php database/migrations/2026_07_02_000010_backfill_order_title_id.php tests/Unit/TitleBackfillServiceTest.php
git commit -m "feat(title-group): backfill title_id for legacy orders (service + migration)

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 3: Full regression verification + migrate dev

**Files:** none (verification only)

- [ ] **Step 1: Whole suite (regression is the point)**

Run: `php artisan test`
Expected: PASS all (329 sebelumnya + GroupKeyTitleIdTest (4) + TitleBackfillServiceTest (4) = ~337). Perhatikan khusus tetap hijau: `OrderDetailGroupKeyTest`, `TitleArchiveServiceTest`, `ArchiveGroupedTitlesTest`, `ManuscriptTrackerTest`, `MarketingDashboardTest`, `MarketingAccessTest`, `TitleProgressTest`.

- [ ] **Step 2: Compile Blade**

Run: `php artisan view:cache` (no error) → `php artisan view:clear`.

- [ ] **Step 3: Migrate dev DB (jalankan backfill)**

Run: `php artisan migrate --force` (menjalankan `TitleBackfillService::run()` di dev — menaut order lama ke Title). Idempotent. See [[migrate-dev-db-after-new-migration]].

- [ ] **Step 4: Smoke (opsional)**

Papan manuskrip & Arsip Judul tetap mengelompokkan seperti biasa (kini via title_id untuk order-nyata). Direktori Judul: order lama kini tertaut ke judul → muncul di Jml Order/Author + status manuskrip.

---

## Catatan & Risiko

- `group_key` tetap ada; nilainya kini `"title:{id}"` untuk order-nyata → seluruh kode grouping ikut tanpa diubah.
- Backfill idempotent (hanya `title_id=null`); reuse Title by (title, jenis) menghindari duplikat; `created_by=null` (tanpa aktor).
- Test grouping lama pakai factory (tanpa `title_id`) → fallback turunan → tetap hijau tanpa perubahan.
- Tak ada view/route yang mem-parse/menampilkan `group_key` (diverifikasi) → aman ganti format.
- **Manuskrip per Bab** = fitur berikutnya (siklus tersendiri), kompatibel dengan skema ini.
