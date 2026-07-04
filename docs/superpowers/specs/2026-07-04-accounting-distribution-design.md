# Spec — Akuntansi Fase D: Distribusi Profit (Fleksibel) + Config

- **Tanggal:** 2026-07-04
- **Branch:** `accounting-distribution`
- **Scope (Fase D):** Distribusi/bagi laba yang **fleksibel** — tiap pos dapat berupa **persen** atau **flat** (mis. Fee Tim = 85% sekarang, bisa jadi gaji pokok flat kelak), dapat di-CRUD; kalkulator distribusi dengan **profit default = laba kas bulan terpilih** (bisa diubah) + pembagian per anggota tim. superadmin/accounting.
- **Di luar scope (Fase D-2):** Asumsi margin per produk + biaya tetap bulanan (reference); aturan bersyarat berbasis ambang pemasukan.

> Dari `data-excel` SIMULASI_DISTRIBUSI (Harta 5% · Saving+Dana TT 10% · Fee Tim 85% ÷ 8 anggota). User: distribusi harus fleksibel (flat/persen) & kondisional untuk berbagai kasus keuangan. Lanjutan Fase A–C (`CashRecapService::monthlyRecap` → laba kas per bulan; `CashSetting` singleton).

---

## 1. Tujuan & Kriteria Sukses

1. superadmin/accounting mengelola **aturan distribusi** (nama, tipe persen/flat, nilai, dibagi-per-anggota) + **jumlah anggota tim** — dapat diubah tanpa ubah kode.
2. Halaman **Distribusi Profit**: pilih tahun/bulan → profit terisi otomatis dari **laba kas bulan itu** (dapat ditimpa manual) → tampil alokasi tiap pos (Rp) + per orang (untuk pos per-anggota) + total teralokasi + **sisa/selisih**.
3. Fleksibel: kombinasi persen + flat didukung; total tak wajib = 100% (sisa ditampilkan).
4. Non-akuntansi 403. Perilaku tertutup test; suite tetap hijau.

## 2. Data Model

- **`tb_cash_settings`** (singleton, sudah ada) + kolom **`team_members`** unsignedInteger default 8 — migrasi `2026_07_04_000006` (alter).
- **`tb_cash_distributions`** (aturan) — migrasi `2026_07_04_000006` (create, sama file):
  ```php
  $table->id();
  $table->string('name');
  $table->string('type');            // percent | flat
  $table->decimal('value', 15, 2);   // % (mis. 5.00) bila percent; Rp bila flat
  $table->boolean('per_member')->default(false); // dibagi jumlah anggota
  $table->boolean('active')->default(true);
  $table->unsignedInteger('position')->default(0);
  $table->timestamps();
  ```
  **Seed** (bisa diubah): Harta/Pemilik (percent, 5, per_member false, pos 1); Saving + Dana Tak Terduga (percent, 10, false, pos 2); Fee Tim (percent, 85, per_member true, pos 3).

**Model `CashDistribution`** (`tb_cash_distributions`): fillable name/type/value/per_member/active/position; casts value decimal:2, per_member bool, active bool; `const TYPES = ['percent'=>'Persen','flat'=>'Flat']`; `scopeActive`.
**`CashSetting`**: +`team_members` fillable + cast integer (default kolom 8; `singleton()` sudah ada).

## 3. Service — `ProfitDistributionService`

`distribute(float $profit, ?int $members = null): array`:
- `$members = $members ?? (int) CashSetting::singleton()->team_members; if ($members < 1) $members = 1;`
- Untuk tiap `CashDistribution::active()->orderBy('position')->get()`:
  - `amount = $rule->type === 'percent' ? round((float)$rule->value / 100 * $profit) : (float) $rule->value;`
  - `perPerson = $rule->per_member ? $amount / $members : null;`
  - line = `['name','type','value','per_member','amount','perPerson']`.
- `totalAllocated = Σ amount`; `remainder = $profit - $totalAllocated`.
- Return `['profit'=>$profit,'members'=>$members,'lines'=>Collection,'totalAllocated'=>float,'remainder'=>float]`.

> Murni hitung (tak menyimpan hasil). Flat = nilai tetap apa pun profit; percent = value% × profit.

## 4. Kontroler & Rute — `ProfitDistributionController` (role `superadmin|accounting`)

