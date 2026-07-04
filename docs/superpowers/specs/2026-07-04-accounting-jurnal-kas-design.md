# Spec — Akuntansi Fase A: Jurnal Kas

- **Tanggal:** 2026-07-04
- **Branch:** `accounting-jurnal-kas`
- **Scope (Fase A):** Buku kas (Jurnal Kas) — tabel transaksi pemasukan/pengeluaran dengan kategori (bagan akun) + produk + ref + **saldo berjalan (turunan)**, CRUD, filter per bulan & jenis, ringkasan periode. Tambah role **`accounting`** (akses akuntansi = superadmin/accounting).
- **Di luar scope:** auto-flow dari Payment (Fase B); rekap bulanan & dashboard (Fase C); asumsi/margin & distribusi profit (Fase D); anggaran/target (Fase E).

> Diturunkan dari `data-excel/Avidpedia_Keuangan_v1.xlsx` sheet **JURNAL_KAS** (tiap baris = 1 transaksi: tanggal, kode, keterangan, pemasukan/pengeluaran, saldo auto, bulan auto, kategori, jenis, produk, ref INV/order, catatan). Data sumber **gitignored** (`data-excel/`), tak di-commit. Modul pemasukan existing (Payment/Invoice) akan diintegrasikan di Fase B — Fase A: entri manual.

---

## 1. Tujuan & Kriteria Sukses

1. Role `accounting` tersedia (idempotent via migrasi); menu & endpoint akuntansi hanya untuk superadmin/accounting (403 selain itu).
2. Pengguna dapat mencatat transaksi kas (pemasukan/pengeluaran) dengan tanggal, kategori (tersaring per jenis), nominal, produk, keterangan, ref, catatan; **kode** otomatis (`B{bulan}{yy}`) dari tanggal.
3. Halaman Jurnal Kas menampilkan transaksi terfilter per **bulan** (+ opsi jenis) dengan **saldo berjalan** (opening balance dari bulan-bulan sebelumnya + kumulatif dalam bulan) + ringkasan (total pemasukan, pengeluaran, saldo akhir).
4. Kategori (bagan akun) di-seed dari data + dapat dikelola (tambah/edit/nonaktif).
5. Perilaku tertutup test; suite tetap hijau.

## 2. Role `accounting`

Migrasi `2026_07_04_000001_add_accounting_role.php` (data migration, idempotent):
```php
public function up(): void {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'accounting', 'guard_name' => 'web']);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
}
public function down(): void {
    \Spatie\Permission\Models\Role::where('name', 'accounting')->where('guard_name', 'web')->delete();
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
}
```
> Roles di app di-seed via `PermissionSeed` (sudah jalan di dev/live); menambah role baru via migrasi = cara aman menjangkau dev/test/prod tanpa re-seed.

## 3. Data Model (2 tabel)

**`tb_cash_categories`** — migrasi `2026_07_04_000002` (+ seed):
```php
$table->id();
$table->string('name');
$table->string('jenis'); // pemasukan | pengeluaran
$table->boolean('active')->default(true);
$table->unsignedInteger('position')->default(0);
$table->timestamps();
```
Seed di `up()`:
- **pemasukan**: Order Artikel Kolaborasi, Order Artikel Mandiri, Order Buku Kolaborasi, Order Buku Mandiri.
- **pengeluaran**: Biaya APC Jurnal, Fee Tim/Freelancer, Operational, PPn Bank, Saving, Dana Tak Terduga.

**`tb_cash_entries`** — migrasi `2026_07_04_000003`:
```php
$table->id();
$table->date('tanggal');
$table->string('kode')->nullable();          // auto B{bulan}{yy}
$table->string('keterangan');
$table->string('jenis');                     // pemasukan | pengeluaran
$table->decimal('amount', 15, 2);
$table->foreignId('cash_category_id')->nullable()->constrained('tb_cash_categories')->nullOnDelete();
$table->string('produk')->nullable();        // artikel | buku | operasional
$table->string('ref')->nullable();           // INV/order code
$table->text('catatan')->nullable();
$table->string('source')->default('manual'); // manual | payment (Fase B)
$table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
$table->timestamps();
$table->index(['tanggal', 'id']);
```
> **Saldo & bulan tidak disimpan** (turunan): saldo = kumulatif (pemasukan `+amount`, pengeluaran `−amount`) urut `tanggal,id`.

**Model `CashCategory`** (`tb_cash_categories`): fillable name/jenis/active/position; casts active bool; `const JENIS = ['pemasukan'=>'Pemasukan','pengeluaran'=>'Pengeluaran']`; `scopeActive`; relasi `entries()` hasMany.

**Model `CashEntry`** (`tb_cash_entries`): fillable tanggal/kode/keterangan/jenis/amount/cash_category_id/produk/ref/catatan/source/created_by; casts `tanggal`=>date, `amount`=>decimal:2; `const PRODUK = ['artikel'=>'Artikel','buku'=>'Buku','operasional'=>'Operasional']`; `isPemasukan()`; relasi `category()` belongsTo, `creator()` belongsTo.

## 4. Logika — `CashJournalService`

