# Spec: Laporan Keuangan (rombak Pendapatan → 3 laporan konsisten)

**Tanggal:** 2026-06-17
**Status:** Disetujui — siap masuk rencana implementasi
**Area:** Laporan keuangan (IncomeController), definisi pemasukan, export
**Branch:** `Fitur` (jangan merge dulu)

---

## Ringkasan

Membenahi laporan keuangan agar **benar & konsisten**. Saat ini menu "Pendapatan" punya 4 sub-menu dengan masalah: definisi "pemasukan" berbeda antara Dashboard dan Laporan, dua menu duplikat (Order = Payment), bug scope marketing di laporan piutang, dan tombol export mati.

Solusi: **satu definisi pemasukan kanonik (basis kas)** dipakai Dashboard **dan** Laporan, lalu rombak jadi **3 laporan berlabel jelas** — Pemasukan (kas masuk), Piutang (outstanding), Order Selesai (lunas) — masing-masing dengan rekap + detail per-baris (DataTables) + export PDF & CSV.

Target/komisi marketing **tidak** termasuk (fitur terpisah di roadmap).

---

## 1. Definisi Kanonik "Pemasukan" (basis kas)

**Pemasukan = Σ amount dari Payment yang disetujui, per `paid_at`** — termasuk DP/parsial pada order yang belum lunas. Mencerminkan uang nyata yang masuk.

- "Disetujui" = `Payment.status = 'paid'` (di-set bersamaan `PaymentApproval.status='approved'` saat approve; `status='paid'` jadi sumber tunggal untuk perhitungan uang — menghapus dual source of truth).
- Basis tanggal = `paid_at` (BUKAN `created_at`).
- **Scope kanonik di model `Payment`:**
  - `scopeApproved($q)` → `$q->where('status', 'paid')`
  - `scopeForOrdersOf($q, ?User $user)` → bila `$user` (marketing-only) diberikan: `$q->whereHas('order', fn($o) => $o->where('user_id', $user->id))`; bila null: tanpa filter (manager/superadmin).

Semua perhitungan pemasukan (Laporan **dan** `MarketingDashboardService`) memakai scope ini → definisi tak bisa melenceng lagi.

> **Perubahan perilaku yang disengaja:** laporan "Pemasukan" lama hanya menghitung payment pada order yang sudah `lunas`. Definisi baru menghitung **semua** payment approved (termasuk DP order berjalan). Ini yang disepakati (basis kas), dan menyamakan angka Laporan dengan Dashboard.

---

## 2. Scoping Role

- **Marketing-only** (`hasRole('marketing') && ! hasAnyRole(['manager','superadmin'])`) → hanya order miliknya (`order.user_id = me`). Diterapkan **konsisten** di KPI **dan** tabel (memperbaiki bug laporan piutang yang KPI-nya global tapi tabelnya scoped).
- Manager/superadmin → semua.

---

## 3. Tiga Laporan (ganti 4 menu lama)

### A. Pemasukan (Kas Masuk) — gabung "Order" + "Payment" yang duplikat
- **KPI:** total kas masuk (tahun berjalan) · jumlah pembayaran · jumlah order (distinct).
- **Rekap:** per tahun & per bulan — Σ amount, COUNT(DISTINCT order_id), COUNT(payment) (by `paid_at`).
- **Detail per-pembayaran (DataTables):** `paid_at` · kode order · klien (judul naskah + email kontak) · tipe (`payment_type`: dp/pelunasan/lunas) · nominal · no invoice (`payment.invoice.invoice_no`, "—" bila belum ada).
- Termasuk DP/parsial pada order belum-lunas.

### B. Piutang (Outstanding) — perbaiki bug scope
- **KPI (ter-scope konsisten):** total nilai order belum-lunas (Σ `details.cost_amount`) · total sudah bayar (Σ payment approved) · **total sisa piutang** (selisih).
- **Detail (DataTables):** kode order · klien · nilai order · sudah bayar · sisa · status order.
- Hanya order `status != 'lunas'`.

### C. Order Selesai (Lunas) — rapikan yang ada
- **KPI:** jumlah order lunas · total nilai.
- **Detail (DataTables):** kode order · klien · nilai · tanggal lunas (`completed_at` bila ada, fallback `updated_at`).
- Hanya order `status = 'lunas'`.

> **Tagihan** (disetujui tapi belum bayar) **tidak** masuk Piutang — itu pra-order, belum ada order/piutang. Konsisten dengan keputusan "tagihan ≠ pemasukan sampai jadi order".

---

## 4. Export (PDF + CSV, tanpa paket baru)

Tiap laporan punya tombol Export berfungsi (saat ini `href="#"` mati):
- **PDF** via DomPDF (sudah terpasang) — blade format laporan (kop + tabel + total), ter-scope role.
- **CSV** via streamed response (native PHP `fputcsv`, langsung kebuka di Excel) — baris detail + baris total.

Export menghormati scope role yang sama (marketing → datanya sendiri).

---

## 5. Komponen / File

