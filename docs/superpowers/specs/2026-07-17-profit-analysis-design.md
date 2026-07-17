# Spec — Analisa Profit (margin per order → siap dibagi)

- **Tanggal:** 2026-07-17
- **Branch:** `profit-analysis`
- **Scope:** Halaman **Analisa Profit**: tiap uang masuk dipecah pakai margin Asumsi menjadi **cadangan APC** dan **siap dibagi**; ringkasan bulan + rincian per order + **akumulasi 12 bulan**; tombol menyambung ke Distribusi Profit.
- **Di luar scope:** mengubah `ProfitDistributionService`/halaman Distribusi Profit (dipakai apa adanya lewat `?profit=`); biaya aktual per order (butuh `order_id` di entri kas — **tak ada**, lihat §Batasan); impor CSV keuangan (ditunda user); data dummy (**tak perlu** — lihat §Konteks).
- **Keputusan user:** dasar hitung = **margin asumsi saja** (bukan biaya aktual) · order tanpa indeksasi → **margin terendah 25%, ditandai & didaftar** · tambahkan **akumulasi 12 bulan**.

## Konteks: datanya sudah ada, tak perlu dummy

Diukur 2026-07-17:

```
Payment paid s/d Juni : 132  (Rp 90.000.000)
Jenis order           : at_kolab 53 · at_mandiri 17 · bk_kolab 27 · bk_mandiri 4
Indeksasi order       : sinta 2 (32) · sinta 4 (29) · sinta 3 (5) · coppernicus (3) · null (31)
Margin di Asumsi      : M_ART_S2 25% · M_ART_S4 30% · M_KOL_S2 25% · M_KOL_S4 30% · M_BK_ALL 87%
```

Semua bahan hitungan (payment, jenis order, indeksasi, margin) **sudah terisi data sungguhan**. Margin asumsi tak memerlukan pengeluaran, jadi halaman ini langsung hidup tanpa data dummy. **Backlog "Sinta tier pada order" ternyata sudah selesai sebagian** — kolom `tb_order_details.indexation` sudah ada dan terisi 65 dari 96 order.

## Pertanyaan user, dijawab

> *"1 orderan artikel kolaborasi dengan DP 1,5jt — berapa tersimpan untuk APC, berapa untuk distribusi profit?"*

| Indeksasi judul | Kode margin | Siap dibagi | Cadangan APC |
|---|---|---|---|
| sinta 2–3 | `M_KOL_S2` 25% | **375.000** | 1.125.000 |
| sinta 4–6 | `M_KOL_S4` 30% | **450.000** | 1.050.000 |
| null / coppernicus | `M_KOL_S2` 25% *(default hati-hati, ditandai)* | 375.000 | 1.125.000 |

> *"1 orderan artikel mandiri 7,5jt lunas, APC 5,5jt → 2jt untuk distribusi"*

Contoh ini memakai **biaya aktual** (2jt = 26,67%), sedangkan Asumsi memberi 25% (1,875jt) atau 30% (2,25jt) — **tak satu pun cocok**. Keputusan user: **pakai margin asumsi**. Jadi order itu akan menghasilkan **1.875.000** (Sinta 2-3), bukan 2.000.000. Selisih dengan kenyataan adalah harga dari memakai asumsi; menutupnya butuh biaya aktual per order (§Batasan).

## Aturan pemilihan margin

```
jenis order (tb_order_details.type) + indeksasi (tb_order_details.indexation) → kode margin → margin_pct
```

| Type | Tier | Kode |
|---|---|---|
| `at_mandiri` | S2 | `M_ART_S2` |
| `at_mandiri` | S4 | `M_ART_S4` |
| `at_kolab` | S2 | `M_KOL_S2` |
| `at_kolab` | S4 | `M_KOL_S4` |
| `bk_*` | — (tanpa tier) | `M_BK_ALL` |

**Normalisasi tier** — data lapangan berantakan (`sinta 2` dan `SINTA 2` sama-sama ada):

- lowercase + trim, cocokkan pola `sinta\s*(\d)`
- angka **2, 3** → tier `S2` · **4, 5, 6** → tier `S4`
- tak cocok (null, `coppernicus`, kosong) → tier `S2` (**margin terendah = paling hati-hati**) + ditandai `unknownTier`

**Kenapa 25% untuk yang tak diketahui:** margin lebih rendah → cadangan APC lebih besar → angka "siap dibagi" lebih kecil. Salah ke arah aman: lebih baik kurang membagi daripada terlanjur membagi uang yang ternyata dibutuhkan untuk APC. Halaman menampilkan jumlah + daftar order tsb supaya bisa dibenahi; angkanya naik sendiri setelah indeksasi diisi.

**Margin tak ditemukan / nonaktif** di Asumsi → `pct = 0` dan baris ditandai `marginMissing`. Bukan menebak angka: nol berarti tak ada yang dibagi dari baris itu, dan halaman menyuruh mengatur Asumsi. Konsisten dengan prinsip "celah harus terlihat, bukan ditebak".

## Refund mengurangi

Iterasi **semua** payment `status = paid` di bulan itu; `payment_type = 'refund'` diberi tanda **negatif**. Profit = `pct% × base`, cadangan = `base − profit`. Refund 300rb pada order Sinta 2 → siap dibagi berkurang 75rb, cadangan berkurang 225rb.

Meniru `Order::paidNet()` yang sudah ada: uang keluar **mengurangi**, tak pernah menambah. (Konsisten dgn perbaikan `0e213b8`.)

## 1. Komponen baru — `app/Services/ProfitAnalysisService.php`

