# Sinkron Pemasukan Manual ke Analisa Profit + Aksi Edit Jurnal Kas

**Tanggal:** 2026-07-24
**Status:** Disetujui (brainstorm) — menunggu review spec

## Masalah

Halaman **Analisa Profit** (`/accounting/profit`) hanya menghitung pemasukan **otomatis** (dari `Payment` yang `paid`). Pemasukan yang di-**input manual** di Jurnal Kas (`tb_cash_entries` dengan `source ≠ payment`) tidak pernah muncul di sana — halaman itu bahkan menyatakan "Pemasukan non-order tidak dihitung karena tak punya margin". User ingin **setiap** pemasukan, otomatis maupun manual, ikut dihitung di profit.

Selain itu, entri manual (pemasukan atau pengeluaran) di Jurnal Kas belum punya **aksi Edit** di UI — hanya Hapus.

## Keputusan (dikonfirmasi user)

1. **Sumber angka profit — pendekatan aditif (Opsi A).** Perhitungan otomatis dari `Payment` (termasuk refund→negatif) DIPERTAHANKAN apa adanya. Pemasukan manual DITAMBAHKAN dari `tb_cash_entries`. Tidak ada dobel-hitung karena entri `source=payment` di-exclude. Alasan: hasil yang dilihat user sama, tapi tanpa mengutak-atik logika refund yang rapuh; semua test lama tetap hijau. (Unifikasi penuh Jurnal Kas sebagai satu-satunya sumber = item hardening terpisah, di luar scope.)

2. **Margin pemasukan manual bergantung produk (dan kategori).**
   - Produk **Buku** → `M_BK_ALL` (87%).
   - Produk **Artikel** → tier terendah **S2 (25%)** karena entri manual tak punya indeksasi. Jika kategori entri punya `map_key` (`at_mandiri`→`M_ART_S2`, `at_kolab`→`M_KOL_S2`; keduanya 25%), map_key dipakai memilih M_ART vs M_KOL; jika tidak ada, default `M_KOL_S2`.
   - **Selain artikel/buku** (Operasional / produk kustom / kosong) → **100% Siap Dibagi** (Cadangan APC = 0).
   - Persen selalu ditarik dari tabel Asumsi (`CashMargin`) sehingga ikut berubah bila asumsi diubah; margin baris hilang (row Asumsi nonaktif) → 0% + flag, sama seperti jalur order.

3. **Kategori & Produk pakai select2 dengan tambah-inline.**
   - Kategori: select2 `tags:true`. Nama baru → `firstOrCreate` kategori (nama + jenis entri).
   - Produk: select2 `tags:true` (Artikel/Buku/Operasional + boleh baru). Hanya "artikel"/"buku" (case-insensitive) yang memicu margin; lainnya 100%.

4. **Aksi Edit** untuk entri manual & bukan transfer. Entri `source=payment` tetap read-only; transfer tetap hapus-saja (edit transfer = hapus + buat ulang).

## Perubahan

### A. `ProfitAnalysisService` (app/Services/ProfitAnalysisService.php)

- Refactor inti margin agar bisa dipanggil dari produk, bukan hanya `OrderDetail`. Tambah method mis. `marginForManual(CashEntry $e): array` yang mengembalikan bentuk sama `{code, pct, unknownTier, marginMissing}`:
  - Tentukan "type efektif" dari `category.map_key` bila ada (`at_*`/`bk_*`), else dari `produk` (`buku`→`bk_*`, `artikel`→`at_kolab` default).
  - `buku` → `M_BK_ALL`. `artikel` → M_ART/M_KOL **S2** (25%). Selain itu → `pct = 100`, `code = null` (Cadangan APC = 0).
  - Reuse `CashMargin` lookup + `marginMissing` flag persis jalur order.
- Di `forMonth($year,$month)`: setelah loop `Payment`, tambah loop entri kas manual:
  ```php
  CashEntry::where('jenis','pemasukan')
      ->where('source','!=','payment')
      ->where('is_transfer', false)
      ->whereYear('tanggal',$year)->whereMonth('tanggal',$month)
      ->with(['category'])->orderBy('tanggal')->get();
  ```
  Untuk tiap entri: `base = amount` (positif), `pct` dari `marginForManual`, `margin = round(base*pct/100,2)`, `reserve = base - margin`. Tambah ke `totalIn`, `totalMargin`, `totalReserve`. Tambah baris ke `$rows` dengan penanda manual (`code_order = null`, `judul = keterangan`, `type = produk ?: '(manual)'`, `indexation = null`, `manual = true`).
