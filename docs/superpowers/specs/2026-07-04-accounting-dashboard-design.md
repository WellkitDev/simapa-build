# Spec — Akuntansi Fase C: Rekap Bulanan + Dashboard Keuangan

- **Tanggal:** 2026-07-04
- **Branch:** `accounting-dashboard`
- **Scope (Fase C):** Satu halaman **Dashboard Keuangan** (filter tahun) turunan dari Jurnal Kas (`CashEntry`): kartu KPI YTD, chart (tren bulanan, breakdown pengeluaran, artikel vs buku), tabel Rekap Bulanan 12 bulan. Laba **berbasis kas** (pemasukan − pengeluaran). Read-only. Tanpa tabel/migrasi baru.
- **Di luar scope:** margin per produk & distribusi profit (Harta/Saving/Fee Tim) = Fase D; anggaran/target = Fase E; edit data (dari Jurnal Kas Fase A/B).

> Lanjutan Akuntansi. Data dari `tb_cash_entries` (jenis pemasukan/pengeluaran, produk artikel/buku/operasional, cash_category_id) + `tb_cash_settings.saldo_awal`. Terpisah dari `FinancialReportService` (laporan dari Payment). ApexCharts sudah dipakai dashboard existing (`assets/plugins/apexcharts/apexcharts.min.js`).

---

## 1. Tujuan & Kriteria Sukses

1. superadmin/accounting membuka **Dashboard Keuangan** (default tahun berjalan; pilih tahun): melihat KPI YTD, chart, dan tabel rekap bulanan — semua otomatis dari Jurnal Kas.
2. Saldo akhir tiap bulan **berlanjut** (saldo awal + kumulatif laba s/d akhir bulan).
3. Non-akuntansi (mis. marketing) tak bisa akses (403).
4. Angka konsisten dgn Jurnal Kas; laba = pemasukan − pengeluaran (kas). Perilaku tertutup test; suite tetap hijau.

## 2. Logika — `CashRecapService`

- **`monthlyRecap(int $year): array`** — array 12 elemen (bulan 1..12), tiap: `['month','label','inArtikel','inBuku','totalIn','totalOut','laba','saldoAkhir']`.
  - `opening` (sebelum tahun) = `CashSetting::singleton()->saldo_awal` + (Σ pemasukan − Σ pengeluaran) untuk entri `tanggal < {year}-01-01`.
  - Ambil entri tahun tsb, kelompokkan per bulan. Untuk tiap bulan: `inArtikel` = Σ amount (jenis pemasukan, produk `artikel`); `inBuku` = Σ (pemasukan, produk `buku`); `totalIn` = Σ pemasukan; `totalOut` = Σ pengeluaran; `laba` = totalIn − totalOut; `running += laba`; `saldoAkhir = running`.
- **`ytd(int $year): array`** — agregasi dari monthlyRecap + entri tahun: `['totalIn','totalOut','laba','saldoAkhir','avgLaba','incomeArtikel','incomeBuku','expenseByCategory','bestMonthLabel']`.
  - `totalIn/totalOut/laba` = Σ 12 bulan; `saldoAkhir` = saldo akhir bulan Desember (running terakhir).
  - `activeMonths` = jumlah bulan dgn `totalIn>0 || totalOut>0`; `avgLaba = laba / max(1,activeMonths)`.
  - `incomeArtikel/incomeBuku` = Σ inArtikel/inBuku.
  - `expenseByCategory` = Σ amount pengeluaran tahun tsb dikelompokkan **nama kategori** (`cash_category.name`; null → "Tanpa Kategori"), urut desc — untuk donut.
  - `bestMonthLabel` = label bulan dgn `laba` tertinggi.

> Semua akses kategori null-safe. Tak menyimpan apa pun (murni hitung dari `CashEntry`).

## 3. Kontroler & Rute

- **`AccountingDashboardController@index(Request)`** (middleware `role:superadmin|accounting`): `year = (int) $request->query('year', now()->year)`; `recap = service->monthlyRecap(year)`; `ytd = service->ytd(year)`; kirim `year`, `recap`, `ytd` ke view.
- Rute `GET accounting/dashboard` name `accounting.dashboard` (dalam grup `role:superadmin|accounting` bersama `accounting.*`).

