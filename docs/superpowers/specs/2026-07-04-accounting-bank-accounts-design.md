# Spec — Akuntansi: Multi-Akun Bank + Transfer Antar Akun

- **Tanggal:** 2026-07-04
- **Branch:** `accounting-bank-accounts`
- **Scope:** Menaikkan Jurnal Kas dari **saldo tunggal** → **multi-akun (bank)**: tiap pemasukan/pengeluaran ditandai **akun**, tiap akun punya **saldo awal + saldo berjalan sendiri**; **transfer antar akun** sebagai pemindahan internal (tak dihitung sebagai pemasukan/pengeluaran/laba). superadmin/accounting.
- **Di luar scope:** rekonsiliasi saldo vs mutasi bank riil, import mutasi, biaya admin bank otomatis (cycle lanjutan).

> Kebutuhan user: dana dipecah ke beberapa bank dengan peran berbeda (mis. "pemasukan saja", "save operational", "save harta") lalu **dipindahkan ke tiap akun**. Lanjutan epik Akuntansi (A–E). Data sumber `data-excel/` tetap gitignored.

---

## 1. Tujuan & Kriteria Sukses

1. superadmin/accounting mengelola daftar **akun/bank** (nama, peran, saldo awal, akun-pemasukan-default, aktif) — CRUD, ter-seed 3 akun default.
2. Tiap entri kas (manual & auto dari Payment) **bertaut ke satu akun**. Auto-flow Payment masuk ke **akun income-default**.
3. Jurnal Kas menampilkan **saldo per akun** (kartu ringkasan) + bisa **difilter per akun** (saldo berjalan mengikuti akun terpilih; "Semua akun" = total gabungan).
4. **Transfer Dana antar akun**: 1 form → **2 baris** (keluar dari akun asal + masuk ke akun tujuan) bertanda `is_transfer`, saling terhubung `transfer_group`; **dikecualikan dari pemasukan/pengeluaran/laba** di Rekap/Dashboard/Distribusi/Target, tetapi **memengaruhi saldo per akun**. UI memberi keterangan jelas bahwa ini pemindahan internal.
5. Non-akuntansi 403. Perilaku tertutup test; suite tetap hijau.

## 2. Data Model — migrasi `2026_07_04_000009_create_cash_accounts_and_transfer_fields`

**Tabel baru `tb_cash_accounts`:**
```php
$table->id();
$table->string('name');
$table->string('purpose')->nullable();          // pemasukan|operational|harta|umum (peran)
$table->decimal('opening_balance', 15, 2)->default(0);
$table->boolean('is_income_default')->default(false); // akun tujuan auto-flow Payment
$table->boolean('active')->default(true);
$table->unsignedInteger('position')->default(0);
$table->timestamps();
```
**Seed 3 akun default** (via `DB::table`, idempotent `insertOrIgnore`/cek `where name`):
- `Kas Pemasukan` — purpose `pemasukan`, `opening_balance` = `DB::table('tb_cash_settings')->value('saldo_awal')` ?? 0, `is_income_default` **true**, position 1.
- `Operational` — purpose `operational`, opening 0, position 2.
- `Harta` — purpose `harta`, opening 0, position 3.

> Saldo awal lama (`tb_cash_settings.saldo_awal`) **dipindah** ke opening akun `Kas Pemasukan`. Kolom `saldo_awal` **dibiarkan** (tak di-drop) tapi **tak lagi dipakai** hitung saldo.

**Alter `tb_cash_entries`:**
```php
$table->foreignId('account_id')->nullable()->after('cash_category_id')
      ->constrained('tb_cash_accounts')->nullOnDelete();
$table->boolean('is_transfer')->default(false)->after('source');
$table->string('transfer_group')->nullable()->after('is_transfer'); // penghubung pasangan transfer
```
**Backfill:** `UPDATE tb_cash_entries SET account_id = {id Kas Pemasukan} WHERE account_id IS NULL` (entri lama → akun income-default).

> `down()`: drop FK+kolom `account_id`/`is_transfer`/`transfer_group`, drop tabel `tb_cash_accounts`.

## 3. Model

