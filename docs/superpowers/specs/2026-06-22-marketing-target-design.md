# Spec — Target Pemasukan & Komisi per Marketing

- **Tanggal:** 2026-06-22
- **Status:** Disetujui (siap masuk implementation plan)
- **Scope:** Tetapkan target pemasukan bulanan + rate komisi per marketing; tampilkan progres (realisasi vs target + komisi) di dashboard marketing & halaman admin.
- **Di luar scope (sengaja):** notifikasi saat target di-set, target tahunan, realisasi komisi manual, alur approval target.

---

## 1. Latar Belakang

Definisi pemasukan sudah kanonik di codebase: `Payment::approved()->forOrdersOf($marketing)->sum('amount')` per `paid_at` (basis kas). Belum ada konsep target maupun komisi. Fitur ini menambah penetapan target pemasukan bulanan + persentase komisi per marketing, lalu menghitung capaian & komisi secara otomatis dari realisasi.

## 2. Tujuan & Kriteria Sukses

1. Manager/superadmin bisa menetapkan, per marketing per bulan: target pemasukan + rate komisi (%).
2. Sistem menghitung otomatis: realisasi pemasukan bulan itu, capaian %, komisi (rate × realisasi), sisa target.
3. Marketing melihat kartu "Target Bulan Ini" di dashboard-nya (progres + komisi).
4. Halaman admin "Target Marketing" sekaligus jadi laporan capaian seluruh marketing per bulan.
5. Marketing tidak bisa membuka halaman admin target (403).
6. Semua perilaku tertutup test; suite tetap hijau.

---

## 3. Desain

### 3.1 Data model

Migrasi `tb_marketing_targets`:

| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK → users | marketing |
| `year` | smallint/int | mis. 2026 |
| `month` | tinyint | 1–12 |
| `target_amount` | bigint | target pemasukan (Rp), int |
| `commission_rate` | decimal(5,2) | persen, mis. `5.00` |
| `created_by` | FK → users, nullable | |
| `updated_by` | FK → users, nullable | |
| timestamps | | |

**Unique** `(user_id, year, month)`.

Model `App\Models\MarketingTarget` (`$table = 'tb_marketing_targets'`): fillable semua kolom di atas; casts `year`/`month`/`target_amount` → int, `commission_rate` → float; relasi `user()` belongsTo.

### 3.2 `MarketingTargetService` (`app/Services/MarketingTargetService.php`)

**`progressFor(User $marketing, int $year, int $month): array`** mengembalikan:

```
[
  'has_target'     => bool,            // ada baris target untuk bulan itu?
  'target'         => int,             // target_amount (0 bila tak ada)
  'rate'           => float,           // commission_rate (0 bila tak ada)
  'realisasi'      => int,             // Payment approved scoped, bulan itu
  'capaian_persen' => float,           // target>0 ? round(realisasi/target*100,1) : 0
  'komisi'         => int,             // round(rate/100 * realisasi)
  'sisa'           => int,             // max(target - realisasi, 0)
]
```

Realisasi: `Payment::approved()->forOrdersOf($marketing)->whereYear('paid_at',$year)->whereMonth('paid_at',$month)->sum('amount')`.

**`monthlyOverview(int $year, int $month): \Illuminate\Support\Collection`** → satu baris per user role `marketing`: `user_id`, `name`, `target`, `rate`, `has_target`, `realisasi`, `capaian_persen`, `komisi`. Realisasi seluruh marketing dihitung **satu query grouped** (`Payment::approved()` join order, `whereYear/Month`, `groupBy order.user_id`) lalu dipetakan per user — hindari N+1.

**`upsertMany(int $year, int $month, array $rows, User $actor): void`** → untuk tiap entri `['user_id'=>.., 'target'=>.., 'rate'=>..]`, `updateOrCreate(['user_id','year','month'], ['target_amount','commission_rate','updated_by'=>actor, 'created_by'=> existing ?? actor])`. Hanya untuk user yang benar-benar marketing (validasi role).

**Perilaku baris kosong (definit):** entri dengan `target` kosong/blank **dilewati** (tidak membuat/menyimpan baris) — admin hanya menetapkan target untuk marketing yang benar-benar diisi. Entri dengan `target` terisi disimpan; bila `rate` kosong → disimpan `0`.

### 3.3 Halaman admin "Target Marketing"

Route (grup `auth`, middleware `role:manager|superadmin`):