## 4. View — `resources/views/accounting/dashboard.blade.php`

- **Header**: "Dashboard Keuangan" + filter tahun (`<input type=number name=year>` GET).
- **Kartu KPI** (format Rp): Total Pemasukan, Total Pengeluaran, **Laba Bersih (Kas)**, Saldo Terakhir, Rata² Laba/Bulan, % Artikel vs Buku (incomeArtikel/(artikel+buku)).
- **Chart (ApexCharts)** — data disuntik `@json(...)`:
  - Tren bulanan: bar (Pemasukan, Pengeluaran) + line (Laba) — 12 bulan (`#cashTrendChart`).
  - Donut breakdown pengeluaran per kategori (`#cashExpenseChart`).
  - Bar/pie Pemasukan Artikel vs Buku (`#cashProdukChart`).
- **Tabel Rekap Bulanan**: kolom BULAN · Pemasukan Artikel · Pemasukan Buku · Total Pemasukan · Total Pengeluaran · Laba · Saldo Akhir; baris **TOTAL YTD**.
- Sertakan `apexcharts.min.js` di `@push('plugin-scripts')`; init chart di `@push('custom-scripts')` (pola `new ApexCharts(document.querySelector('#id'), {...}).render()`).
- Menu sidebar **"Keuangan → Dashboard Keuangan"** (`@role(['superadmin','accounting'])`, dekat Jurnal Kas).

## 5. Rencana Test

- **Unit `CashRecapServiceTest`** (RefreshDatabase):
  - `monthly_recap_computes_income_expense_laba_and_running_saldo`: `CashSetting` saldo_awal 1.000.000; entri: Jan pemasukan artikel 500k + buku 300k, pengeluaran 200k; Feb pemasukan artikel 400k. Assert Jan: inArtikel 500k, inBuku 300k, totalIn 800k, totalOut 200k, laba 600k, saldoAkhir 1.600.000; Feb: totalIn 400k, laba 400k, saldoAkhir 2.000.000.
  - `ytd_aggregates`: dari data di atas → totalIn 1.200.000, totalOut 200.000, laba 1.000.000, saldoAkhir 2.000.000, incomeArtikel 900.000, incomeBuku 300.000, bestMonthLabel = 'Jan' (laba 600k > 400k), expenseByCategory berisi kategori pengeluaran.
- **Feature `AccountingDashboardTest`**:
  - `accounting_can_open_dashboard`: accounting `GET accounting.dashboard?year=2026` → 200 + `assertSee('Dashboard Keuangan')` + `assertSee('Total Pemasukan')`.
  - `marketing_cannot_access_dashboard`: marketing → 403.
- **Regresi**: suite hijau; `php artisan view:cache` bersih.

Tanpa migrasi. Test via `.env.testing`.

## 6. Komponen

- **Baru:** `app/Services/CashRecapService.php`; `app/Http/Controllers/Pages/AccountingDashboardController.php`; `resources/views/accounting/dashboard.blade.php`; test unit+feature.
- **Diubah:** `routes/web.php` (`accounting.dashboard`); `resources/views/layouts/sidebar.blade.php` (menu Dashboard Keuangan).
- **Tak diubah:** Jurnal Kas (Fase A/B), FinancialReportService, tabel.

## 7. Asumsi & Risiko

- Laba = kas (pemasukan − pengeluaran); margin & distribusi = Fase D (dokumentasikan agar tak rancu).
- Pemasukan tanpa produk (null) tetap masuk `totalIn` tapi tak ke kolom Artikel/Buku (kolom informatif; total tetap akurat).
- Saldo akhir bulanan berlanjut dari `saldo_awal` (konsisten dgn Jurnal Kas Fase A).
- Chart murni tampilan; angka tabel/KPI = sumber kebenaran. ApexCharts sudah tersedia (tak tambah aset).
- Rata² laba/bulan pakai bulan aktif (ada transaksi) agar tak bias bulan kosong.
