# Spec — Peringatan Celah Pengeluaran

- **Tanggal:** 2026-07-17
- **Branch:** `expense-gap-warning`
- **Scope:** Peringatan jujur di tiga halaman yang menampilkan "laba" (Distribusi Profit, Dashboard Keuangan, Ringkasan) ketika **tak ada pengeluaran tercatat** untuk periode itu.
- **Di luar scope:** membuat entri kas dari Asumsi (**ditolak sadar**, lihat §Keputusan); mengubah cara laba dihitung; UI Jurnal Kas; kunci periode + audit log (backlog terpisah).
- **Keputusan user:** Asumsi tetap **murni referensi** — hanya pengeluaran nyata di Jurnal Kas yang memotong laba. Halaman cukup **memperingatkan**.

## Masalah (terungkap setelah backfill)

Backfill (`fe9b99f`) membuat sisi pemasukan jujur: Jurnal Kas kini 80,6jt, cocok dengan Laporan Keuangan. Tapi pengukuran di DB dev 2026-07-17 menunjukkan:

```
Entri pengeluaran : 0  (belum pernah ada satu pun)
Dashboard "Laba"  : Rp 80.600.000   ← sebenarnya pemasukan kotor
Biaya tetap Asumsi: Rp 824.167/bln  (6 item aktif) — tak pernah jadi entri kas
```

Jadi "Laba" di Dashboard Keuangan **bukan laba** — tak ada biaya yang dikurangkan, karena tak ada yang pernah dicatat. Distribusi Profit membagi angka itu: 5% Harta, 10% Saving, 85% Fee Tim. Risikonya nyata — membayarkan porsi tim dari uang yang seharusnya menutup biaya.

## Keputusan: peringatan, BUKAN entri otomatis dari Asumsi

Usul awal (tombol "catat biaya tetap bulan ini") **ditolak setelah melihat datanya**:

| Biaya | Periode | Nilai | `monthlyAmount()` |
|---|---|---|---|
| Hosting Avidpedia | tahunan | 975.000/thn | 81.250 |
| Hosting Jurnal | tahunan | 1.755.000/thn | 146.250 |
| Domain Avidpedia | tahunan | 205.000/thn | 17.083 |
| Domain Jurnal | tahunan | 205.000/thn | 17.083 |
| Keanggotaan DOI | tahunan | 750.000/thn | 62.500 |
| Saving Bulanan | bulanan | 500.000 | 500.000 |

Lima dari enam **tahunan**. `monthlyAmount()` = alokasi akuntansi, bukan peristiwa kas: hosting dibayar 975.000 **sekali setahun**, bukan 81.250 tiap bulan. Mem-posting nilai bulanan ke **buku kas** (yang tugasnya mencatat pergerakan uang nyata) = mengarang transaksi yang tak pernah terjadi — merusak pembukuan dari arah sebaliknya, dan lebih sulit dideteksi karena angkanya terlihat wajar.

Lebih telak: total biaya tetap hanya **824rb/bulan**. Pengeluaran nyata (gaji tim, operasional) tak ada di daftar itu. Jembatan Asumsi→Jurnal Kas hanya menutup sebagian kecil celah sambil menciptakan **ilusi bahwa celahnya sudah tertutup** — lebih berbahaya daripada celah yang jelas menganga.

Asumsi tak punya tanggal jatuh tempo, kategori, maupun akun — bukti struktural bahwa ia memang dirancang sebagai referensi perencanaan (untuk margin & anggaran), bukan sumber entri kas. **Yang kurang bukan fiturnya (form Jurnal Kas sudah ada), tapi kebiasaan mencatatnya.** Tugas kode: membuat celah itu terlihat di tempat keputusan diambil.

## 1. Komponen baru — `app/Services/ExpenseGapService.php`

Satu rumah untuk satu konsep; tiga halaman memakainya — tak ada logika yang diduplikasi (pelajaran dari bug refund: definisi tersebar = divergensi).

```php
namespace App\Services;

use App\Models\CashEntry;
use App\Models\CashFixedExpense;

class ExpenseGapService
{
    /**
     * Periksa apakah periode ini tak punya pengeluaran tercatat sama sekali.
     * $month null → satu tahun penuh.
     *
     * @return array{recorded:float, fixedMonthly:float, hasGap:bool}
     */
    public function check(int $year, ?int $month = null): array
    {
        $q = CashEntry::whereYear('tanggal', $year)
            ->where('jenis', 'pengeluaran')
            ->where('is_transfer', false);

        if ($month !== null) {
            $q->whereMonth('tanggal', $month);
        }

        $recorded = (float) $q->sum('amount');

        return [
            'recorded'     => $recorded,
            'fixedMonthly' => (float) CashFixedExpense::where('active', true)->get()
                                ->sum(fn (CashFixedExpense $e) => $e->monthlyAmount()),
            'hasGap'       => $recorded == 0.0,
        ];
    }
}
```

**Transfer internal dikecualikan** (`is_transfer = false`) — konsisten dengan `CashRecapService`, karena pemindahan antar akun sendiri bukan pengeluaran.

### Kenapa ambangnya NOL, bukan "kurang dari biaya tetap"

