# Spec — Akuntansi Fase D-2: Asumsi (Margin + Biaya Tetap)

- **Tanggal:** 2026-07-04
- **Branch:** `accounting-assumption`
- **Scope (Fase D-2):** Halaman **Asumsi** — kelola (CRUD) **margin per produk** (per kode, dgn tier Sinta) + **biaya tetap bulanan** (referensi, daftar + total/bulan). superadmin/accounting. Referensi/setelan; tak menyentuh Jurnal Kas.
- **Di luar scope:** memakai margin untuk hitung profit-kotor otomatis; posting biaya tetap ke Jurnal Kas (cycle lanjutan).

> Dari `data-excel` sheet ASUMSI (margin M_ART_S2..M_BK_ALL; pengeluaran wajib bulanan hosting/domain/DOI/saving). Lanjutan epik Akuntansi (A–D). Data sumber tetap gitignored.

---

## 1. Tujuan & Kriteria Sukses

1. superadmin/accounting mengelola daftar **margin per produk** (kode, label, margin %) — CRUD, ter-seed dari ASUMSI.
2. Mengelola daftar **biaya tetap** (nama, periode bulanan/tahunan, nominal, catatan) — CRUD, ter-seed; halaman menampilkan **nominal per bulan** tiap baris + **total per bulan**.
3. Non-akuntansi 403. Perilaku tertutup test; suite tetap hijau.

## 2. Data Model (2 tabel) — migrasi `2026_07_04_000007`

**`tb_cash_margins`**:
```php
$table->id();
$table->string('code')->nullable();
$table->string('label');
$table->decimal('margin_pct', 6, 2); // mis. 25.00
$table->boolean('active')->default(true);
$table->unsignedInteger('position')->default(0);
$table->timestamps();
```
Seed: `M_ART_S2` "Artikel Mandiri Sinta 2-3" 25 (pos1) · `M_ART_S4` "Artikel Mandiri Sinta 4-6" 30 (2) · `M_KOL_S2` "Artikel Kolaborasi Sinta 2-3" 25 (3) · `M_KOL_S4` "Artikel Kolaborasi Sinta 4-6" 30 (4) · `M_BK_ALL` "Buku (semua jenis)" 87 (5).

**`tb_cash_fixed_expenses`**:
```php
$table->id();
$table->string('name');
$table->string('period'); // bulanan | tahunan
$table->decimal('amount', 15, 2);
$table->text('note')->nullable();
$table->boolean('active')->default(true);
$table->unsignedInteger('position')->default(0);
$table->timestamps();
```
Seed: Hosting Avidpedia (tahunan 975000, pos1) · Hosting Jurnal (tahunan 1755000, 2) · Domain Avidpedia (tahunan 205000, 3) · Domain Jurnal (tahunan 205000, 4) · Keanggotaan DOI PubMEDIA (tahunan 750000, 5) · Saving Bulanan (bulanan 500000, 6).

**Model `CashMargin`**: fillable code/label/margin_pct/active/position; casts margin_pct decimal:2, active bool; `scopeActive`.
**Model `CashFixedExpense`**: fillable name/period/amount/note/active/position; casts amount decimal:2, active bool; `const PERIODS = ['bulanan'=>'Bulanan','tahunan'=>'Tahunan']`; **`monthlyAmount(): float`** = `$this->period === 'tahunan' ? (float)$this->amount / 12 : (float)$this->amount`.

## 3. Kontroler & Rute — `CashAssumptionController` (role `superadmin|accounting`)

- `index`: `margins = CashMargin::orderBy('position')->get()`; `expenses = CashFixedExpense::orderBy('position')->get()`; `totalMonthly = expenses->where('active', true)->sum(fn ($e) => $e->monthlyAmount())`. View `accounting.assumption`.
- Margin: `storeMargin` (code nullable string, label required string, margin_pct required numeric 0..100), `updateMargin` (+ active boolean), `destroyMargin`.
- Biaya: `storeExpense` (name required, period in bulanan/tahunan, amount required numeric ≥0, note nullable), `updateExpense` (+ active), `destroyExpense`.
- Rute: `accounting/assumption` GET `accounting.assumption`; `accounting/assumption/margin` POST + `/{id}` PUT/DELETE `accounting.assumption.margin.store|update|destroy`; `accounting/assumption/expense` POST + `/{id}` PUT/DELETE `accounting.assumption.expense.store|update|destroy`.

## 4. View — `resources/views/accounting/assumption.blade.php`

- **Seksi Margin per Produk**: tabel — Kode · Label · Margin (%) · Aktif · Aksi. Tiap baris form edit (update) + tombol hapus (form terpisah, tak nested). Form tambah margin.
- **Seksi Biaya Tetap Bulanan**: tabel — Nama · Periode · Nominal · **Per Bulan** (`monthlyAmount`, format Rp) · Catatan · Aktif · Aksi. Baris **Total per Bulan** (Σ aktif). Form tambah biaya.
- Menu sidebar "Keuangan → Asumsi" (`@role(['superadmin','accounting'])`, setelah Distribusi Profit).

## 5. Rencana Test

- **Unit `CashFixedExpenseTest`**: `monthlyAmount` tahunan (1.200.000 → 100.000) & bulanan (500.000 → 500.000).
- **Feature `AccountingAssumptionTest`**:
  - `accounting_opens_assumption`: GET `accounting.assumption` (accounting) → 200 + `assertSee('Buku (semua jenis)')` + `assertSee('Hosting Avidpedia')`.
  - `crud_margin`: superadmin tambah margin → tersimpan; update; hapus.
  - `crud_expense_and_total`: tambah biaya tahunan 1.200.000 → tersimpan; total per bulan mencerminkan (naik 100.000). (assert via DB / halaman)
  - `marketing_cannot_access`: 403.
- **Regresi**: suite hijau; `php artisan view:cache` bersih.

**Dev/prod:** `php artisan migrate` (2 tabel + seed). Lihat [[migrate-dev-db-after-new-migration]].

## 6. Komponen

- **Baru:** migrasi `2026_07_04_000007`; model `CashMargin`, `CashFixedExpense`; `CashAssumptionController`; view `accounting/assumption.blade.php`; test unit+feature.
- **Diubah:** `routes/web.php` (`accounting.assumption.*`); `sidebar.blade.php` (menu Asumsi).
- **Tak diubah:** Jurnal Kas / Dashboard / Distribusi (A–D).

## 7. Asumsi & Risiko

- Referensi/setelan saja (tak menyentuh Jurnal Kas) → risiko rendah; nilai dipakai manual/informasional.
- `monthlyAmount` = tahunan ÷ 12 (pembulatan tampilan di view; simpan mentah).
- Margin per kode (tier Sinta) — dipetakan ke produk/tier saat integrasi profit-kotor kelak (di luar scope).
- Baris dapat dinonaktifkan (`active=false`) tanpa hapus histori.
