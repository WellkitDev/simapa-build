# Spec — Title Directory Fase 2b-2: Kunci Grup Manuskrip = title_id

- **Tanggal:** 2026-07-02
- **Branch:** `title-group-by-id`
- **Scope (2b-2):** Jadikan **`title_id`** identitas grup manuskrip menggantikan `group_key` turunan (type+nama). Pendekatan **A (rendah risiko)**: ubah *sumber* nilai `group_key` → bila `title_id` ada, `group_key = "title:{id}"`; bila tidak, tetap turunan lama. Semua kode grouping (ManuscriptTracker, TitleProgressService, TitleArchiveService, dashboard, Arsip) **tetap** group-by `group_key` yang kini = identitas title_id — jadi tak disentuh. Plus **backfill** `title_id` untuk order lama pra-2a.
- **Di luar scope (sengaja):** **Manuskrip per Bab** (editor & status per bab — fitur berikutnya, siklus tersendiri); drop kolom `group_key`; menautkan opsi jurnal panel publikasi ke direktori Jurnal riil; per-bab author.

> Lanjutan 2b-1 (status manuskrip sudah tampil di direktori). Di sini kita membuat identitas grup **robust**: tahan rename judul & nama kembar, karena bertumpu pada `title_id` (entitas Title) bukan string judul snapshot.

---

## 1. Latar Belakang

`group_key` = `pipelineClass(type) . '|' . normalizeTitle(title)` (di `OrderDetail::booted() saving` + `TitleArchiveService`). Ia mengelompokkan order berjudul sama untuk papan manuskrip & Arsip Judul. Kelemahan: (a) berbasis **string judul snapshot** — bila judul di-rename di direktori, group_key OrderDetail tak ikut; (b) dua **Title berbeda** dengan nama ternormalisasi sama akan tergabung. Fase 2a memberi `OrderDetail.title_id` (identitas Title sebenarnya). 2b-2 memakai `title_id` sebagai identitas grup, tanpa merombak kode grouping.

## 2. Tujuan & Kriteria Sukses

1. Order-nyata (punya `title_id`) dikelompokkan berdasarkan **`title_id`** di papan manuskrip, Arsip Judul, dashboard, dan aksi grup (`createForDetail`/`changeGroupStatus`) — otomatis, tanpa mengubah file-file itu.
2. Robust: dua order dengan `title_id` sama tetap **satu grup** walau string judulnya berbeda; Title berbeda tak tergabung.
3. Order lama (`title_id=null`) ter-**backfill**: tertaut ke Title (dibuat/ditemukan) dan tetap terkelompok seperti sebelumnya.
4. Data tanpa `title_id` (mis. fixture factory) tetap memakai grup turunan lama (fallback) → suite lama tetap hijau.
5. Perilaku tertutup test; seluruh suite tetap hijau.

## 3. Desain

### 3.1 Sumber `group_key` (2 titik)
- **`app/Models/OrderDetail.php`** `booted() saving`:
  ```php
  if ($detail->title_id !== null) {
      $detail->group_key = 'title:' . $detail->title_id;
  } elseif ($detail->type !== null && $detail->title !== null) {
      $detail->group_key = (new \App\Services\TitleArchiveService())->groupKeyFor($detail->type, $detail->title);
  }
  ```
- **`app/Services/TitleArchiveService.php`** `groupKey(OrderDetail $detail): string`:
  ```php
  return $detail->title_id !== null
      ? 'title:' . $detail->title_id
      : $this->groupKeyFor($detail->type, $detail->title);
  ```
  `groupKeyFor(type,title)` **dipertahankan** (dipakai fallback + backfill).

Efek berantai (tanpa perubahan kode): `TitleProgressService::createForDetail` (`where('group_key', …)`) & `changeGroupStatus`, `ManuscriptTrackerController::buildGroupCards/groupFor/reviewCount`, `TitleArchiveService::groupDetails`, `OrderBookController::detailJudul`, dashboard marketing — semua mengelompokkan via `group_key` yang kini = `"title:{id}"`.

