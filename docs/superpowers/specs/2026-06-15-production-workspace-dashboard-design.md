# Spec: Production POV — Meja Kerja Saya + Dashboard Role-Aware

**Tanggal:** 2026-06-15
**Status:** Disetujui — siap masuk rencana implementasi
**Area:** Manuscript Tracker (scope), Dashboard, Performance analytics, Role-aware UI

---

## Ringkasan

Melengkapi POV **production** dan membuat **dashboard role-aware berbasis progres + grafik**:

1. **Meja Kerja Saya** — view tracker yang ter-scope ke "kerja saya" (assigned ke saya **atau** belum diambil di stage produksi), dengan tombol **Ambil** (self-assign), diurut prioritas + deadline.
2. **Dashboard role-aware** (`/dashboard`) — production melihat dashboard produksi (tanpa finansial); manager/superadmin melihat **semuanya** (finansial existing + seksi progres global); marketing tetap finansial (dashboard progres marketing ditunda).
3. **Grafik (ApexCharts)** — reuse library yang sudah dipakai dashboard; tidak ada dependency baru.
4. **Persentase performa per-user (on-time rate)** — metrik jelas dari data yang ada (`target_date`, `assigned_user_id`, status).

Dibangun di atas data yang sudah ada (TitleProgress, `assigned_user_id`, `priority`, `target_date`, activity log). **Tidak ada tabel baru.**

---

## 1. Konsep & Scope

- **Stage produksi** = status yang handler-nya `production` di `TitleProgress::STAGE_HANDLER`: `templating, editing, revisi, submit, layout, proofreading, isbn`. (Diturunkan dari STAGE_HANDLER, bukan di-hardcode ulang.)
- **Stage final** = `terbit` (buku) & `publish` (artikel).
- **"Kerja saya" (production user yang login):**
  `assigned_user_id = Auth::id()` **ATAU** (`assigned_user_id IS NULL` **DAN** `status ∈ stage produksi`).
  - Bucket **Tugas saya** = assigned ke saya.
  - Bucket **Belum diambil** = belum ada editor & di stage produksi (boleh diambil siapa pun production).
- **"Ambil"** = self-assign → reuse endpoint `manuscript.assign` dengan `assigned_user_id = Auth::id()` (production sudah boleh self-assign).

---

## 2. Meja Kerja Saya (scope di tracker)

Bukan halaman baru — menambah dimensi **scope** pada tracker yang ada.

- `ManuscriptTrackerController@index` menerima param **`scope=mine|all`**.
  - Default: user `production` (dan bukan manager/superadmin) → `mine`; selain itu → `all`.
- Filter saat `scope=mine` (pada query `$details`):
  ```
  whereHas('titleProgress', fn($t) =>
      $t->where('assigned_user_id', Auth::id())
        ->orWhere(fn($q) => $q->whereNull('assigned_user_id')->whereIn('status', $productionStages)))
  ```
- **Toolbar**: toggle **"Meja Saya / Semua"** (mengubah `scope`), tampil untuk production/manager/superadmin.
- **Kartu**: kartu **belum diambil** (`assigned_user_id IS NULL`) menampilkan tombol **Ambil** menonjol (self-assign). Kartu yang sudah jadi tugas saya tampil normal.
- **Urutan dalam kolom**: prioritas (high→normal→low) lalu `target_date` menaik (null di akhir); yang **overdue** muncul paling atas. Diurutkan di controller (`$byStatus` per stage di-sort sebelum dikirim ke view).
- **Sidebar**: untuk production, item menu diberi label **"Meja Kerja Saya"** (route tracker, default `scope=mine`). Manager/superadmin tetap "Manuscript Tracker" (`scope=all`).
- Berlaku untuk view **Papan & Daftar** (Log tetap apa adanya). Scope `mine` tidak berlaku ke Log (Log = seluruh aktivitas tipe terpilih).

---

## 3. Dashboard Role-Aware (`/dashboard`)

