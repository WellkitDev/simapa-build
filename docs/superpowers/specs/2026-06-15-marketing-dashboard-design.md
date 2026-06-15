# Spec: Marketing POV — Dashboard Marketing + Arsip Judul

**Tanggal:** 2026-06-15
**Status:** Disetujui — siap masuk rencana implementasi
**Area:** Dashboard (role-aware), Marketing analytics, Arsip Judul

---

## Ringkasan

Melengkapi POV **marketing**: dashboard tersendiri yang menggabungkan **ringkasan pemasukan** dan **progres naskah miliknya** (semua ter-scope ke order yang dia buat, `order.user_id = saya`, read-only), plus memperkaya **Arsip Judul** dengan kolom Target/overdue.

Marketing saat ini hanya melihat dashboard finansial generik (approve/pending/reject) + Arsip Judul (sudah ter-scope). Fitur ini mengganti dashboard generik untuk role marketing dengan dashboard yang relevan, dan menambahkan visibilitas target.

Dibangun di atas data yang ada (Order, Payment, TitleProgress, `target_date`, activity log). **Tanpa tabel baru.** Reuse ApexCharts + DataTables + pola dashboard role-aware (dari fitur Production Workspace).

> **Catatan scope:** "Target pencapaian" & "performa" marketing **ditunda** ke fitur tersendiri **"Target Pemasukan/Komisi"** (perlu subsistem penetapan target + reward). Tidak termasuk di sini.

---

## 1. Konsep & Scope

- **"Naskah saya / order saya" (marketing yang login)** = `order.user_id = Auth::id()`.
- **Stage final** = `terbit`, `publish` (`TitleProgress::FINAL_STAGES`, sudah ada).
- **Belum diproses** = `status = menunggu_proses` (sudah di-order, belum diambil production/admin).
- **Naskah aktif** = sedang dikerjakan = status **bukan** final **dan bukan** `menunggu_proses`.
- **Selesai** = status final (`terbit`/`publish`).
- **Pemasukan** = `Payment` `status = paid`, `amount`, untuk order milik marketing.

Marketing-only = `hasRole('marketing')` **dan bukan** `manager`/`superadmin`.

---

## 2. Dashboard Marketing (role-aware, menggantikan dashboard generik untuk marketing)

`DashboardController@index` menambah cabang **marketing-only** (early-return, seperti cabang production yang sudah ada) → render dashboard marketing dengan `dashboardView = 'marketing'`. Manager/superadmin/marketing-plus-role tetap dashboard generik + progres global.

> **Penting (gating Blade):** `dashboard.blade.php` kini punya **tiga** cabang — `production` | `marketing` | generik-finansial. Markup **dan** blok `@push('custom-scripts')` finansial (yang mereferensikan `$data`) harus digerbangi agar hanya jalan untuk cabang finansial generik (mis. `$dashboardView === 'financial'`), bukan sekadar `!== 'production'` — kalau tidak, cabang marketing (yang tidak mengirim `$data`) akan error. Cabang marketing meng-`@include` partial marketing yang membawa `$push` chart-nya sendiri.

Empat seksi (Blade partial + ApexCharts):

### A. Ringkasan Pemasukan (KPI cards)
| KPI | Definisi (scoped order.user_id = saya) |
|-----|------|
| Pemasukan Hari Ini | Σ `amount` Payment paid, `paid_at` = hari ini |
| Pemasukan Minggu Ini | Σ Payment paid, `paid_at` dalam minggu berjalan |
| Pemasukan Tahun Ini | Σ Payment paid, `paid_at` tahun berjalan |
| Jumlah Order (tahun ini) | count Order `user_id = saya`, `ordered_at` tahun berjalan |

### B. Chart Pemasukan
- **Area** Tren Pemasukan (30 hari, Σ harian).
- **Area** Tren Jumlah Order (30 hari, count harian).

### C. Progres Naskah Saya (KPI cards)
| KPI | Definisi (naskah dari order saya) |
|-----|------|
| Naskah Aktif | status ∉ final & ≠ `menunggu_proses` |
| Belum Diproses | status = `menunggu_proses` |
| Lewat Target | status ∉ final, `target_date < hari ini` |
| Jatuh Tempo ≤7 hari | status ∉ final, `target_date` dalam 7 hari ke depan |
| Selesai Bulan Ini | status ∈ final, `started_at` di bulan berjalan |

### D. Chart Progres
- **Donut** distribusi naskah-ku per-tahap (aktif, non-final).
- **Area** tren terbit/publish 30 hari (penyelesaian naskah-ku).

---

## 3. `MarketingDashboardService`

Satu service menampung query/agregasi agar controller tipis & testable. Semua ter-scope `order.user_id = $user->id`.

```
MarketingDashboardService::forUser(User $user): array
```
Mengembalikan: `pemasukan_hari_ini, pemasukan_minggu_ini, pemasukan_tahun_ini, jumlah_order_tahun_ini,
income_trend{labels,series}, order_trend{labels,series},
naskah_aktif, belum_diproses, lewat_target, jatuh_tempo_7, selesai_bulan_ini,
per_stage{labels,series}, completion_trend{labels,series}`.

