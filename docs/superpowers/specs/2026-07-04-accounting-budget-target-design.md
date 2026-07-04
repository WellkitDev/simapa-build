# Spec — Akuntansi Fase E: Anggaran & Target

- **Tanggal:** 2026-07-04
- **Branch:** `accounting-budget-target`
- **Scope (Fase E):** Halaman **Anggaran & Target** — set target perusahaan bulanan (operasional & order) + tabel **realisasi vs target** per bulan (realisasi = pemasukan kas dari Jurnal Kas) + skenario referensi (Minimum/Aman/Ideal/Agresif) + total biaya tetap/bln (referensi dari Asumsi). superadmin/accounting.
- **Di luar scope:** target per-marketing (sudah di `tb_marketing_targets`); realisasi berbasis nilai order.

> Dari `data-excel` Proposal PDF (target operasional min 80jt/bln; target order 200jt/bln = 80jt÷40%; skenario 150/200/250/300jt). Lanjutan epik Akuntansi A–D-2 (`CashRecapService::monthlyRecap` → pemasukan kas/bln; `CashSetting` singleton; `CashFixedExpense` total biaya tetap).

---

## 1. Tujuan & Kriteria Sukses

1. superadmin/accounting menetapkan **target operasional** & **target order** bulanan (perusahaan), tersimpan.
2. Halaman menampilkan **realisasi vs target** per bulan (tahun terpilih): pemasukan kas bulan itu, target operasional, **% pencapaian**, status (Tercapai/Kurang) + YTD.
3. Menampilkan referensi: total biaya tetap/bln (Asumsi) + skenario order (Minimum 150jt · Aman 200jt · Ideal 250jt · Agresif 300jt).
4. Non-akuntansi 403. Perilaku tertutup test; suite tetap hijau.

## 2. Data Model — migrasi `2026_07_04_000008` (alter `tb_cash_settings`)

```php
$table->decimal('target_operasional', 15, 2)->default(0)->after('team_members');
$table->decimal('target_order', 15, 2)->default(0)->after('target_operasional');
```
**`CashSetting`**: fillable +`target_operasional`,`target_order`; casts decimal:2. **`singleton()`** default eksplisit diperluas: `firstOrCreate([], ['saldo_awal'=>0,'team_members'=>8,'target_operasional'=>0,'target_order'=>0])` (agar instance baru punya nilai numerik, bukan null).

## 3. Service — `BudgetTargetService` (inject `CashRecapService`)

`monthlyAchievement(int $year): array` — 12 elemen (bulan 1..12), tiap `['month','label','realisasi','target','pct','achieved']`:
- `target = (float) CashSetting::singleton()->target_operasional`.
- `realisasi = (float) monthlyRecap($year)[$m-1]['totalIn']` (pemasukan kas bulan).
- `pct = $target > 0 ? (int) round($realisasi / $target * 100) : 0`.
- `achieved = $target > 0 && $realisasi >= $target`.

> Guard `target > 0` menghindari bagi nol.

## 4. Kontroler & Rute — `BudgetTargetController` (role `superadmin|accounting`)

- `index(Request)`: `year` (default kini). `setting = CashSetting::singleton()`; `monthly = service->monthlyAchievement($year)`; `ytdRealisasi = Σ realisasi`; `ytdTarget = target × 12`; `fixedMonthly = CashFixedExpense::where('active',true)->get()->sum(fn($e)=>$e->monthlyAmount())`. Kirim `year, setting, monthly, ytdRealisasi, ytdTarget, fixedMonthly, scenarios` (`const SCENARIOS = ['Minimum'=>150000000,'Aman'=>200000000,'Ideal'=>250000000,'Agresif'=>300000000]`).
- `updateTarget(Request)`: validasi `target_operasional` required numeric ≥0, `target_order` required numeric ≥0 → `CashSetting::singleton()->update(...)`.
- Rute: `accounting/target` GET `accounting.target`; `accounting/target` PUT `accounting.target.update`.

## 5. View — `resources/views/accounting/target.blade.php`

- **Set Target** (form PUT `accounting.target.update`): Target Operasional/bln + Target Order/bln (input Rp).
- **Referensi**: kartu Total Biaya Tetap/bln (`fixedMonthly`) + tabel Skenario Order (Minimum/Aman/Ideal/Agresif → nominal) + catatan "target order ≈ operasional ÷ 40%".
- **Realisasi vs Target** (filter tahun): tabel per bulan — Bulan · Pemasukan Kas (realisasi) · Target Operasional · **% Pencapaian** (badge hijau bila ≥100, kuning bila <100) · Status. Baris YTD (realisasi vs target×12 + %).
- Menu sidebar "Keuangan → Anggaran & Target".

## 6. Rencana Test

- **Unit `BudgetTargetServiceTest`**: set `target_operasional=1.000.000`; seed cash pemasukan Jun 2026 800.000, Jul 2026 1.200.000. `monthlyAchievement(2026)` → Jun: realisasi 800.000, pct 80, achieved false; Jul: realisasi 1.200.000, pct 120, achieved true. Target 0 → pct 0, achieved false (guard).
- **Feature `AccountingBudgetTargetTest`**:
  - `accounting_opens_target_page`: GET `accounting.target` (accounting) → 200 + `assertSee('Target Operasional')` + `assertSee('Minimum')` (skenario).
  - `set_target_saved`: PUT `accounting.target.update` (operasional 80000000, order 200000000) → `CashSetting::singleton()` terisi.
  - `marketing_cannot_access`: 403.
- **Regresi**: suite hijau; `php artisan view:cache` bersih.

**Dev/prod:** `php artisan migrate` (2 kolom). Lihat [[migrate-dev-db-after-new-migration]].

## 7. Komponen

- **Baru:** migrasi `2026_07_04_000008`; `BudgetTargetService`; `BudgetTargetController`; view `accounting/target.blade.php`; test unit+feature.
- **Diubah:** `CashSetting` (+target fields + singleton defaults); `routes/web.php`; `sidebar.blade.php`.
- **Tak diubah:** Jurnal Kas/Dashboard/Distribusi/Asumsi (A–D-2); MarketingTarget existing.

## 8. Asumsi & Risiko

- Target = perusahaan (bukan per-marketing). Realisasi = pemasukan kas (bukan nilai order) — sesuai keputusan.
- Skenario referensi = konstanta (angka Proposal); target aktual di-set bebas.
- `singleton()` default eksplisit mencakup semua kolom bernilai numerik → instance baru konsisten (pelajaran dari Fase D team_members).
- Guard `target>0` untuk % pencapaian.