| Route | Nama | Aksi |
|---|---|---|
| `GET /marketing-target` | `marketing-target.index` | param `?year=&month=` (default bulan berjalan); render overview |
| `POST /marketing-target` | `marketing-target.save` | upsert target bulan terpilih |

Controller `App\Http\Controllers\Pages\MarketingTargetController` (`index`, `save`).

View `resources/views/marketing-target/index.blade.php`:
- Pemilih bulan (dropdown tahun + bulan, submit GET).
- Tabel per marketing: **Nama · Target Pemasukan (input number, Rp) · Rate Komisi % (input number) · Realisasi (read-only, format Rp) · Capaian % (badge warna) · Komisi (read-only, Rp)**.
- Satu `<form method=POST>` membungkus seluruh baris (input bernama `targets[user_id][target]` & `targets[user_id][rate]`), tombol "Simpan".
- Capaian badge: ≥100% hijau, ≥75% kuning, <75% merah.

Validasi `save`: `targets.*.target` numeric ≥ 0; `targets.*.rate` numeric 0–100; `year`/`month` valid.

Sidebar: tambah item "Target Marketing" di grup **Laporan** (hanya `@role(['superadmin','manager'])`).

### 3.4 Kartu di dashboard marketing

`MarketingDashboardService::forUser($user)` menambah key `target` = `app(MarketingTargetService::class)->progressFor($user, today.year, today.month)`.

Partial `resources/views/dashboard/partials/marketing.blade.php` menambah section **"Target Bulan Ini"** (mis. setelah Ringkasan Pemasukan): progress bar capaian %, angka target, realisasi, sisa, dan komisi diperoleh. Bila `has_target=false` → kartu redup "Target belum ditetapkan untuk bulan ini."

### 3.5 Hak akses

Halaman admin di-gate `role:manager|superadmin` (marketing → 403). Kartu dashboard hanya memuat target milik marketing yang login (lewat `progressFor($user, ...)`), jadi otomatis ter-scope.

---

## 4. Komponen yang Disentuh / Dibuat

- **Baru:** migrasi `tb_marketing_targets`, `app/Models/MarketingTarget.php`, `app/Services/MarketingTargetService.php`, `app/Http/Controllers/Pages/MarketingTargetController.php`, `resources/views/marketing-target/index.blade.php`.
- **Dimodifikasi:** `routes/web.php` (2 route + grup), `resources/views/layouts/sidebar.blade.php` (menu), `app/Services/MarketingDashboardService.php` (key `target` di `forUser`), `resources/views/dashboard/partials/marketing.blade.php` (section target).

## 5. Rencana Test

- **Unit `tests/Unit/MarketingTargetServiceTest.php`**:
  - `progressFor`: target 10jt, rate 5%, realisasi 10jt → capaian 100%, komisi 500rb, sisa 0.
  - realisasi parsial: target 10jt, realisasi 6jt → capaian 60%, sisa 4jt, komisi 300rb (rate 5%).
  - tanpa target → `has_target=false`, target/rate/komisi 0, realisasi tetap dihitung.
  - realisasi ter-scope ke marketing (order marketing lain tidak ikut) & ke bulan (pembayaran bulan lain tidak ikut).
  - `monthlyOverview`: memuat semua marketing dengan realisasi benar (termasuk yang belum punya target).
  - `upsertMany`: membuat baris baru lalu meng-update baris yang sama (idempotent per user+bulan).
- **Feature `tests/Feature/MarketingTargetTest.php`**:
  - manager GET index → 200 & melihat nama marketing; POST save → target tersimpan (assert DB).
  - marketing GET index → **403**.
  - dashboard marketing menampilkan section "Target Bulan Ini" + angka target saat target ada.

Suite dijalankan terhadap `avidpedi_simapa_test` via `.env.testing`; migrasi `tb_marketing_targets` ikut ter-migrate oleh `RefreshDatabase`. Migrasi juga harus dijalankan di dev/produksi saat rilis (`php artisan migrate`).

## 6. Asumsi & Risiko

- Realisasi pemasukan memakai definisi kanonik yang sama dengan dashboard & laporan (konsisten lintas fitur).
- Komisi murni turunan (rate × realisasi); tidak disimpan, jadi perubahan rate langsung tercermin.
- Bila target belum di-set untuk suatu bulan, fitur tetap aman (nilai 0 / "belum ditetapkan"), tidak error.
- `monthlyOverview` memakai satu query grouped untuk realisasi agar tidak N+1 saat marketing banyak.