**Scoping query:**
- Pemasukan: `Payment::where('status','paid')->whereHas('order', fn($q) => $q->where('user_id', $user->id))...`
- Order: `Order::where('user_id', $user->id)...`
- Progres: `TitleProgress::whereHas('orderDetail.order', fn($q) => $q->where('user_id', $user->id))...`
- `completion_trend`: log `to_value ∈ ['Terbit','Publish']` yang `titleProgress.orderDetail.order.user_id = saya`, per hari 30 hari.

`per_stage`/`income_trend`/`order_trend`/`completion_trend` berformat `{labels:[], series:[]}` untuk ApexCharts (pola sama dengan `ProductionDashboardService`).

---

## 4. Perkaya Arsip Judul (Target + overdue)

`TitleArchiveService::summarize()` menambah ke objek ringkasan:
- `target_date` — dari `titleProgress.target_date` varian representatif (target kini group-wide).
- `is_overdue` — `target_date < hari ini` **dan** `bottleneck_status` bukan final.

`resources/views/orders/index-title.blade.php` (DataTable Arsip Judul) menambah kolom **Target** (tanggal + badge merah "lewat" bila `is_overdue`). Berlaku untuk semua role yang memakai Arsip Judul (marketing/manager/superadmin).

---

## 5. Komponen / File

| Aksi | Path | Tanggung jawab |
|------|------|----------------|
| Create | `app/Services/MarketingDashboardService.php` | KPI pemasukan + order + progres (scoped) + data chart |
| Modify | `app/Http/Controllers/DashboardController.php` | cabang marketing-only → dashboard marketing |
| Modify | `resources/views/dashboard.blade.php` | include partial marketing (role-aware) |
| Create | `resources/views/dashboard/partials/marketing.blade.php` | 4 seksi + ApexCharts |
| Modify | `app/Services/TitleArchiveService.php` | `summarize()` + `target_date`/`is_overdue` |
| Modify | `resources/views/orders/index-title.blade.php` | kolom Target + badge overdue |
| Create | `tests/Unit/MarketingDashboardServiceTest.php` | KPI ter-scope benar |
| Create | `tests/Feature/MarketingDashboardTest.php` | render dashboard marketing + Arsip Judul Target |

---

## 6. Alur Data (ringkas)

```
Marketing buka /dashboard
  → DashboardController: cabang marketing-only
  → MarketingDashboardService::forUser(me)  [pemasukan + order + progres, scoped user_id]
  → view dashboard.marketing  (Ringkasan Pemasukan + chart + Progres Naskah Saya + chart)

Marketing buka Arsip Judul
  → indexJudul (sudah scoped marketing) → TitleArchiveService::summarize (+ target_date, is_overdue)
  → kolom Target + badge overdue
```

---

## 7. Error Handling / Edge Cases

| Kondisi | Penanganan |
|---------|-----------|
| Marketing tanpa order/naskah | semua KPI 0; chart kosong (series kosong aman di ApexCharts); empty state ramah |
| Naskah tanpa `target_date` | dikecualikan dari lewat-target & jatuh-tempo; tetap dihitung di aktif/belum-diproses/selesai |
| User marketing + role lain (mis. manager) | bukan marketing-only → dashboard generik + progres global (tidak dapat dashboard marketing) |
| Payment tanpa `paid_at` (belum lunas) | tidak masuk pemasukan (hanya `status=paid`) |
| Arsip Judul grup tanpa target | kolom Target tampil "—", tanpa badge overdue |

---

## 8. Kualitas (QA/QC)

**Unit — `MarketingDashboardServiceTest`:**
- pemasukan hari ini/minggu/tahun hanya menjumlah Payment paid milik order marketing itu (bukan marketing lain).
- jumlah_order_tahun_ini hanya order miliknya, tahun berjalan.
- `belum_diproses` = hanya `menunggu_proses`; `naskah_aktif` mengecualikan final & menunggu; `selesai_bulan_ini` = final di bulan ini.
- `lewat_target` = non-final & target < hari ini (due-today TIDAK overdue, konsisten dengan board/KPI produksi).
- scoping: naskah/pembayaran milik marketing lain tidak ikut terhitung.

**Feature — `MarketingDashboardTest`:**
- marketing melihat dashboard marketing: "Ringkasan Pemasukan" + "Progres Naskah Saya"; TIDAK melihat blok generik approve/pending/reject.
- KPI hanya naskah/order miliknya (assertSee/DontSee judul milik marketing lain).
- manager/superadmin tetap dashboard generik + progres global (tidak berubah).
- production tetap dashboard produksi (tidak berubah).
- Arsip Judul (sebagai marketing) menampilkan kolom **Target**.

Target: seluruh suite tetap hijau (saat ini 131 passed). Jalankan `php artisan test` (DB test).

**Manual QA:** login marketing → dashboard = pemasukan (hari/minggu/tahun) + jumlah order + progres naskah (aktif/belum diproses/lewat target/jatuh tempo/selesai) + chart; Arsip Judul ada kolom Target dengan badge overdue.

---

## 9. Di Luar Cakupan (YAGNI)

Target pemasukan/komisi & reward (fitur tersendiri), performa marketing berbasis target, notifikasi, akses board tracker untuk marketing, export, dashboard generik approve/pending/reject untuk marketing (digantikan).

---

## Dependensi

- ApexCharts & DataTables sudah tersedia (tanpa dependency baru).
- `TitleProgress::FINAL_STAGES`, role-aware dashboard pattern, `target_date` — sudah ada dari fitur Production Workspace.
- Tidak ada migrasi/tabel baru.
</content>