**`CashAccount` (baru):**
- `$table = 'tb_cash_accounts'`; fillable name/purpose/opening_balance/is_income_default/active/position; casts opening_balance decimal:2, is_income_default bool, active bool.
- `const PURPOSES = ['pemasukan'=>'Pemasukan','operational'=>'Operational','harta'=>'Harta','umum'=>'Umum']`.
- `scopeActive($q)` = `where('active', true)`.
- `entries()` = hasMany(CashEntry).
- `static incomeDefault(): ?self` = `where('is_income_default', true)->first() ?? static::orderBy('position')->first()`.
- `static totalOpening(): float` = `(float) static::sum('opening_balance')`.

**`CashEntry` (ubah):**
- fillable +`account_id`, +`is_transfer`, +`transfer_group`; casts +`is_transfer => 'boolean'`.
- `account()` = belongsTo(CashAccount).
- `isTransfer(): bool` = `(bool) $this->is_transfer`.
- `scopeReal($q)` = `where('is_transfer', false)` (untuk query laba/pemasukan riil).

## 4. Service

**`CashJournalService::compute(int $year, ?int $month, ?string $jenis = null, ?int $accountId = null): array`** — refactor:
- Opening (scoped):
  - `accountId` != null → `opening = (float) CashAccount::find($accountId)?->opening_balance + priorAll(scoped ke account)`.
  - else → `opening = CashAccount::totalOpening() + priorAll(semua akun)`.
  - `priorAll` = `Σ(pemasukan) − Σ(pengeluaran)` entri `tanggal < $start` **(termasuk transfer)**, discope `->where('account_id',$accountId)` bila diset. (Transfer perlu diikutkan agar saldo per akun benar; pada tampilan semua-akun transfer net-nol.)
- Query entri `whereYear` (+`whereMonth` bila ada) (+`where('account_id',$accountId)` bila diset), `with('category','account')`, urut tanggal,id.
- Running saldo: iterasi **semua** entri scope, `running += pemasukan? +amt : −amt`, set `$e->saldo`. `saldoAkhir = running` (final; **termasuk transfer**).
- `totalIn = entri->where('is_transfer',false)->where('jenis','pemasukan')->sum('amount')`; `totalOut` idem pengeluaran (**kecualikan transfer**).
- `entries` = (bila `$jenis`) subset by jenis else semua.
- return `entries, opening, totalIn, totalOut, saldoAkhir` (+ hapus `saldoAwal` yg lama).

**`CashJournalService::accountBalances(): array`** (baru) — untuk kartu ringkasan:
- Untuk tiap `CashAccount::active()->orderBy('position')` → `saldo = opening_balance + Σ(pemasukan) − Σ(pengeluaran)` seluruh entri akun (semua waktu, termasuk transfer). return `[['account'=>CashAccount,'saldo'=>float], ...]` + `total` = Σ saldo.

**`CashRecapService::monthlyRecap` & `ytd`** — ubah:
- Opening = `CashAccount::totalOpening()` + prior (`->where('is_transfer',false)`) menggantikan `CashSetting::singleton()->saldo_awal`.
- Semua agregasi pemasukan/pengeluaran/laba pakai `->where('is_transfer', false)` (kecualikan transfer). `expenseByCategory` juga `where('is_transfer',false)`.

**`PaymentCashSyncService::sync`** — tambah ke `updateOrCreate` values: `'account_id' => optional(CashAccount::incomeDefault())->id`. (Payment selalu `is_transfer=false` default.)

## 5. Kontroler & Rute (role `superadmin|accounting`)

**`CashEntryController`:**
- `index`: baca `account` query → `$accountId` (null=semua). Panggil `compute($year,$month,$jenis,$accountId)`. Kirim tambahan: `accounts = CashAccount::active()->orderBy('position')->get()`, `allAccounts = CashAccount::orderBy('position')->get()`, `accountId`, `balances = service->accountBalances()`. Hapus ketergantungan `$setting->saldo_awal` (ganti tampilan total opening dari `balances`/`CashAccount::totalOpening()`).
- `store`/`update`: `validated()` +`'account_id' => 'nullable|exists:tb_cash_accounts,id'`; di controller `$data['account_id'] = $data['account_id'] ?? optional(CashAccount::incomeDefault())->id`. (Entri manual tanpa akun → akun income-default; menjaga test lama.)
- `destroy(int $id)`: bila entri `is_transfer` & `transfer_group` → hapus **semua** `where('transfer_group', $group)` (kedua kaki). Selain itu hapus satu.
- **`transfer(Request)` (baru):** validasi `from_account_id required exists`, `to_account_id required exists different:from_account_id`, `amount required numeric >0`, `tanggal required date`, `catatan nullable string`. `group = (string) Str::uuid()`, `kode = service->deriveKode(Carbon::parse(tanggal))`, `ket = "Transfer: {from->name} → {to->name}"`. Buat 2 `CashEntry`: OUT (account_id=from, jenis pengeluaran) + IN (account_id=to, jenis pemasukan), keduanya `is_transfer=true, transfer_group=group, source='manual', created_by=Auth::id(), catatan`. `back()->with('success','Transfer dana dicatat.')`.
- **Hapus** `updateOpening()` (opening pindah ke akun).