`DashboardController@index` bercabang per-role; Blade memakai partial per-seksi. Data berat dipindah ke service agar controller tipis & testable.

### 3a. Production (tanpa finansial)
**KPI cards:**
| KPI | Definisi |
|-----|----------|
| Antrian saya | assigned ke saya & **belum final** (= `active_queue` di §4) |
| Belum diambil | `assigned_user_id` null & status ∈ stage produksi |
| Lewat target | kerja saya, `target_date < hari ini` & belum final |
| Jatuh tempo ≤7 hari | kerja saya, `target_date` dalam 7 hari ke depan & belum final |
| Selesai bulan ini | assigned ke saya, status final, `started_at` di bulan berjalan |

**Grafik:**
- **Donut** — distribusi naskah-ku (aktif) per-stage.
- **Area (30 hari)** — tren aktivitas saya (jumlah event log `changed_by = saya` per hari).
- **Radial gauge** — **Performa Saya** (on-time rate, lihat §4).

### 3b. Manager / Superadmin (semuanya)
- **Finansial existing tetap** (tidak diubah).
- **+ Seksi "Progres Naskah (Global)":**
  - KPI: Total dalam produksi · Lewat target (global) · Jatuh tempo ≤7 hari (global) · Selesai bulan ini (global).
  - **Donut** — distribusi per-stage global.
  - **Bar + tabel (DataTable)** — **performa per-editor**: on-time % · jumlah selesai · antrian aktif.
  - **Area (30 hari)** — tren penyelesaian global.

### 3c. Marketing
Tetap dashboard finansial yang sudah ada (sudah ter-scope ke order sendiri). **Dashboard progres marketing = di luar scope** (bagian fitur Marketing POV berikutnya).

---

## 4. Performa (On-Time Rate) — `PerformanceService`

Metrik jelas dari data yang ada. Atribusi ke **`assigned_user_id`** (editor penanggung jawab).

- **Selesai (periode):** TitleProgress `status ∈ {terbit, publish}` & `started_at` dalam periode (default 30 hari), dikelompokkan per `assigned_user_id`. (`started_at` = saat mencapai status final — proxy tanggal selesai; cukup akurat untuk metrik ini.)
- **Tepat waktu (on-time):** dari yang selesai & punya `target_date`, yang `DATE(started_at) ≤ target_date`.
- **On-time rate %** = `on_time ÷ (selesai yang punya target_date) × 100`. Naskah selesai tanpa target_date **dikecualikan dari %**, tetap dihitung di volume.
- **Antrian aktif:** assigned ke editor & status **belum** final.

API:
```
PerformanceService::forEditor(User $editor, int $days = 30): array
    → ['completed' => int, 'on_time' => int, 'with_target' => int, 'on_time_rate' => float|null, 'active_queue' => int]
PerformanceService::allEditors(int $days = 30): Collection  // per user role production/manager, untuk tabel/bar global
```
`on_time_rate` = `null` jika tidak ada naskah selesai ber-target (tampilkan "—", bukan 0%, agar adil).

> Enhancement berikutnya (bukan v1): rata-rata waktu per-stage dari selisih timestamp log.

---

## 5. Komponen / File

| Aksi | Path | Tanggung jawab |
|------|------|----------------|
| Modify | `app/Http/Controllers/Pages/ManuscriptTrackerController.php` | param `scope`, filter kerja-saya, sort prioritas/target |
| Modify | `resources/views/manuscript/partials/toolbar.blade.php` | toggle Meja Saya/Semua |
| Modify | `resources/views/manuscript/partials/card.blade.php` | tombol **Ambil** (kartu belum diambil) |
| Modify | `resources/views/layouts/sidebar.blade.php` | label/route "Meja Kerja Saya" untuk production |
| Modify | `app/Http/Controllers/DashboardController.php` | branch per-role; delegasi ke service |
| Create | `app/Services/ProductionDashboardService.php` | KPI produksi (saya & global) + data chart |
| Create | `app/Services/PerformanceService.php` | on-time rate per editor |
| Modify | `resources/views/dashboard.blade.php` | sertakan partial per-role |
| Create | `resources/views/dashboard/partials/production.blade.php` | KPI + chart produksi (diri) |
| Create | `resources/views/dashboard/partials/progress-global.blade.php` | KPI + chart + tabel performa global |
| Create | `tests/Unit/PerformanceServiceTest.php` | hitung on-time rate |
| Create | `tests/Feature/ProductionWorkspaceTest.php` | scope mine, Ambil, dashboard per-role |