Peringatan muncul **hanya** bila pengeluaran tercatat = 0 — keadaan yang tak mungkin benar (perusahaan selalu punya biaya) dan selalu layak diteriaki. Ambang "lebih kecil dari biaya tetap" akan menyala di bulan yang biayanya memang rendah; peringatan yang sering salah akan cepat diabaikan, dan peringatan yang diabaikan sama saja tak ada.

## 2. Komponen baru — `resources/views/accounting/partials/expense-warning.blade.php`

Mengikuti pola partial yang sudah ada (`accounting/partials/money-mask`). Menerima `$gap` (hasil `check()`) dan `$periodeLabel` (mis. "bulan ini" / "tahun 2026").

```blade
@if($gap['hasGap'])
    <div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
        <span>⚠</span>
        <div>
            <strong>Belum ada pengeluaran tercatat {{ $periodeLabel }}.</strong>
            Angka laba di bawah adalah <strong>pemasukan kotor</strong> — belum dikurangi biaya apa pun.
            @if($gap['fixedMonthly'] > 0)
                Menurut <a href="{{ route('accounting.assumption') }}">Asumsi</a>, ada
                <strong>Rp {{ number_format($gap['fixedMonthly'], 0, ',', '.') }}/bulan</strong>
                biaya tetap yang belum masuk Jurnal Kas.
            @endif
            <a href="{{ route('accounting.journal') }}">Catat pengeluaran di Jurnal Kas →</a>
        </div>
    </div>
@endif
```

## 3. Pemasangan (3 halaman)

| Controller | Periode | Data dikirim | View |
|---|---|---|---|
| `ProfitDistributionController@index` | bulan (`$year`,`$month`) | `gap` = `check($year,$month)`, `periodeLabel` = nama bulan + tahun | `accounting/distribution` — di atas kartu hasil distribusi |
| `AccountingDashboardController@index` | tahun (`$year`) | `gap` = `check($year)`, `periodeLabel` = "sepanjang {$year}" | `accounting/dashboard` — di atas kartu KPI |
| `AccountingOverviewController@index` | tahun (`$year`) | `gap` = `check($year)`, `periodeLabel` = "sepanjang {$year}" | `accounting/overview` — di atas kartu KPI |

`AccountingOverviewController` sudah menghitung `$fixedMonthly` untuk keperluannya sendiri — **dibiarkan**, tidak digabung: ia dipakai menampilkan biaya tetap sebagai informasi, beda maksud dari peringatan. Menggabungkannya akan menautkan dua kebutuhan yang kebetulan memakai angka sama.

**Tidak dipasang di** `accounting.target` (Anggaran & Target — soal realisasi pemasukan vs target, bukan laba) dan `accounting.journal` (jurnalnya sendiri; kalau kosong sudah kelihatan).

## 4. Testing — `tests/Feature/ExpenseGapTest.php` (baru)

- `no_expense_recorded_sets_gap`: 1 payment masuk, nol pengeluaran → `check()['hasGap']` true, `recorded` = 0.
- `recorded_expense_clears_gap`: ada 1 entri pengeluaran manual → `hasGap` false.
- `transfer_is_not_an_expense`: hanya ada entri transfer internal (`is_transfer = true`) → `hasGap` tetap **true** (transfer bukan pengeluaran).
- `month_scope_is_independent`: pengeluaran di Januari, diperiksa Februari → `hasGap` true untuk Februari, false untuk Januari.
- `warning_appears_on_three_pages`: superadmin GET `accounting.distribution`, `accounting.dashboard`, `accounting.overview` → ketiganya `assertSee('Belum ada pengeluaran tercatat')`.
- `warning_absent_when_expense_exists`: dgn entri pengeluaran → ketiga halaman `assertDontSee('Belum ada pengeluaran tercatat')`.
- **`warning_does_not_change_distribution`** (kunci): laba yang dibagi tetap sama persis dengan/tanpa peringatan — membuktikan peringatan tidak diam-diam memotong apa pun (keputusan user: hanya kas nyata yang memotong).

Regresi: suite penuh (529 + baru) hijau; `php artisan view:cache` bersih.

## 5. Risiko

- **Peringatan diabaikan** bila terlalu sering muncul — dijaga dengan ambang nol (§1).
- **`accounting.assumption` / `accounting.journal` route** dipakai di partial; bila namanya berubah, halaman 500. Dikunci test render ketiga halaman.
- Peringatan **tidak** mengubah angka apa pun — dikunci `warning_does_not_change_distribution`.

## 6. Komponen

- **Baru:** `app/Services/ExpenseGapService.php`; `resources/views/accounting/partials/expense-warning.blade.php`; `tests/Feature/ExpenseGapTest.php`.
- **Diubah:** `ProfitDistributionController.php`, `AccountingDashboardController.php`, `AccountingOverviewController.php` (masing-masing +2 baris data); `accounting/distribution.blade.php`, `accounting/dashboard.blade.php`, `accounting/overview.blade.php` (masing-masing +1 `@include`).
- **Tak diubah:** Asumsi (tetap referensi), `CashRecapService`, `ProfitDistributionService`, skema (tanpa migrasi).
