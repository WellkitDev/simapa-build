# Spec — Definisi Kanonik Pemasukan (refund berhenti menggelembungkan)

- **Tanggal:** 2026-07-17
- **Branch:** `income-definition-refund`
- **Scope:** Satu definisi kanonik "uang masuk" — `Payment::income()` = `status paid` DAN `payment_type != refund` — dipakai oleh Laporan Keuangan, Dashboard Marketing, dan Target Marketing.
- **Di luar scope:** Jurnal Kas (sudah benar); aturan/alur refund; UI; audit refund bulanan (backlog terpisah); penyatuan sumber angka sepenuhnya (backlog #1 utuh).
- **Keputusan user:** Pemasukan = **kotor** (refund dikecualikan, bukan dikurangkan) · Realisasi target = **kotor**, komisi **tidak dipotong otomatis**; refund ditinjau tim audit tiap akhir bulan, "jika murni kesalahan marketing maka ditanggung marketing" (proses manusia, bukan formula) · Prinsip: **"jika ada refund maka ada pengeluaran"**.

## Masalah (bug berjalan, bukan risiko teoretis)

`Payment::scopeApproved()` (`Payment.php:44`) hanya `where('status', 'paid')`. Refund disimpan sebagai Payment `status = 'paid'`, `payment_type = 'refund'` (`RefundController.php:66-70`). Maka refund **lolos sebagai pemasukan**.

Dibuktikan dengan probe (order dibayar 10jt, direfund 4jt):

```
Pemasukan menurut FinancialReportService : 14.000.000
Seharusnya                               : 10.000.000
```

Salahnya dobel — uang **keluar** malah **ditambahkan** (+4jt, bukan 0). Terdampak:

| Tempat | Baris | Dampak |
|---|---|---|
| `FinancialReportService::pemasukan` | `:23` | KPI + rekap tahunan/bulanan + detail kelebihan |
| `FinancialReportService::piutang` | `:52` | `total_paid` kelebihan → **sisa piutang kekecilan** |
| `MarketingDashboardService` | `:23` | KPI harian/mingguan/tahunan, tren, realisasi |
| `MarketingTargetService` | `:23` | realisasi menggelembung → **komisi kelebihan bayar** |
| Jurnal Kas / Dashboard Keuangan | — | **benar** (refund → pengeluaran) |

`RefundController.php:24` sudah menulis `where('payment_type', '!=', 'refund')` manual — pengetahuannya ada, tapi tak pernah naik ke `approved()`. Inilah alasan backlog #1 ada: definisi "uang masuk" tersebar, satu kekhilafan lolos ke 4 tempat.

## Keputusan: kotor, bukan bersih

Pemasukan tetap **10jt**; refund muncul di sisi **pengeluaran** (Jurnal Kas). Alasan:

1. **Cocok dengan Jurnal Kas.** Jurnal Kas mencatat refund sebagai `jenis = 'pengeluaran'` (`PaymentCashSyncService:40`, dikunci `PaymentCashSyncTest::refund_creates_expense_entry`), sehingga `totalIn` di sana tetap kotor. Membuat Laporan Keuangan jadi bersih (6jt) justru melahirkan divergensi **baru** — penyakit yang sedang diobati. Kotor membuat keduanya **sepakat**.
2. **Lazim di akuntansi.** Pemasukan dan refund dilaporkan terpisah, tidak saling menghapus.
3. **Refund tetap terlihat.** Pemasukan bersih 6jt ambigu (bayar 6jt? atau bayar 10jt lalu refund 4jt?).

Sejalan dengan prinsip user: **"jika ada refund maka ada pengeluaran"** — dan pengeluaran itu sudah otomatis dibuat `PaymentObserver` → `PaymentCashSyncService`.

## Konsekuensi yang disengaja

Setelah perbaikan ini refund **tidak muncul** di modul Laporan Keuangan (modul itu hanya Pemasukan/Piutang/Order Selesai — tak punya halaman pengeluaran). Refund hidup di Jurnal Kas. Batas yang dipilih sadar: **Laporan Keuangan = penjualan/operasional; Jurnal Kas = kas.** Bila kelak refund perlu tampil di Laporan Keuangan, itu penambahan halaman — bukan alasan mengubah definisi pemasukan.

## 1. Komponen — `App\Models\Payment`

Tambah scope kanonik, di bawah `scopeApproved`:

```php
/** Uang masuk kanonik: pembayaran diterima, BUKAN refund (refund = pengeluaran, lihat PaymentCashSyncService). */
public function scopeIncome($query)
{
    return $query->where('status', 'paid')->where('payment_type', '!=', 'refund');
}
```

**`scopeApproved()` DIPERTAHANKAN**, tidak dihapus dan tidak diubah. Artinya "pembayaran sudah disetujui" — sah dipakai konteks non-pemasukan (mis. menghitung apakah order lunas, di mana refund memang relevan). Yang diperbaiki hanya pemanggil yang bertanya *"berapa uang masuk"*. Menghapus `approved()` akan menyeret perubahan ke tempat yang tak diminta dan tak diuji di siklus ini.

## 2. Pemanggil yang pindah ke `income()`

| File | Baris | Perubahan |
|---|---|---|
| `FinancialReportService::pemasukan` | 23 | `Payment::approved()` → `Payment::income()` |
| `FinancialReportService::piutang` | 52 | `fn ($q) => $q->approved()` → `$q->income()` |
| `MarketingDashboardService` | 23 | `Payment::approved()` → `Payment::income()` |
| `MarketingTargetService` | 23 | `Payment::approved()` → `Payment::income()` |

Komentar `MarketingDashboardService:22` ("Definisi kanonik (sama dengan FinancialReportService)") diperbarui agar menunjuk `Payment::income()` sebagai sumber definisi.

`RefundController:24` (`->where('payment_type', '!=', 'refund')` manual) **dibiarkan** — ia menghitung "sudah dibayar untuk keperluan refund", konteks berbeda, dan mengubahnya di luar scope.

## 3. Testing — `tests/Feature/IncomeDefinitionTest.php` (baru)

Fixture bersama: order 10jt milik marketing, Payment dp 10jt `paid`, Payment refund 4jt `paid`.

- `pemasukan_excludes_refund`: `FinancialReportService::pemasukan(null)['kpi']['total']` === **10jt** (bukan 14jt).
- `piutang_paid_excludes_refund`: order belum lunas, `piutang(null)['kpi']['dibayar']` === **10jt** → `sisa` benar.
- `marketing_dashboard_income_excludes_refund`: KPI `income` tahunan === **10jt**.
- `marketing_target_realisasi_excludes_refund`: realisasi === **10jt**, komisi = rate × 10jt (bukan 14jt).
- **`laporan_keuangan_matches_jurnal_kas`** (kunci anti-divergensi): setelah Payment tersinkron (PaymentObserver), `FinancialReportService::pemasukan(null)['kpi']['total']` === `CashRecapService::ytd($tahun)['totalIn']`. Membuktikan dua modul **sepakat**, sehingga divergensi ini tak bisa lahir lagi diam-diam.
- `refund_still_recorded_as_expense`: entri kas refund tetap `jenis = 'pengeluaran'` — perbaikan ini tidak menghilangkan prinsip "ada refund maka ada pengeluaran".

Regresi: suite penuh tetap hijau. Bila ada test lama yang mengunci angka lama (mengandung refund), itu **temuan** — laporkan, jangan diam-diam disesuaikan.

## 4. Risiko

- **Angka laporan berubah** bagi order yang pernah direfund — itu tujuannya (angka lama salah). Angka tanpa refund tidak bergeser sama sekali.
- **Komisi historis:** bila komisi pernah dibayar atas realisasi yang menggelembung, koreksinya **keputusan bisnis**, bukan kode. Di luar scope; diangkat ke user.
- `approved()` tetap dipakai di tempat lain — sengaja, lihat §1.

## 5. Komponen

- **Diubah:** `app/Models/Payment.php` (+scope `income`); `FinancialReportService.php` (2 baris); `MarketingDashboardService.php` (1 baris + komentar); `MarketingTargetService.php` (1 baris).
- **Baru:** `tests/Feature/IncomeDefinitionTest.php`.
- **Tak diubah:** Jurnal Kas, `PaymentCashSyncService`, `RefundController`, UI, skema (tanpa migrasi).

## 6. Backlog yang lahir dari sini

- **Audit refund bulanan (Report Marketing)** — tampilkan daftar refund per marketing per bulan + alasan, sebagai bahan rapat tim audit. Mulai dari read-only. Atribusi penyebab terstruktur (`refund_reason` kini teks bebas; tak ada field "siapa salah") + mekanisme "ditanggung marketing" (potong komisi? catat utang?) menyusul **bila** memang perlu ditegakkan sistem — menyentuh uang orang, butuh jejak audit + persetujuan + ruang membantah.
- **Backlog #1 utuh** (satukan sumber angka) tetap terbuka: `laporan_keuangan_matches_jurnal_kas` baru mengunci satu titik (total pemasukan setahun), belum menyatukan sumbernya.