Reuse: **ApexCharts** (`assets/plugins/apexcharts`), **DataTables** (tabel performa), TitleProgress/log/target_date.

---

## 6. Alur Data (ringkas)

```
Production buka /dashboard
  → DashboardController: role production
  → ProductionDashboardService::forUser(me) [KPI + per_stage + activity_30d]
  → PerformanceService::forEditor(me) [on-time rate]
  → view dashboard.production

Production buka Meja Kerja Saya (tracker scope=mine)
  → index() filter kerja-saya + sort prioritas/target
  → kartu belum diambil → tombol Ambil → manuscript.assign(self) → masuk "Tugas saya"

Manager/super buka /dashboard
  → finansial existing + ProductionDashboardService::global() + PerformanceService::allEditors()
  → view dashboard.progress-global (+ finansial)
```

---

## 7. Error Handling / Edge Cases

| Kondisi | Penanganan |
|---------|-----------|
| Tidak ada naskah selesai ber-target untuk editor | `on_time_rate = null` → tampil "—" (bukan 0%) |
| Naskah tanpa `target_date` | dikecualikan dari on-time & overdue; tetap di volume/queue |
| Production tanpa tugas & tanpa belum-diambil | Meja Kerja kosong → empty state ramah ("Tidak ada tugas") |
| User multi-role (production + manager) | default scope `all`; tetap bisa pilih `mine` |
| Ambil oleh production di kartu yang ternyata sudah diambil orang lain (race) | endpoint assign menimpa/menolak sesuai aturan assign existing; UI refresh |
| Chart tanpa data | render kosong/0 tanpa error (series kosong aman di ApexCharts) |

---

## 8. Kualitas (QA/QC)

**Unit — `PerformanceServiceTest`:**
- on-time rate: 3 selesai ber-target, 2 tepat waktu → 66.7%.
- selesai tanpa target dikecualikan dari rate, masuk volume.
- tidak ada selesai ber-target → rate null.
- active_queue hanya menghitung assigned & belum final.

**Feature — `ProductionWorkspaceTest`:**
- `scope=mine` hanya menampilkan kerja saya (assigned ke saya) + belum-diambil (null & stage produksi); tidak menampilkan naskah milik editor lain.
- tombol **Ambil** (assign self) memindahkan naskah ke "Tugas saya".
- default scope: production → `mine`; manager → `all`.
- dashboard production menampilkan KPI yang benar (ter-scope ke saya) & tidak menampilkan blok finansial.
- dashboard manager/superadmin menampilkan seksi progres global + tabel performa per-editor + finansial.
- marketing dashboard tetap finansial.
- urutan kartu: prioritas lalu target (overdue dulu) — assert urutan minimal.

Target: seluruh suite tetap hijau (saat ini 117 passed). Jalankan via `php artisan test` (DB test, bukan asli).

**Manual QA:** login production → Meja Kerja Saya berisi tugas + belum-diambil, Ambil bekerja, dashboard produksi + chart tampil; login manager → dashboard global + tabel performa; login marketing → finansial seperti biasa.

---

## 9. Di Luar Cakupan (YAGNI)

Dashboard progres marketing + target pemasukan/komisi, notifikasi, export, lampiran file, rata-rata waktu per-stage, leaderboard publik. (Roadmap terpisah.)

---

## Dependensi

- ApexCharts & DataTables sudah tersedia (tanpa dependency baru).
- Tidak ada migrasi/tabel baru — semua dari data yang ada.
</content>