```php
/** Tier + margin utk satu OrderDetail. @return array{code:?string,pct:float,unknownTier:bool,marginMissing:bool} */
public function marginFor(?OrderDetail $detail): array

/** @return array{rows:array,totalIn:float,totalReserve:float,totalMargin:float,unknownTier:int,noOrder:int} */
public function forMonth(int $year, int $month): array

/** Akumulasi 12 bulan: month,label,totalIn,totalReserve,totalMargin. @return array<int,array> */
public function yearly(int $year): array
```

`rows` = satu baris per payment: `tanggal, code_order, judul, type, indexation, marginCode, pct, base, reserve, margin, unknownTier, marginMissing`.

Payment tanpa order/detail → dihitung di `noOrder`, **tak masuk** totalMargin (tak punya margin) — ditampilkan sebagai catatan, tidak disembunyikan.

## 2. Controller + rute + menu

- `app/Http/Controllers/Pages/ProfitAnalysisController.php@index` — filter `year` (default tahun ini) + `month` (default bulan ini).
- Rute `GET accounting/profit` → `accounting.profit`, di dalam grup `role:superadmin|accounting` yang sudah ada.
- Sidebar **Keuangan → Analisa Profit** (ikon `pie-chart`… sudah dipakai Ringkasan; pakai `trending-up`).

## 3. View — `resources/views/accounting/profit-analysis.blade.php`

1. **Kartu ringkasan bulan**: Uang Masuk · Cadangan APC · **Siap Dibagi** (menonjol) + tombol **"Bagi profit ini →"** → `route('accounting.distribution', ['year'=>$year,'month'=>$month,'profit'=>$totalMargin])`.
2. **Peringatan** bila `unknownTier > 0`: *"N order belum punya indeksasi — dihitung memakai margin terendah (25%). Tentukan indeksasinya agar angka ini akurat."*; bila `noOrder > 0`: *"N pemasukan tanpa order tak dihitung (tak punya margin)."*
3. **Akumulasi 12 bulan** (permintaan user): tabel Bulan · Uang Masuk · Cadangan APC · Pendapatan Margin + baris **TOTAL**.
4. **Rincian per order** (DataTables, pola `titles/index`): Tgl · Order · Judul · Jenis · Indeksasi · Margin% · Masuk · Cadangan · Siap Dibagi. Baris `unknownTier` diberi badge.

**Distribusi Profit tidak diubah** — ia sudah menerima `?profit=`. Menyambung ke yang sudah bekerja lebih baik daripada mengubahnya.

## 4. Batasan (ditulis di halaman, bukan disembunyikan)

- **Berbasis asumsi, bukan biaya nyata.** Kalau APC sesungguhnya lebih mahal dari cadangan, profit terlanjur kelebihan bagi. Menutupnya butuh biaya aktual per order — `tb_cash_entries` **tak punya `order_id`**, jadi sistem tak bisa tahu "APC 5,5jt itu untuk order mana". Itu epik tersendiri (backlog).
- **Hanya profit dari order.** Pemasukan non-order (mis. "pengembalian dana talang") tak punya margin → tak dihitung.
- **Tak menyentuh Jurnal Kas.** Halaman ini pembacaan; tak membuat entri apa pun.

## 5. Testing — `tests/Feature/ProfitAnalysisTest.php`

- **`kolaborasi_dp_sinta2_matches_user_example`**: order `at_kolab` indeksasi `sinta 2`, DP 1,5jt → margin **375.000**, cadangan **1.125.000**. (Contoh user, dikunci.)
- **`kolaborasi_dp_sinta4_uses_30_percent`**: indeksasi `sinta 4`, DP 1,5jt → margin **450.000**.
- **`mandiri_lunas_uses_assumption_not_actual`**: `at_mandiri` `sinta 2`, bayar 7,5jt → margin **1.875.000** (bukan 2jt) — mengunci keputusan "asumsi menang".
- `book_uses_87_percent`: `bk_kolab`, bayar 100rb → margin 87.000.
- `uppercase_indexation_is_normalised`: `SINTA 2` → 25% (bukan dianggap tak dikenal).
- `sinta3_uses_s2_and_sinta5_uses_s4`: batas bucket benar.
- **`unknown_indexation_uses_lowest_margin_and_is_flagged`**: indeksasi null → 25% + `unknownTier` = 1.
- **`refund_reduces_margin`**: bayar 1,5jt + refund 300rb (sinta 2) → margin 375.000 − 75.000 = **300.000**.
- `missing_margin_row_yields_zero_and_flag`: `M_BK_ALL` dinonaktifkan → buku pct 0 + `marginMissing`.
- `payment_without_order_is_counted_separately`: → `noOrder` = 1, tak menambah totalMargin.
- `other_months_are_not_mixed`: payment bulan lain tak ikut.
- `yearly_accumulates_12_months`: akumulasi = jumlah per bulan; bulan kosong = 0.
- `page_renders_and_links_to_distribution`: superadmin GET → 200, memuat "Siap Dibagi" + tautan `accounting.distribution` ber-`profit=`; `accounting` → 200; `marketing` → 403.

Regresi: suite penuh (553 + baru) hijau; `view:cache` bersih.

## 6. Komponen

- **Baru:** `app/Services/ProfitAnalysisService.php`; `app/Http/Controllers/Pages/ProfitAnalysisController.php`; `resources/views/accounting/profit-analysis.blade.php`; `tests/Feature/ProfitAnalysisTest.php`.
- **Diubah:** `routes/web.php` (+1 rute); `layouts/sidebar.blade.php` (+1 menu).
- **Tak diubah:** `ProfitDistributionService`, halaman Distribusi Profit, `CashRecapService`, Jurnal Kas, skema (tanpa migrasi).