**`CashAccountController` (baru):**
- `store(Request)`: `name required string max:100`, `purpose nullable in:keys(PURPOSES)`, `opening_balance required numeric ≥0`, `is_income_default boolean`, `active boolean`. Bila `is_income_default` → `CashAccount::query()->update(['is_income_default'=>false])` dulu. Create. Redirect back.
- `update(Request,$id)`: idem; bila set income-default → unset lainnya (`where('id','!=',$id)`).
- `destroy($id)`: tolak (redirect + error) bila akun `is_income_default` atau `entries()->exists()`; selain itu delete.

**Rute (`routes/web.php`, dalam grup accounting):**
```php
Route::post('accounting/transfer', [CashEntryController::class,'transfer'])->name('accounting.transfer.store');
Route::post('accounting/account', [CashAccountController::class,'store'])->name('accounting.account.store');
Route::put('accounting/account/{id}', [CashAccountController::class,'update'])->name('accounting.account.update')->whereNumber('id');
Route::delete('accounting/account/{id}', [CashAccountController::class,'destroy'])->name('accounting.account.destroy')->whereNumber('id');
```
**Hapus** rute `accounting/opening` PUT (`accounting.opening.update`).

## 6. View — `resources/views/accounting/journal.blade.php`

- **Filter GET** +dropdown **Akun** (`Semua akun` + tiap `accounts`), `value` mengikuti `accountId`.
- **Kartu saldo per akun** (baris di atas, dari `balances`): tiap akun → nama + badge peran (`PURPOSES`) + saldo (Rp); kartu **Total** (semua akun). Menggantikan/menambah kartu ringkasan lama (Total Pemasukan/Pengeluaran/Saldo Akhir tetap, kini menghormati filter akun).
- **Tombol** "Transfer Dana" (collapse) + "Kelola Akun" (collapse) + "+ Tambah Transaksi" + (tabel) "Kelola Kategori" (tetap). **Hapus** form "Set Saldo Awal".
- **Form Transfer Dana** (POST `accounting.transfer.store`): Dari Akun (select) · Ke Akun (select) · Nominal · Tanggal · Catatan. **Teks bantu jelas**: *"Transfer = pemindahan dana antar akun sendiri (mis. dari Kas Pemasukan ke Operational/Harta). Ini BUKAN pemasukan/pengeluaran — tidak menambah/mengurangi laba, hanya memindah saldo antar akun."*
- **Form Tambah Transaksi** +field **Akun** (select `accounts`, default akun income-default).
- **Tabel entri** +kolom **Akun** (`$e->account?->name`); baris transfer diberi badge **`🔁 Transfer (internal)`** (di kolom Kategori/Produk) + tooltip penjelas; kolom Aksi: baris `payment` → `⚙ auto`; baris transfer → tombol hapus (menghapus **kedua** kaki, konfirmasi "Hapus transfer ini? Kedua sisi akan dihapus."); manual biasa → hapus seperti sekarang.
- **Kelola Akun** (collapse, mirip Kelola Kategori): daftar `allAccounts` tiap baris form update (name, purpose select, opening_balance, checkbox is_income_default, checkbox active) + tombol hapus (form terpisah). Form tambah akun.

## 7. Sidebar
Tak berubah (menu "Keuangan → Jurnal Kas" sudah memuat semua ini di halaman jurnal).

## 8. Rencana Test

**Unit `CashAccountBalanceTest`:** seed 2 akun A(opening 1.000.000) B(opening 0); entri pemasukan 200.000 akun A; transfer A→B 300.000 (2 entri manual is_transfer). `accountBalances()`: A = 1.000.000+200.000−300.000 = 900.000; B = 300.000; total 1.200.000. `monthlyRecap` bulan itu: totalIn 200.000 (transfer dikecualikan), totalOut 0, laba 200.000.