- **`deriveKode(\Carbon\Carbon $tanggal): string`** → `'B' . $tanggal->month . substr((string) $tanggal->year, -2)` (Okt 2025 → `B1025`; Jan 2026 → `B126`). Konsisten dgn data.
- **`compute(int $year, ?int $month, ?string $jenis = null): array`**:
  - `opening` = (Σ pemasukan − Σ pengeluaran) semua entri **sebelum** awal periode. Bila `$month` null (mode setahun) → opening = sebelum awal tahun.
  - Ambil entri periode (bila `$month`: `whereYear+whereMonth`; else `whereYear`) urut `tanggal,id` — **semua jenis** (agar saldo benar).
  - Jalan kumulatif: `running = opening`; tiap entri `running += isPemasukan ? +amount : -amount`; set atribut transien `$entry->saldo = running`.
  - `totalIn` = Σ amount pemasukan periode; `totalOut` = Σ amount pengeluaran periode; `saldoAkhir` = opening + totalIn − totalOut.
  - Bila `$jenis` diisi → filter koleksi entri untuk **tampilan** (saldo tetap dari perhitungan penuh); ringkasan tetap atas seluruh entri periode.
  - Return `['entries'=>Collection, 'opening'=>float, 'totalIn'=>float, 'totalOut'=>float, 'saldoAkhir'=>float]`.

## 5. Kontroler & Rute

**`CashEntryController`** (middleware `role:superadmin|accounting`):
- `index(Request)`: `year`/`month` (default bulan berjalan), `jenis` opsional; panggil `service->compute`; kirim entries+ringkasan + `categories` (active) + daftar bulan tersedia. View `accounting.journal`.
- `store(Request)`: validasi (tanggal date, jenis in pemasukan/pengeluaran, cash_category_id nullable exists, amount numeric ≥0, produk nullable in artikel/buku/operasional, keterangan required, ref/catatan nullable); `kode = service->deriveKode(tanggal)`; `source='manual'`; `created_by`; create; redirect back + flash.
- `update(Request,$id)`, `destroy($id)`.

**`CashCategoryController`** (middleware sama):
- `store` (name/jenis), `update` (name/active), `destroy`. Redirect back + flash.

Rute prefix `accounting.*`: `accounting.journal` (GET), `accounting.entry.store|update|destroy`, `accounting.category.store|update|destroy`. Grup `role:superadmin|accounting`.

## 6. View — `resources/views/accounting/journal.blade.php`

- **Header**: judul "Jurnal Kas" + filter **bulan** (`<input type=month>` atau dropdown) + jenis + tombol "Tambah Transaksi".
- **Ringkasan** (3 kartu): Total Pemasukan, Total Pengeluaran, Saldo Akhir (format Rp).
- **Tabel DataTables**: Tgl · Kode · Keterangan · Kategori · Produk · Pemasukan · Pengeluaran · **Saldo** · Ref · Aksi (Edit/Hapus). Pemasukan/Pengeluaran diisi sesuai jenis; Saldo = `$entry->saldo`.
- **Form tambah/edit** (collapse/modal): tanggal, jenis (select), kategori (select — JS saring per jenis, atau tampil semua dgn label jenis), amount, produk (select), keterangan, ref, catatan.
- **Kelola Kategori** (collapse): daftar per jenis + form tambah + edit/nonaktif.
- Menu sidebar **"Akuntansi"** (`@role(['superadmin','accounting'])`) → `accounting.journal`, dengan sub/section header "Keuangan".

## 7. Rencana Test

- **Unit `CashJournalServiceTest`**: `deriveKode` (Okt-25→B1025, Jan-26→B126); `compute` saldo berjalan benar (opening dari bulan sebelumnya + kumulatif); totalIn/totalOut/saldoAkhir benar; filter jenis tak mengubah saldo/ringkasan.
- **Feature `AccountingJournalTest`** (role `accounting` dari migrasi):
  - `accounting_role_exists` (opsional) atau langsung pakai.
  - `superadmin_and_accounting_can_store_entry`: simpan pemasukan + pengeluaran → tersimpan, `kode` auto, `created_by`, `source=manual`.
  - `marketing_cannot_access`: `GET accounting.journal` & `POST accounting.entry.store` → 403.
  - `index_shows_entries_and_summary`: GET journal (bulan) → tampil keterangan + total.
  - `category_crud`: superadmin tambah kategori; nonaktif.
  - `update_and_delete_entry`.
- **Regresi**: suite hijau; `php artisan view:cache` bersih.

**Dev/prod:** `php artisan migrate` (role + 2 tabel + seed). Lihat [[migrate-dev-db-after-new-migration]].

## 8. Komponen

- **Baru:** 3 migrasi (`000001` role, `000002` categories+seed, `000003` entries); model `CashCategory`, `CashEntry`; `CashJournalService`; `CashEntryController`, `CashCategoryController`; view `accounting/journal.blade.php`; test unit+feature.
- **Diubah:** `routes/web.php` (`accounting.*`); `resources/views/layouts/sidebar.blade.php` (menu Akuntansi).
- **Tak diubah:** Payment/Invoice/MarketingTarget (integrasi di Fase B/E).

## 9. Asumsi & Risiko

- Saldo & bulan turunan (dihitung), tak disimpan → tak ada risiko desinkron; hitung saat query (jumlah transaksi/bulan modest).
- Fase A entri manual (pemasukan & pengeluaran); Fase B otomatiskan pemasukan dari Payment (kolom `source` disiapkan).
- Kode auto dari tanggal (bisa ditimpa manual bila perlu — opsional; default auto).
- Kategori bisa dinonaktifkan (soft, `active=false`) agar tak hapus data historis.
- Role `accounting` via migrasi idempotent + reset cache Spatie; test pakai role hasil migrasi (RefreshDatabase).