- `index(Request)`: `year` (default tahun kini), `month` (default bulan kini, 1..12), `profit` (opsional override). Bila `profit` tak diisi → `profit = ` laba kas bulan tsb dari `app(CashRecapService::class)->monthlyRecap($year)[$month-1]['laba']`. `result = service->distribute($profit, null)`. Kirim `year,month,profit,result`, `rules = CashDistribution::orderBy('position')->get()`, `setting = CashSetting::singleton()`.
- `updateSetting(Request)`: validasi `team_members` required integer min 1 → `CashSetting::singleton()->update(['team_members'=>...])`.
- `storeRule` (name required, type in percent/flat, value numeric ≥0, per_member boolean), `updateRule` (sama + active), `destroyRule`.
- Rute: `accounting/distribution` GET `accounting.distribution`; `accounting/distribution/settings` PUT `accounting.distribution.settings`; `accounting/distribution/rule` POST `accounting.distribution.rule.store`; `.../rule/{id}` PUT/DELETE `accounting.distribution.rule.update|destroy`.

## 5. View — `resources/views/accounting/distribution.blade.php`

- **Filter**: tahun + bulan + input **Profit** (prefilled laba kas bulan, editable, submit GET) + info jumlah anggota.
- **Tabel hasil**: Pos · Tipe (badge Persen/Flat) · Nilai (mis. "5%" / "Rp …") · **Alokasi (Rp)** · **Per Orang** (bila per_member; else "—"). Footer: **Total Teralokasi** + **Sisa/Selisih** (merah bila negatif).
- **Kelola** (collapse, superadmin/accounting): daftar aturan (edit name/type/value/per_member/active) + tambah aturan + set **jumlah anggota tim**.
- Menu sidebar "Keuangan → Distribusi Profit" (`@role(['superadmin','accounting'])`).

## 6. Rencana Test

- **Unit `ProfitDistributionServiceTest`**:
  - Aturan seed (Harta 5% · Saving 10% · Fee 85% per_member) + `team_members=8`; `distribute(1_000_000, null)` → Harta 50.000, Saving 100.000, Fee 850.000 (perPerson 106.250), totalAllocated 1.000.000, remainder 0.
  - Tambah aturan **flat** (mis. "PPn Bank" flat 20.000) → amount 20.000 apa pun profit; remainder = profit − Σ (percent+flat).
  - `members` override / `<1` → dibagi min 1 (tak error).
- **Feature `AccountingDistributionTest`**:
  - `accounting_opens_with_month_laba_as_default_profit`: seed cash entry (pemasukan 800k Jun 2026) → GET `accounting.distribution?year=2026&month=6` → 200 + tampil alokasi (assertSee 'Harta/Pemilik' & angka).
  - `crud_rule_and_members`: superadmin tambah aturan flat, ubah `team_members` → tersimpan.
  - `marketing_cannot_access`: 403.
- **Regresi**: suite hijau; `php artisan view:cache` bersih.

**Dev/prod:** `php artisan migrate` (kolom team_members + tabel distribusi + seed). Lihat [[migrate-dev-db-after-new-migration]].

## 7. Komponen

- **Baru:** migrasi `2026_07_04_000006`; model `CashDistribution`; `ProfitDistributionService`; `ProfitDistributionController`; view `accounting/distribution.blade.php`; test unit+feature.
- **Diubah:** `CashSetting` (+team_members); `routes/web.php` (`accounting.distribution.*`); `sidebar.blade.php` (menu Distribusi Profit).
- **Tak diubah:** Jurnal Kas / Dashboard (Fase A–C) — hanya membaca `CashRecapService`.

## 8. Asumsi & Risiko

- Fleksibel flat/persen per pos → mendukung Fee Tim flat (gaji pokok) kelak tanpa migrasi. Kombinasi bebas; sisa ditampilkan (tak dipaksa 100%).
- Distribusi = simulator/kalkulator (hitung on-the-fly, tak disimpan per bulan) — konsisten dgn Excel yg profit-kotor-nya manual.
- Profit default = laba kas bulan (dapat ditimpa) → tersambung Jurnal Kas.
- `per_member` bagi `team_members` (min 1). Asumsi margin/biaya tetap = Fase D-2.
- Aturan dapat dinonaktifkan (soft `active=false`) untuk skenario berbeda tanpa hapus histori.