**Feature `AccountingBankAccountTest`:**
- `account_crud`: superadmin POST `accounting.account.store` (name "BCA", purpose operational, opening 500000) → tersimpan; PUT update opening + `is_income_default=1` → akun jadi default & default lama ter-unset; DELETE akun tanpa entri → terhapus; DELETE akun income-default → ditolak (masih ada).
- `transfer_creates_two_legs_and_moves_balance`: seed akun A(opening 1.000.000, income-default) & B(opening 0). POST `accounting.transfer.store` {from A, to B, amount 300000, tanggal '2026-06-10'} → redirect; 2 `CashEntry` `is_transfer=true` `transfer_group` sama (OUT di A pengeluaran, IN di B pemasukan). `accountBalances`: A 700.000, B 300.000; total tetap 1.000.000.
- `transfer_excluded_from_profit`: setelah transfer di atas, `CashRecapService::monthlyRecap(2026)` Jun → totalIn 0, totalOut 0, laba 0.
- `deleting_transfer_removes_both_legs`: DELETE salah satu kaki via `accounting.entry.destroy` → kedua entri (transfer_group) hilang.
- `payment_entry_lands_in_income_account` *(bila mudah)*: paksa sync Payment paid → entri `account_id` = akun income-default. (opsional bila setup Payment berat; boleh diverifikasi via unit `PaymentCashSyncService` yang sudah ada — cukup assert account_id ter-set.)
- `marketing_cannot_transfer_or_manage_accounts`: marketing POST transfer & account.store → 403.

**Sesuaikan `AccountingJournalTest`:**
- Ganti `accounting_sets_saldo_awal` → **`accounting_sets_account_opening`**: ambil akun income-default; PUT `accounting.account.update` (opening_balance 50000000, name & purpose sama) → assert `opening_balance` akun = 50000000.
- `accounting_and_superadmin_can_store_entry`: tetap (account_id tak dikirim → default akun income; assertion lama tetap valid). Tambah assert `$e->account_id === CashAccount::incomeDefault()->id`.

**Regresi:** seluruh suite hijau; recap/dashboard/distribution/target tak berubah perilaku (fresh DB → opening akun 0 = seperti saldo_awal 0; tanpa transfer filter no-op). `php artisan view:cache` bersih.

**Dev/prod:** `php artisan migrate` (1 migrasi: tabel akun + 3 kolom entri + seed + backfill). Lihat [[migrate-dev-db-after-new-migration]].

## 9. Komponen

- **Baru:** migrasi `2026_07_04_000009`; model `CashAccount`; `CashAccountController`; method `CashEntryController::transfer`; `CashJournalService::accountBalances`; test unit `CashAccountBalanceTest` + feature `AccountingBankAccountTest`.
- **Diubah:** `CashEntry` (fillable/casts/relasi/scope); `CashJournalService::compute` (param accountId + saldo dari running + kecualikan transfer di totalIn/Out); `CashRecapService` (opening Σ akun + kecualikan transfer); `PaymentCashSyncService` (account_id); `CashEntryController` (index/store/update/destroy, hapus updateOpening); `routes/web.php` (transfer + account.* , hapus opening); `resources/views/accounting/journal.blade.php`; `tests/Feature/AccountingJournalTest.php` (sesuaikan 1 test).
- **Tak diubah:** Dashboard/Distribusi/Asumsi/Target controller & view (mereka konsumsi service yg sudah diperbarui, hasil konsisten); sidebar.

## 10. Asumsi & Risiko

- **Opening pindah ke per-akun**; `tb_cash_settings.saldo_awal` deprecated (tak di-drop) → menghindari migrasi destruktif & tak menyentuh field lain `tb_cash_settings`. `CashSetting::singleton()` tetap ada untuk team_members/target.
- Transfer = **pemindahan internal** (net-nol laba) — inti kebutuhan; kesalahpahaman user dimitigasi dengan teks bantu + badge. Refund Payment tetap `pengeluaran` biasa (bukan transfer) dari akun income-default.
- `is_income_default` tunggal ditegakkan di controller (bukan constraint DB).
- Saldo per akun dihitung on-the-fly (seperti saldo lama) — untuk skala besar nanti pakai snapshot (backlog item skala).
- `account_id` nullable + default income-default menjaga kompatibilitas entri lama & test lama; entri baru selalu ber-akun.