| Aksi | Path | Tanggung jawab |
|------|------|----------------|
| Modify | `app/Models/Payment.php` | scope `approved()` + `forOrdersOf()` |
| Create | `app/Services/FinancialReportService.php` | 3 dataset (pemasukan/piutang/orderSelesai) + scope role + data export |
| Modify | `app/Http/Controllers/Pages/IncomeController.php` | 3 method (pemasukan/piutang/lunas) + 6 method export (pdf/csv × 3); tipis, delegasi ke service |
| Modify | `app/Services/MarketingDashboardService.php` | pakai `Payment::approved()->forOrdersOf()` (definisi tunggal; perilaku tetap) |
| Modify | `routes/web.php` | route group `income.` jadi pemasukan/piutang/lunas + route export |
| Modify | `resources/views/layouts/sidebar.blade.php` | menu Laporan ▸ Pemasukan · Piutang · Order Selesai |
| Create | `resources/views/income/pemasukan.blade.php` | KPI + rekap + detail (DataTables) + tombol export |
| Create | `resources/views/income/piutang.blade.php` | KPI + detail + export |
| Modify | `resources/views/income/index-lunas.blade.php` → `lunas.blade.php` | rapikan + KPI + export (rename/replace) |
| Delete | `resources/views/income/index-order.blade.php`, `index-payment.blade.php`, `index-report.blade.php` | digantikan |
| Create | `resources/views/income/pdf/*.blade.php` | template PDF per laporan |
| Create | `tests/Unit/FinancialReportServiceTest.php` | basis kas incl DP, scoping, piutang konsisten |
| Create | `tests/Feature/FinancialReportTest.php` | 3 laporan render + scope + export content-type + Dashboard↔Laporan sama |

---

## 6. Alur Data (ringkas)

```
Marketing buka Laporan ▸ Pemasukan
  → IncomeController@pemasukan → FinancialReportService::pemasukan(scopeUser)
  → Payment::approved()->forOrdersOf(me) by paid_at  → KPI + rekap + detail
  → tombol Export PDF → income.pemasukan.pdf (DomPDF, scoped)
  → tombol Export CSV → streamed fputcsv (scoped)

Dashboard pemasukan (tak berubah perilaku) kini juga lewat Payment::approved()->forOrdersOf(me)
  → angka Dashboard == angka Laporan Pemasukan (definisi tunggal)
```

---

## 7. Error Handling / Edge Cases

| Kondisi | Penanganan |
|---------|-----------|
| Marketing tanpa pembayaran | KPI 0, tabel kosong (empty-state DataTables), export tetap jalan (hanya header + total 0) |
| Order tanpa details/cost | dianggap 0 di piutang/nilai (guard null) |
| Payment tanpa invoice | kolom no invoice "—" |
| Marketing + role manager | bukan marketing-only → lihat semua (konsisten app) |
| Order belum-lunas dengan DP | DP masuk Pemasukan; order masuk Piutang (sisa = cost − paid) — TIDAK dobel-hitung sbg pemasukan |
| `paid_at` null pada payment lama | fallback ke `created_at` saat grouping (guard) |

---

## 8. Kualitas (QA/QC)

**Unit — `FinancialReportServiceTest`:**
- pemasukan basis kas: DP pada order belum-lunas IKUT terhitung (beda dari definisi lama).
- pemasukan ter-scope: payment order marketing lain tak terhitung.
- pemasukan by `paid_at` (bukan created_at).
- piutang: KPI total (nilai/bayar/sisa) **dan** daftar sama-sama ter-scope (regresi bug lama).
- order selesai: hanya `status='lunas'`.

**Feature — `FinancialReportTest`:**
- 3 laporan render 200 untuk marketing & manager; marketing hanya data sendiri (assertSee/DontSee).
- export PDF → content-type `application/pdf`; export CSV → `text/csv` + isi baris benar.
- **konsistensi:** total Pemasukan dari `FinancialReportService` == `MarketingDashboardService::forUser()['pemasukan_tahun_ini']` untuk user yang sama (definisi tunggal).
- menu duplikat lama (income.order/payment) tidak lagi ada sebagai route terpisah; route income.pemasukan/piutang/lunas ada.

Target: seluruh suite tetap hijau (saat ini 158 passed) + test baru. Update test existing yang menyentuh route income lama bila ada (perubahan disengaja, jangan dilemahkan diam-diam).

**Manual QA:** login marketing → Laporan ▸ Pemasukan (kas masuk termasuk DP, rekap + detail) = angka sama dengan Dashboard; Piutang (KPI = tabel, ter-scope); Order Selesai; export PDF & CSV tiap laporan terunduh benar.

---

## 9. Di Luar Cakupan (YAGNI)

Target pemasukan/komisi & reward (fitur tersendiri), filter rentang tanggal kustom (rekap bulan/tahun + search DataTables sudah cukup), export .xlsx asli (CSV cukup, tanpa paket baru), laporan laba/biaya/HPP, grafik baru di laporan (Dashboard sudah punya chart), notifikasi.

---

## Dependensi

- DomPDF, DataTables — sudah ada. **Tanpa paket baru** (CSV native).
- `Payment`/`Order`/`OrderDetail`/`Invoice`/`PaymentApproval` — sudah ada.
- `MarketingDashboardService` — di-refactor agar berbagi definisi (perilaku tetap).
- Tidak ada migrasi/tabel baru.
