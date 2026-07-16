# Spec — Backfill Payment ke Jurnal Kas

- **Tanggal:** 2026-07-17
- **Branch:** `payment-cash-backfill`
- **Scope:** Menarik SEMUA payment `paid` lama menjadi entri Jurnal Kas (sekali jalan, lewat migrasi data), sehingga modul Akuntansi mencerminkan kenyataan.
- **Di luar scope:** mengubah `PaymentCashSyncService` (sudah benar); UI; saldo awal manual; penyatuan kode sumber angka (backlog #1 versi refactor).
- **Keputusan user:** **backfill semua** (bukan dari tanggal tertentu, bukan saldo awal saja) — agar Rekap Bulanan, Dashboard, Distribusi Profit, dan Target punya rincian historis yang bisa ditelusuri.

## Masalah (diukur, bukan diduga)

Diukur di DB dev 2026-07-17:

```
Laporan Keuangan (pemasukan 2026) : 80.600.000
Jurnal Kas       (totalIn   2026) :  6.500.000
SELISIH                           : 74.100.000

Entri kas total     : 1  (dari payment: 1)
Payment paid th ini : 123
Saldo awal          : 0 di semua akun (Kas Pemasukan/Operational/Harta)
Refund              : 0 (belum pernah ada)
```

`PaymentCashSyncService` dijalankan `PaymentObserver` **saat payment disimpan**. Payment yang sudah ada sebelum fitur akuntansi dibangun tak pernah tersentuh — hanya 1 entri lahir, dari payment yang kebetulan tersimpan ulang setelahnya.

Desain aslinya sengaja "maju-saja", **dengan asumsi** saldo awal diisi manual dari Excel (~50jt) agar tak dobel. Asumsi itu tak pernah terjadi: saldo awal = 0. Akibatnya seluruh Fase A–E berdiri di atas 1 dari 123 transaksi:

| Halaman | Kondisi sekarang |
|---|---|
| Jurnal Kas | 1 baris, saldo 6,5jt |
| Dashboard Keuangan | KPI & grafik dari 1 transaksi |
| Rekap Bulanan | 11 bulan kosong |
| **Distribusi Profit** | membagi laba yang **74jt terlalu kecil** |
| Anggaran & Target | realisasi ~0% padahal uang masuk 80,6jt |

**Alasan "maju-saja" sudah gugur.** Ia ada untuk mencegah dobel-hitung dengan saldo awal; saldo awalnya nol, jadi tak ada yang bisa dobel.

## Pendekatan: migrasi data memanggil service

Mengikuti preseden repo: `database/migrations/2026_07_02_000010_backfill_order_title_id.php` — migrasi tipis yang memanggil `TitleBackfillService`, `down()` no-op.

Dipilih dibanding perintah artisan karena **live deploy menjalankan `php artisan migrate`** — backfill ikut jalan sendiri. Perintah artisan menaruh beban ingatan pada manusia, dan backfill yang lupa dijalankan sama saja tidak ada.

**Tanpa logika sinkron baru.** `PaymentCashSyncService::sync()` sudah `updateOrCreate` per `payment_id` (kolom unik) → idempotent secara alami. Backfill hanya memanggilnya untuk tiap payment `paid`. Dijalankan dua kali → hasil sama. Refund ikut benar tanpa kode tambahan (`sync()` memetakan refund → `jenis = 'pengeluaran'`).

## 1. Komponen baru — `app/Services/PaymentCashBackfillService.php`

```php
namespace App\Services;

use App\Models\CashAccount;
use App\Models\CashSetting;
use App\Models\Payment;
use RuntimeException;

class PaymentCashBackfillService
{
    /**
     * Tarik semua payment 'paid' jadi entri kas. Idempotent (sync() updateOrCreate
     * per payment_id). Menolak bila saldo awal sudah diisi — lihat guardOpening().
     *
     * @return array{synced:int}
     */
    public function run(): array
    {
        $this->guardOpening();

        $sync = app(PaymentCashSyncService::class);
        $synced = 0;
        Payment::where('status', 'paid')->orderBy('id')
            ->chunkById(200, function ($payments) use ($sync, &$synced) {
                foreach ($payments as $payment) {
                    $sync->sync($payment);
                    $synced++;
                }
            });

        return ['synced' => $synced];
    }

    /**
     * Backfill aman HANYA bila saldo awal nol — kalau saldo awal sudah diisi
     * (mewakili transaksi lama secara ringkas), menariknya lagi = dobel-hitung
     * pembukuan. Berhenti keras dan minta keputusan manusia, jangan diam-diam
     * menggandakan.
     */
    private function guardOpening(): void
    {
        $accounts = (float) CashAccount::sum('opening_balance');
        $global   = (float) (CashSetting::singleton()->saldo_awal ?? 0);

        if ($accounts != 0.0 || $global != 0.0) {
            throw new RuntimeException(
                'Backfill dibatalkan: saldo awal sudah diisi (akun: ' . $accounts . ', global: ' . $global . '). '
                . 'Menarik payment lama akan dobel-hitung. Nolkan saldo awal dulu, atau lewati backfill ini.'
            );
        }
    }
}
```

> **Kenapa melempar exception, bukan diam-diam melewati?** Migrasi yang gagal menghentikan deploy — keras, tapi tepat: alternatifnya merusak pembukuan diam-diam. Bila guard ini menyala, situasinya butuh penilaian manusia. Di dev/live saat ini saldo awal = 0, jadi guard tak akan menyala.

## 2. Komponen baru — migrasi data

`database/migrations/2026_07_17_000001_backfill_payments_to_cash_entries.php`:

```php
use App\Services\PaymentCashBackfillService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new PaymentCashBackfillService())->run();
    }

    public function down(): void
    {
        // no-op: entri kas hasil backfill dibiarkan saat rollback.
        // Menghapusnya berisiko membuang entri yang sudah disunting manual.
    }
};
```

## 3. Testing — `tests/Feature/PaymentCashBackfillTest.php` (baru)

Fixture: payment dibuat **tanpa** memicu observer tidak mungkin (observer terpasang), jadi test membuat payment lalu **menghapus entri kasnya** (`CashEntry::truncate()`) untuk meniru keadaan "payment lama tanpa entri".

- `backfills_existing_payments`: 3 payment paid (2 artikel, 1 buku) + entri kas dikosongkan → `run()` → 3 entri, `synced` = 3, jenis semua `pemasukan`, produk benar.
- `backfill_is_idempotent`: jalankan `run()` dua kali → tetap 3 entri (bukan 6).
- `backfills_refund_as_expense`: payment refund `paid` → entri `jenis = 'pengeluaran'` (aturan sudah ada, dikunci agar backfill tak melewatkannya).
- `skips_unpaid_payments`: payment `pending` → tak jadi entri.
- `refuses_when_opening_balance_set`: `CashAccount` opening_balance = 1 → `run()` melempar `RuntimeException` **dan tidak membuat entri apa pun** (assert jumlah entri tak berubah).
- **`report_matches_journal_after_backfill`** (kunci): setelah backfill, `FinancialReportService::pemasukan(null)['kpi']['total']` == `CashRecapService::ytd($tahun)['totalIn']`. Inilah yang tadi hanya berlaku di fixture kecil — kini berlaku untuk seluruh riwayat.

Regresi: suite penuh hijau. **Bila test lama gagal karena kini ada entri kas yang dulu tak ada, itu temuan — laporkan.**

## 4. Verifikasi di data nyata (bagian dari pekerjaan, bukan opsional)

Setelah `php artisan migrate` di DB dev, ukur ulang perbandingan yang membuka kasus ini:

```
Laporan Keuangan (pemasukan 2026) vs Jurnal Kas (totalIn 2026)
Target: SELISIH = 0
```

Angka itu buktinya — bukan test hijau. Test membuktikan aturannya benar; angka ini membuktikan data nyatanya sembuh.

## 5. Risiko

- **Menulis ~135 baris ke `tb_cash_entries`** di dev dan (saat deploy) live. Idempotent + guard saldo awal. `down()` no-op disengaja: menghapus massal lebih berisiko daripada meninggalkan entri.
- **Angka semua halaman Keuangan berubah drastis** (6,5jt → ~80,6jt). Itu tujuannya — angka lama tak mencerminkan apa pun.
- **Distribusi Profit ikut berubah** karena labanya kini nyata. Bila ada distribusi yang sudah dibayar berdasar laba lama, itu **keputusan bisnis** — diangkat ke user, di luar scope.
- **Kategori tak terpetakan:** `sync()` memakai `map_key` dari tipe order; bila ada tipe tak dikenal, `cash_category_id` = null (entri tetap dibuat, tampil "Tanpa Kategori"). Tak menggugurkan backfill; dilaporkan bila banyak.

## 6. Komponen

- **Baru:** `app/Services/PaymentCashBackfillService.php`; `database/migrations/2026_07_17_000001_backfill_payments_to_cash_entries.php`; `tests/Feature/PaymentCashBackfillTest.php`.
- **Tak diubah:** `PaymentCashSyncService` (dipakai apa adanya), `PaymentObserver`, UI, skema tabel.