- `yearly()` tak berubah (memanggil `forMonth`, jadi manual otomatis ikut).
- Pengeluaran manual TIDAK disentuh (halaman = analisa pemasukan→margin).

### B. Tampilan Analisa Profit (resources/views/accounting/profit-analysis.blade.php)

- Tabel "Rincian per Pembayaran": baris manual tampil dengan kolom Order "—" dan badge kecil "manual". Header/teks penjelas disesuaikan (hapus/ubah kalimat "Pemasukan non-order tidak dihitung").
- Guard array: baris manual harus punya semua key yang dibaca view (`unknownTier`, `marginMissing`, `pct`, `base`, `reserve`, `margin`, `code_order`, `type`, `indexation`, `judul`, `tanggal`).

### C. `CashEntryController` (app/Http/Controllers/Pages/CashEntryController.php)

- `validated()`: ubah `produk` dari `in:artikel,buku,operasional` → `nullable|string|max:50`. `cash_category_id` tidak lagi divalidasi `exists` mentah; ditangani lewat resolver.
- Tambah helper `resolveCategoryId($raw, string $jenis): ?int`: jika numerik & ada → id; jika string non-kosong → `firstOrCreate(['name'=>trim, 'jenis'=>$jenis], ['active'=>true,'position'=>next])` → id; kosong → null.
- `store()` & `update()`: panggil resolver sebelum simpan. `update()` guard payment & kunci periode TIDAK berubah.

### D. Tampilan Jurnal Kas (resources/views/accounting/journal.blade.php)

- Form Tambah: kategori & produk jadi select2 (`tags:true`).
- Kolom Aksi: tambah tombol **✎ Edit** untuk entri `source ≠ payment` & bukan transfer (di samping tombol Hapus).
- Tambah **modal Bootstrap** `#editEntryModal` berisi form (POST + `@method('PUT')` ke `accounting.entry.update`), field ter-prefill via data-* / JS. Select2 + money-mask aktif di modal (init saat modal shown, `dropdownParent` = modal).
- Pastikan aset select2 (css/js) di-load di halaman ini (cek apakah sudah global di master; jika belum, push di halaman).

## Test

`tests/Feature/ProfitAnalysisTest.php` (tambah):
- Pemasukan manual **buku** → 87% Siap Dibagi.
- Pemasukan manual **artikel** → 25% Siap Dibagi, 75% Cadangan APC.
- Pemasukan manual **operasional / produk kustom / kosong** → 100% Siap Dibagi, 0 Cadangan.
- Manual + payment di bulan sama → totalIn/margin gabungan benar, **tanpa dobel** (entri payment tak dihitung dua kali).
- Transfer (is_transfer) & pengeluaran manual **tidak** masuk profit.
- `yearly()` mengakumulasi pemasukan manual.

`tests/Feature/AccountingJournalTest.php` (atau setara, tambah):
- `store` dengan **kategori baru (nama)** → kategori dibuat + entri tertaut.
- `store`/`update` dengan **produk kustom** → tersimpan apa adanya; muncul di profit sbagai 100%.
- `update` entri manual → nilai berubah; entri `source=payment` → tetap ditolak (guard existing); kunci periode tetap dihormati.

## Di luar scope (sengaja)

- Unifikasi penuh sumber pemasukan (Jurnal Kas satu-satunya sumber) — item hardening terpisah.
- Pengeluaran manual masuk analisa profit / penautan biaya ke order (`order_id` di entri kas) — item terpisah.
- Edit entri transfer & entri auto payment.
- Filter akun di halaman Analisa Profit (tetap year/month seperti sekarang).

## Referensi semantik (jangan dilanggar)

Lihat `docs/superpowers/specs/2026-07-17-*` dan memory keuangan: `Payment::income()` (pelaporan kotor) vs `Order::paidNet()` (kelunasan, refund dikurangkan); refund = pengeluaran; Analisa Profit pakai margin ASUMSI bukan biaya aktual; Asumsi bukan sumber entri kas. Perubahan ini hanya MENAMBAH pemasukan manual ke analisa margin — tidak mengubah definisi di atas.