### 3.2 Backfill order lama (`TitleBackfillService` + migrasi data-only)
- **`app/Services/TitleBackfillService.php`** `run(): int` (kembalikan jumlah detail ter-backfill):
  - Ambil `OrderDetail::whereNull('title_id')->with('scopes')->get()`, kelompokkan by `groupKeyFor(type,title)` lama.
  - Tiap grup: `$repr = grup->first()`; `$jenis = pipelineClass($repr->type)`; `$tipe = str_contains($repr->type,'kolab') ? 'kolaborasi' : 'mandiri'`. Cari `Title::where('title',$repr->title)->where('jenis',$jenis)->first()` — **atau buat**:
    ```php
    Title::create([
      'title' => $repr->title, 'code' => (new TitleCodeService)->generate($repr->title),
      'jenis' => $jenis, 'tipe_naskah' => $tipe,
      'indeksasi' => $repr->indexation ?: null,
      'scope_id' => optional($repr->scopes->first())->id,
      'status' => 'disetujui', 'asal' => 'order', 'created_by' => null,
    ]);
    ```
  - Set `title_id` seluruh detail grup: `$d->update(['title_id' => $title->id])` (hook otomatis set `group_key='title:{id}'`).
- **Migrasi** `..._backfill_order_title_id.php` `up()` → `(new TitleBackfillService)->run();` `down()` → no-op. Idempotent (hanya `title_id=null`). Di test (`RefreshDatabase`, DB kosong) = no-op.

### 3.3 Kolom `group_key`
Dipertahankan (dipakai seluruh kode grouping), kini **di-back `title_id`**. Tak di-drop → tak ada perubahan skema selain migrasi backfill data.

## 4. Komponen yang Disentuh

- **Diubah:** `app/Models/OrderDetail.php` (saving-hook), `app/Services/TitleArchiveService.php` (`groupKey`).
- **Baru:** `app/Services/TitleBackfillService.php`; migrasi `..._backfill_order_title_id.php`; test `GroupKeyTitleIdTest` (unit) + `TitleBackfillServiceTest` (unit/feature).
- **TIDAK disentuh:** `ManuscriptTrackerController`, `TitleProgressService`, `OrderBookController::detailJudul`, view papan/arsip/dashboard, dan test grouping lama (fallback menjaga hijau).

## 5. Rencana Test

- **`GroupKeyTitleIdTest`**:
  - `OrderDetail` dengan `title_id` → `group_key === 'title:'.$title_id`.
  - Dua detail `title_id` sama, string judul berbeda → `group_key` sama (rename/robust).
  - Detail tanpa `title_id` (factory) → `group_key` turunan lama (fallback).
  - `TitleArchiveService::groupKey()` mengembalikan `'title:{id}'` saat `title_id` ada.
- **`TitleBackfillServiceTest`**: buat 2 order lama `title_id=null` berjudul (ternormalisasi) sama & tipe se-pipeline → `run()` menaut keduanya ke **satu** `Title` (dibuat, `asal=order`, `disetujui`, code terisi) & `group_key` keduanya jadi `'title:{id}'`; judul beda pipeline → Title terpisah; reuse Title existing bila nama+jenis cocok (tak duplikat).
- **Regresi (kritis):** `OrderDetailGroupKeyTest`, `TitleArchiveServiceTest`, `ArchiveGroupedTitlesTest`, `ManuscriptTrackerTest`, `MarketingDashboardTest`, `MarketingAccessTest`, `TitleProgressTest` (inherit) tetap **hijau** tanpa perubahan (fixture factory → fallback; order-nyata → title_id, tetap satu grup). `php artisan view:cache` bersih.

Suite via DB test (`.env.testing`), `GoogleDriveService` di-mock. **Dev/prod: `php artisan migrate`** menjalankan backfill (data-only). Lihat [[migrate-dev-db-after-new-migration]].

## 6. Asumsi & Risiko

- `group_key` tetap ada namun nilainya kini `"title:{id}"` untuk order-nyata → semua kode grouping ikut tanpa diubah (blast radius minimal).
- Backfill mengonsolidasikan order lama berjudul sama (ternormalisasi) + se-pipeline ke satu Title — konsisten dengan pengelompokan `group_key` sebelumnya.
- Reuse Title by (nama, jenis) menghindari duplikat dengan Title yang sudah ada (mirip `resolveForOrder`); `created_by=null` karena backfill tanpa aktor.
- Data tanpa `title_id` tetap didukung (fallback) → suite lama & data historis aman.
- **Manuskrip per Bab** (editor/status per bab) adalah fitur berikutnya (siklus tersendiri) dan kompatibel: memakai `tb_title_chapters` + `title_id` yang sudah ada; 2b-2 tak menghalanginya.
