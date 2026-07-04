# Spec — Keuangan UX: Export + Overview + Mask Ribuan

- **Tanggal:** 2026-07-04
- **Branch:** `accounting-keuangan-ux`
- **Scope:** Tiga peningkatan UX pada modul Keuangan: (1) **mask ribuan** pada semua input Rupiah; (2) **export** Jurnal Kas & Rekap ke **CSV + PDF**; (3) halaman **Ringkasan Keuangan** (landing agregat + pintasan). superadmin/accounting.
- **Di luar scope:** export .xlsx asli (pakai CSV), export Distribusi/Asumsi/Target, kunci periode/audit (backlog terpisah), menyatukan sumber pemasukan (backlog terpisah).

> Dari kritik-diri epik Akuntansi (backlog item #3). Tanpa dependency baru: PDF pakai `barryvdh/laravel-dompdf` (sudah ada), CSV native PHP, mask pakai `jquery.inputmask` (sudah ter-bundle di `public/assets/plugins/inputmask/`). Lanjutan setelah Multi-Akun Bank.

---

## 1. Tujuan & Kriteria Sukses

1. Input Rupiah menampilkan **pemisah ribuan** (50.000.000) saat diketik; nilai terkirim ke server tetap **angka polos** (validasi `numeric` tak berubah).
2. Jurnal Kas & Rekap bisa **di-export CSV & PDF** (Jurnal mengikuti filter aktif; Rekap per tahun).
3. Halaman **Ringkasan Keuangan** menampilkan KPI (total saldo semua akun, pemasukan/pengeluaran/laba YTD), saldo per akun, target vs realisasi ringkas, total biaya tetap/bln, + pintasan ke 5 halaman.
4. Non-akuntansi 403. Suite tetap hijau.

## 2. Bagian 1 — Mask Ribuan

**Partial baru** `resources/views/accounting/partials/money-mask.blade.php`:
```blade
@push('plugin-scripts')
<script src="{{ asset('assets/plugins/inputmask/jquery.inputmask.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
$(function () {
    $('.money-mask').inputmask({
        alias: 'numeric', groupSeparator: '.', digits: 0, autoGroup: true,
        rightAlign: false, autoUnmask: true, removeMaskOnSubmit: true, allowMinus: false
    });
});
</script>
@endpush
```
> `autoUnmask` + `removeMaskOnSubmit` → nilai yang terkirim = angka polos (mis. `50000000`). `jquery.inputmask` butuh jQuery (sudah dimuat via DataTables) & input **`type="text"`** (mask tak jalan di `type=number`).

**Perubahan tiap view** (money inputs): ubah `type="number"` → `type="text"` + tambah class `money-mask`, lalu `@include('accounting.partials.money-mask')` sekali di view. Input terdampak:
- `accounting/journal.blade.php`: amount (Tambah Transaksi), amount (Transfer), opening_balance (Kelola Akun — update & tambah).
- `accounting/distribution.blade.php`: value aturan distribusi.
- `accounting/assumption.blade.php`: amount biaya tetap (tambah & update). *(margin_pct = persen, BUKAN di-mask.)*
- `accounting/target.blade.php`: target_operasional, target_order.

> Atribut `min="0"` boleh tetap (tak berpengaruh pada `type=text`; validasi server tetap `min:0`).

## 3. Bagian 2 — Export CSV & PDF

### 3a. Jurnal Kas (mengikuti filter year/month/jenis/account)
`CashEntryController`:
- **`exportCsv(Request)`** — baca year/month/jenis/account seperti `index`, panggil `service->compute(...)`. Kembalikan `response()->streamDownload`:
  - Header baris pertama = **BOM UTF-8** (`\xEF\xBB\xBF`) lalu `fputcsv($h, [...], ';')` (delimiter `;` agar rapi di Excel-ID).
  - Kolom: `Tanggal, Kode, Keterangan, Akun, Kategori, Produk, Pemasukan, Pengeluaran, Saldo, Ref`. Tiap entri → transfer ditandai di Keterangan (sudah "Transfer: ..."). Nominal ditulis angka polos.
  - Nama file `Jurnal_Kas_{year}_{month|semua}.csv`, header `Content-Type: text/csv; charset=UTF-8`.
- **`exportPdf(Request)`** — sama, `Pdf::loadView('accounting.pdf.journal', $data)->download('Jurnal_Kas_...pdf')`.

**View PDF** `resources/views/accounting/pdf/journal.blade.php` — HTML standalone (tanpa master), tabel ringkas + header periode + ringkasan (opening, totalIn, totalOut, saldoAkhir). Format Rp.

### 3b. Rekap bulanan (per tahun)
`AccountingDashboardController` (sudah inject `CashRecapService`):
- **`exportCsv(Request)`** — `year`; `recap = monthlyRecap($year)`, `ytd = ytd($year)`. streamDownload CSV (`;` + BOM): kolom `Bulan, Pemasukan, Pengeluaran, Laba, Saldo Akhir`; 12 baris + baris `YTD`. Nama `Rekap_{year}.csv`.
- **`exportPdf(Request)`** — `Pdf::loadView('accounting.pdf.recap', compact('year','recap','ytd'))->download('Rekap_{year}.pdf')`.

**View PDF** `resources/views/accounting/pdf/recap.blade.php` — tabel 12 bulan + YTD.

### 3c. Rute (grup `superadmin|accounting`)
```php
Route::get('accounting/journal/export/csv', [CashEntryController::class, 'exportCsv'])->name('accounting.journal.export.csv');
Route::get('accounting/journal/export/pdf', [CashEntryController::class, 'exportPdf'])->name('accounting.journal.export.pdf');
Route::get('accounting/recap/export/csv', [AccountingDashboardController::class, 'exportCsv'])->name('accounting.recap.export.csv');
Route::get('accounting/recap/export/pdf', [AccountingDashboardController::class, 'exportPdf'])->name('accounting.recap.export.pdf');
```

### 3d. Tombol
- `journal.blade.php` (dekat filter): "Export CSV" & "Export PDF" — link `route('accounting.journal.export.csv', request()->query())` (meneruskan filter aktif). Idem PDF.
- `dashboard.blade.php`: "Export Rekap CSV" & "Export Rekap PDF" — link dgn `['year'=>$year]`.

## 4. Bagian 3 — Ringkasan Keuangan (landing)

**Kontroler baru** `AccountingOverviewController` (inject `CashJournalService`, `CashRecapService`, `BudgetTargetService`):
- `index(Request)`: `year` (default kini). Kirim:
  - `balances = journalService->accountBalances()` (rows + total).
  - `ytd = recapService->ytd($year)` (totalIn/totalOut/laba/saldoAkhir/…).
  - `achievement = budgetService->monthlyAchievement($year)`; hitung `ytdRealisasi = Σ realisasi`, `target = CashSetting::singleton()->target_operasional`, `ytdTarget = target*12`, `pct`.
  - `fixedMonthly = CashFixedExpense::where('active',true)->get()->sum(fn($e)=>$e->monthlyAmount())`.
  - `year`.
- Rute: `GET accounting/overview` name `accounting.overview` (grup `superadmin|accounting`).

**View** `resources/views/accounting/overview.blade.php`:
- Filter tahun.
- **KPI cards**: Total Saldo Semua Akun (`balances.total`) · Pemasukan YTD · Pengeluaran YTD · Laba YTD.
- **Saldo per akun** (kartu kecil dari `balances.rows`, badge peran).
- **Target vs Realisasi YTD** (realisasi vs ytdTarget + % badge) · **Total Biaya Tetap/bln** (`fixedMonthly`).
- **Pintasan**: tombol ke Jurnal Kas · Dashboard · Distribusi · Asumsi · Anggaran & Target + Export (CSV/PDF).

**Sidebar** `layouts/sidebar.blade.php` (grup Keuangan, sebelum item Jurnal Kas di ~baris 87): tambah item **"Ringkasan"** → `route('accounting.overview')` (ikon `pie-chart`/`activity`).

## 5. Rencana Test

**Feature `AccountingExportTest`** (setUp roles seperti test akuntansi lain; accounting dari migrasi):
- `journal_csv_download`: seed 1 entri; GET `accounting.journal.export.csv` (accounting) → 200, header `Content-Type` mengandung `text/csv`; `$response->streamedContent()` memuat keterangan entri + header kolom `Pemasukan`.
- `journal_pdf_download`: GET `accounting.journal.export.pdf` → 200, header `Content-Type` mengandung `application/pdf`.
- `recap_csv_download`: GET `accounting.recap.export.csv?year=2026` → 200 + `text/csv` + memuat `YTD`.
- `recap_pdf_download`: 200 + `application/pdf`.
- `marketing_cannot_export`: marketing GET journal.export.csv → 403.

**Feature `AccountingOverviewTest`**:
- `overview_shows_kpis`: accounting GET `accounting.overview` → 200 + `assertSee('Total Saldo')` + `assertSee('Laba')` (YTD) + `assertSee('Kas Pemasukan')` (kartu akun).
- `marketing_cannot_access`: 403.

**Mask (regresi perilaku):** halaman `accounting.journal` (accounting) → 200 + `assertSee('money-mask')` (class ada). Store dgn angka polos tetap lolos (test lama `AccountingJournalTest` tak berubah).

**Regresi:** suite hijau; `php artisan view:cache` bersih.

## 6. Komponen

- **Baru:** `AccountingOverviewController`; views `accounting/overview.blade.php`, `accounting/pdf/journal.blade.php`, `accounting/pdf/recap.blade.php`, `accounting/partials/money-mask.blade.php`; method `CashEntryController::exportCsv/exportPdf`, `AccountingDashboardController::exportCsv/exportPdf`; test `AccountingExportTest`, `AccountingOverviewTest`.
- **Diubah:** `routes/web.php` (+5 rute); `sidebar.blade.php` (+menu Ringkasan); views `journal`/`dashboard`/`distribution`/`assumption`/`target` (mask + tombol export).
- **Tak diubah:** service/model existing (dipakai apa adanya).

## 7. Asumsi & Risiko

- **CSV** dengan delimiter `;` + BOM UTF-8 → terbuka rapi di Excel locale ID. Tetap file teks (bukan .xlsx).
- **Mask**: input jadi `type=text`; `removeMaskOnSubmit`+`autoUnmask` menjaga nilai terkirim tetap numerik. Bila JS mati, input tetap fungsional (teks angka; server tetap validasi numeric). Margin (%) tidak di-mask.
- **PDF** pakai dompdf (`->download()`), pola sama dgn Arsip/Invoice (risiko rendah).
- **Overview** read-only (agregasi service yang ada) → risiko rendah; tak menulis data.
- Tidak menambah dependency composer/npm.
