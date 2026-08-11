# Spec — Invoice Layanan / Jasa (standalone)

- **Tanggal:** 2026-08-11
- **Branch:** `feat/invoice-layanan-jasa`
- **Scope:** Modul mandiri untuk menagih **jasa non-publikasi** (instalasi & setup OJS, perbaikan, upgrade/migrasi, desain, hosting, maintenance, paket bundle, Turnitin & penurunan plagiasi). Berisi: katalog layanan yang bisa dikelola, master klien jasa, invoice custom dengan rincian item, pencatatan pembayaran bertahap (DP → pelunasan), pelacakan status pengerjaan, PDF, dan pengiriman email. Template PDF & email **meniru** invoice order buku/artikel.
- **Di luar scope:** integrasi apa pun dengan keuangan (Jurnal Kas, Rekap, Analisa Profit, Dashboard), `tb_orders`, `tb_payments`, `tb_invoices`, refund, approval pembayaran, langganan/perpanjangan otomatis, portal klien.

> **Sifat modul: benar-benar standalone.** Nol titik singgung dengan modul mana pun yang sudah ada. Tidak menulis ke `tb_payments`, tidak memicu entri kas, tidak muncul di laporan keuangan. Ini **keputusan sengaja dari pemilik produk**, bukan pekerjaan yang belum selesai — jangan "diperbaiki" jadi terhubung tanpa keputusan baru. Dikunci dengan tes (§10, T-ISO).

---

## 1. Tujuan & Kriteria Sukses

1. superadmin/manager membuka menu **Layanan → Invoice Layanan**, membuat invoice custom: pilih klien (master atau baru), tambah baris layanan dari katalog (harga terisi otomatis, boleh ditimpa), diskon opsional, jatuh tempo.
2. Sistem memberi **nomor invoice** unik `INV-JS-YYYYMM-0001`, menghitung subtotal/total, dan menyimpan **snapshot** data klien + item.
3. Invoice bisa **diunduh sebagai PDF** dan **dikirim ke email klien** kapan pun lewat tombol manual.
4. Pembayaran dicatat bertahap (DP, cicilan, pelunasan). Setiap pencatatan memperbarui `paid_total`, `remaining`, dan `payment_status` **secara otomatis** — tidak ada dropdown status bayar yang bisa diketik salah.
5. Status pengerjaan (`belum → proses → selesai`, plus `batal`) diubah dari halaman detail; setiap transisi tercatat di log dengan pelaku, waktu, dan catatan.
6. Guard: hanya superadmin & manager melihat menunya; `cancel` + `destroy` superadmin saja. Suite tetap hijau.

---

## 2. Keputusan Desain (dan alasannya)

Enam keputusan yang menentukan bentuk modul. Ditulis lengkap dengan alasannya supaya tidak dibongkar tanpa sadar.

### 2.1 Satu entitas: invoice = pekerjaan ("invoice hidup")

Satu baris `tb_service_invoices` mewakili **satu pekerjaan sekaligus satu dokumen tagihan**. Nomor invoice tidak berubah sepanjang umur pekerjaan.

**DP → pelunasan ditangani lewat tabel pembayaran anak, bukan invoice baru.** Setiap pembayaran jadi satu baris di `tb_service_invoice_payments`; PDF selalu mencetak Riwayat Pembayaran + Total Terbayar + Sisa Tagihan. "Mengirim invoice pelunasan" = mengirim ulang PDF yang sama, yang kini menunjukkan sisa tagihan.

Ini setara perilaku PDF yang sudah ada: `InvoicePdfData::for()` menarik **seluruh** pembayaran order, sehingga tiap invoice buku/artikel pun mencetak rekap penuh, bukan hanya nominal satu pembayaran.

Alternatif yang ditolak:
- **Dua entitas (Order Layanan → N Invoice)** — pola order buku/artikel. Paling fleksibel, tapi harganya 2 menu + 2 CRUD + 2 layar detail untuk transaksi yang seringkali Rp250 ribu.
- **Invoice turunan (`parent_id`)** — dokumen pelunasan bernomor sendiri. Bukan tidak mungkin: kalau nanti klien (mis. bagian keuangan kampus) menuntut dokumen tagihan terpisah per termin, cukup tambah kolom `parent_id` — migrasi aditif, bukan rombak. Tidak dibangun sekarang.

### 2.2 Snapshot, bukan referensi

Item invoice menyimpan **salinan** nama + harga layanan. Invoice menyimpan **salinan** nama/instansi/email/telepon/alamat klien.

`service_catalog_id` murni jejak asal. `service_client_id` punya satu tugas nyata — menjawab "pekerjaan apa saja untuk Universitas X" di halaman detail klien — tapi **bukan** sumber data cetakan. Keduanya `nullOnDelete`: klien atau layanan yang dihapus tidak boleh menyeret invoice ikut hilang atau berubah isi.

Konsekuensi yang diinginkan: menaikkan harga katalog atau mengganti alamat klien **tidak** mengubah invoice yang sudah terbit. Dokumen tagihan yang berubah isi setelah dikirim adalah cacat, bukan fitur.

### 2.3 Total disimpan, bukan dihitung ulang tiap render

`subtotal`, `total`, `paid_total`, `remaining`, `payment_status` adalah kolom tersimpan, ditulis ulang oleh `recalcTotals()` setiap item atau pembayaran berubah — pola yang sama dengan `SalarySlip::recalcTotals()`.

Alasan: daftar DataTables harus bisa mengurutkan dan memfilter berdasarkan sisa tagihan & status bayar di SQL, tanpa N+1. Harganya satu titik yang wajib disiplin dipanggil; dikunci dengan tes (§10, T-CALC).

### 2.4 Tidak ada status "draft"

Hanya dua sumbu: **status pengerjaan** dan **status bayar**. Sumbu ketiga (draft/terbit) cuma menghasilkan tiga dropdown yang saling bertabrakan.

Sebagai gantinya, **aturan kunci edit** (§5.4): invoice bebas diedit selama belum ada pembayaran **dan** belum pernah dikirim email; setelah itu hanya superadmin, wajib alasan, tercatat di log.

### 2.5 Status bayar turunan, status kerja manual

`payment_status` **tidak pernah** diketik manusia — selalu hasil hitungan dari SUM pembayaran. `work_status` sepenuhnya manual dan boleh maju-mundur bebas, karena pekerjaan jasa rutin kembali ke Proses akibat revisi klien; memaksa satu arah hanya membuat operator berbohong.

### 2.6 Email tidak bergantung Google Drive

Email dikirim **lebih dulu**, upload Drive menyusul sebagai best-effort. Ini memperbaiki jebakan pada `SendInvoiceJob` yang ada: di sana `Mail::to(...)` berada **di dalam** `if ($folderId)`, sehingga Drive bermasalah = invoice tidak pernah sampai ke klien, tanpa jejak apa pun.

---

## 3. Data Model

Lima tabel + satu tabel log. Semua tabel baru — tidak ada migrasi lama yang meng-query model-model ini, jadi jebakan `SoftDeletes` + global scope pada migrasi lama tidak berlaku di sini.

### 3.1 `tb_service_clients` — master klien jasa

```php
$table->id();
$table->string('name');                         // nama PIC / klien
$table->string('institution')->nullable();      // instansi / kampus / penerbit
$table->string('email')->nullable();
$table->string('phone', 40)->nullable();
$table->text('address')->nullable();
$table->text('note')->nullable();
$table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
$table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
$table->timestamps();
$table->softDeletes();
$table->index('name');
```

### 3.2 `tb_service_catalogs` — katalog layanan

```php
$table->id();
$table->string('category', 40);                 // lihat CATEGORIES di bawah
$table->string('name');
$table->decimal('price', 15, 2)->default(0);        // harga dasar / batas bawah
$table->decimal('price_max', 15, 2)->nullable();    // batas atas utk harga berkisar
$table->string('unit', 20)->nullable();             // paket | bulan | tahun | jurnal
$table->text('description')->nullable();            // isi paket bundle
$table->boolean('is_active')->default(true);
$table->unsignedInteger('position')->default(0);
$table->timestamps();
$table->softDeletes();
$table->index(['category', 'position']);
```

`ServiceCatalog::CATEGORIES` (kunci → label):

```
instalasi   => 'Layanan Instalasi & Setup'
perbaikan   => 'Layanan Perbaikan'
upgrade     => 'Upgrade & Migrasi'
desain      => 'Desain OJS'
hosting     => 'Hosting OJS (per Tahun)'
maintenance => 'Maintenance'
similarity  => 'Turnitin & Penurunan Plagiasi'
bundle      => 'Paket Bundle'
lainnya     => 'Lainnya'
```

`price_max` yang terisi menandakan harga berkisar; UI menampilkan `Rp500.000 – Rp1.000.000` dan mengisi form dengan `price` (batas bawah) sebagai titik awal yang boleh dinaikkan.

### 3.3 `tb_service_invoices` — inti

```php
$table->id();
$table->string('invoice_no')->unique();
$table->foreignId('service_client_id')->nullable()
      ->constrained('tb_service_clients')->nullOnDelete();   // jejak asal saja

// SNAPSHOT klien — sumber kebenaran untuk cetakan
$table->string('client_name');
$table->string('client_institution')->nullable();
$table->string('client_email')->nullable();
$table->string('client_phone', 40)->nullable();
$table->text('client_address')->nullable();

$table->date('issued_at');
$table->date('due_at')->nullable();

$table->string('work_status', 20)->default('belum');   // belum|proses|selesai|batal
$table->timestamp('work_started_at')->nullable();
$table->timestamp('work_finished_at')->nullable();

$table->decimal('subtotal', 15, 2)->default(0);
$table->decimal('discount', 15, 2)->default(0);
$table->decimal('total', 15, 2)->default(0);
$table->decimal('paid_total', 15, 2)->default(0);
$table->decimal('remaining', 15, 2)->default(0);       // boleh negatif = lebih bayar
$table->string('payment_status', 20)->default('belum');// belum|dp|lunas — TURUNAN

$table->text('note')->nullable();            // tercetak di PDF
$table->text('internal_note')->nullable();   // TIDAK tercetak

$table->string('pdf_drive_url')->nullable();
$table->timestamp('sent_at')->nullable();
$table->unsignedInteger('sent_count')->default(0);

$table->text('cancel_reason')->nullable();
$table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
$table->timestamp('cancelled_at')->nullable();

$table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
$table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
$table->timestamps();
$table->softDeletes();
$table->index(['work_status', 'payment_status']);
$table->index('issued_at');
```

### 3.4 `tb_service_invoice_items`

```php
$table->id();
$table->foreignId('service_invoice_id')->constrained('tb_service_invoices')->cascadeOnDelete();
$table->foreignId('service_catalog_id')->nullable()
      ->constrained('tb_service_catalogs')->nullOnDelete();  // jejak asal saja
$table->string('name');                       // SNAPSHOT
$table->text('description')->nullable();      // SNAPSHOT
$table->decimal('qty', 8, 2)->default(1);
$table->decimal('unit_price', 15, 2)->default(0);
$table->decimal('subtotal', 15, 2)->default(0);
$table->unsignedInteger('position')->default(0);
$table->timestamps();
```

### 3.5 `tb_service_invoice_payments`

```php
$table->id();
$table->foreignId('service_invoice_id')->constrained('tb_service_invoices')->cascadeOnDelete();
$table->date('paid_at');
$table->string('type', 20);                   // dp | cicilan | pelunasan
$table->decimal('amount', 15, 2);
$table->string('method', 20)->default('transfer');  // transfer | tunai | lainnya
$table->string('reference')->nullable();      // no. transaksi / rekening pengirim
$table->text('note')->nullable();
$table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
$table->timestamps();
$table->softDeletes();
```

> `softDeletes` di sini disengaja: menghapus pembayaran adalah koreksi, dan barisnya tetap perlu bisa ditelusuri. `paid_total` menjumlahkan hanya baris yang tidak ter-*trash* (perilaku bawaan global scope).

### 3.6 `tb_service_invoice_logs`

```php
$table->id();
$table->foreignId('service_invoice_id')->constrained('tb_service_invoices')->cascadeOnDelete();
$table->string('event', 30);                  // lihat daftar di bawah
$table->string('from_status', 20)->nullable();
$table->string('to_status', 20)->nullable();
$table->text('note')->nullable();
$table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
$table->timestamps();
```

`event`: `created`, `updated`, `status_changed`, `payment_added`, `payment_deleted`, `emailed`, `email_failed`, `cancelled`.

Log **tidak bisa dihapus** oleh siapa pun (tidak ada route destroy) — sama seperti riwayat Penugasan Naskah.

---

## 4. Model & Relasi

```
ServiceClient   hasMany ServiceInvoice
ServiceCatalog  (berdiri sendiri; dirujuk lemah oleh item)

ServiceInvoice  belongsTo ServiceClient (nullable)
                hasMany   ServiceInvoiceItem    (orderBy position, id)
                hasMany   ServiceInvoicePayment (orderBy paid_at, id)
                hasMany   ServiceInvoiceLog     (latest)
                belongsTo User as creator / canceller
```

Konstanta:

```php
ServiceInvoice::WORK_STATUS    = ['belum'=>'Belum Dikerjakan','proses'=>'Proses',
                                 'selesai'=>'Selesai','batal'=>'Dibatalkan'];
ServiceInvoice::PAYMENT_STATUS = ['belum'=>'Belum Dibayar','dp'=>'DP','lunas'=>'Lunas'];
ServiceInvoicePayment::TYPES   = ['dp'=>'DP','cicilan'=>'Cicilan','pelunasan'=>'Pelunasan'];
ServiceInvoicePayment::METHODS = ['transfer'=>'Transfer','tunai'=>'Tunai','lainnya'=>'Lainnya'];
```

Helper di model: `isEditable()`, `isOverdue()`, `isOverpaid()`, `overpaidAmount()`, `workStatusLabel()`, `paymentStatusLabel()`.

---

## 5. Algoritma

### 5.1 Penomoran invoice

Format `INV-JS-YYYYMM-NNNN`, urutan per bulan penerbitan.

```php
// dijalankan DI DALAM transaksi yang sama dengan insert-nya
$prefix = 'INV-JS-' . $issuedAt->format('Ym') . '-';
$last   = ServiceInvoice::withTrashed()
    ->where('invoice_no', 'like', $prefix . '%')
    ->lockForUpdate()
    ->selectRaw('MAX(CAST(SUBSTRING(invoice_no, ?) AS UNSIGNED)) AS seq', [strlen($prefix) + 1])
    ->value('seq');
$no = $prefix . str_pad((string) (((int) $last) + 1), 4, '0', STR_PAD_LEFT);
```

Tiga lapis pengaman:
1. `lockForUpdate` di dalam transaksi → dua permintaan bersamaan berbaris. **Dengan satu celah yang diketahui:** pada bulan yang masih kosong, `LIKE 'prefix%' FOR UPDATE` hanya mengambil *gap lock* yang kompatibel-bersama, jadi dua pemanggil sama-sama menghitung `0001` lalu saling mengunci saat INSERT dan salah satunya kena deadlock. Karena itu lapis 3 wajib ikut menangani `40001`/`1213`, bukan hanya duplikat.
2. `withTrashed()` → nomor invoice yang dihapus **tidak** pernah didaur ulang.
3. Unique index + retry maksimal 3× pada `QueryException` yang **benar-benar** balapan. Dicocokkan lewat SQLSTATE + kode driver di `errorInfo` (pola yang sudah dipakai `EnforceIdempotency`), **bukan** teks pesan — Laravel menempelkan seluruh SQL ke pesan, sehingga mencari nama kolom di sana ikut cocok dengan duplikat kolom lain.

Dua gerbang yang gagal keras, bukan menebak: sufiks non-angka di bawah prefiks yang sama, dan kuota `9999` per bulan. Keduanya membuat nomor yang sama diterbitkan dua kali kalau dibiarkan diam.

> Sengaja berbeda dari `SalarySlipController::generateSlipNo()` yang memakai `count() + 1`: pola itu balapan, dan menghasilkan nomor duplikat begitu ada baris yang dihapus.

### 5.2 `recalcTotals()`

Dipanggil setelah: item disimpan/diubah/dihapus, pembayaran ditambah, pembayaran dihapus, diskon diubah.

```
subtotal   = Σ items.subtotal            // subtotal item = round(qty × unit_price, 2)
total      = max(subtotal − discount, 0)
paid_total = Σ payments.amount           // baris ter-soft-delete otomatis dikecualikan
remaining  = total − paid_total          // boleh negatif

payment_status:
    paid_total <= 0        → 'belum'
    paid_total <  total    → 'dp'
    paid_total >= total    → 'lunas'
```

Semua aritmetika di server. JavaScript di form hanya **pratinjau**; nilai yang disimpan selalu dihitung ulang di PHP dari `qty` dan `unit_price` mentah.

**Lebih bayar tidak diblokir.** `remaining` negatif ditampilkan sebagai "Lebih bayar Rp X" di detail dan PDF. Memblokirnya terdengar rapi sampai klien mentransfer lebih karena biaya admin — lalu operator terpaksa memalsukan angka supaya form mau tersimpan. Validasi yang ada: `amount > 0`, dan `total > 0` saat invoice disimpan (minimal satu item).

### 5.3 Mesin status pengerjaan

Tinggal di `App\Services\ServiceInvoiceWorkflow` (`changeStatus()` + `cancel()`), bukan sebagai metode model — mengikuti konvensi "ubah keadaan + tulis baris log" yang sudah dipakai `CashPeriodService` dan `TitleProgressService`. Model tetap sekadar rekaman, dan kedua jalur (pindah status & batal) memakai satu implementasi yang sama alih-alih ditulis dua kali.

Transisi bebas antara `belum ⇄ proses ⇄ selesai`. `batal` adalah keadaan terminal yang hanya bisa dimasuki (dan hanya oleh superadmin).

```
→ proses    : work_started_at  = now, HANYA jika masih null
                                 (tidak ditimpa saat kembali ke Proses)
→ selesai   : work_finished_at = now
selesai → * : work_finished_at = null      ← supaya tanggal tidak berbohong
→ batal     : superadmin saja; wajib cancel_reason;
              set cancelled_by/cancelled_at;
              invoice batal MENOLAK pembayaran baru
setiap transisi → satu baris log (event=status_changed, from, to, pelaku, catatan)
```

### 5.4 Aturan kunci edit

```
isEditable() = payments()->doesntExist()
               AND sent_at IS NULL
               AND work_status !== 'batal'
```

- `isEditable() == true` → superadmin/manager bebas mengubah klien, item, diskon, tanggal.
- `isEditable() == false` → form edit tertutup untuk manager. superadmin tetap bisa mengoreksi, **wajib** mengisi alasan, dan perubahannya tercatat sebagai log `updated`.

Alasannya: nomor invoice itu sudah beredar di luar. Mengubah nominalnya diam-diam membuat PDF yang dipegang klien berbeda dari yang ada di sistem.

`invoice_no`, `paid_total`, `remaining`, dan `payment_status` **tidak pernah** bisa diedit lewat form mana pun.

### 5.5 Alur kirim email

```
ServiceInvoiceController::send()
  → guard: client_email tidak kosong (kalau kosong: pesan galat, tidak dispatch)
  → dispatch SendServiceInvoiceJob(id)

SendServiceInvoiceJob::handle(GoogleDriveService $drive)
  1. muat invoice (+items, +payments, +client); berhenti diam-diam kalau sudah hilang
  2. render PDF ke variabel
  3. Mail::to(client_email)->send(new ServiceInvoiceMail($invoice))   ← WAJIB, DULUAN
  4. try { upload Drive → simpan pdf_drive_url } catch { Log::warning }  ← best-effort
  5. sent_at = now, sent_count++, log event=emailed
  failed() → log event=email_failed dengan pesan galat
```

Langkah 3 mendahului 4, dan kegagalan 4 tidak membatalkan apa pun. Ini pembalikan sengaja dari `SendInvoiceJob` yang ada.

### 5.6 Aturan kecil yang mudah salah tafsir

- **Diskon adalah nominal rupiah, bukan persen.** Satu kolom `discount` di tingkat invoice; tidak ada diskon per item. Validasi `0 ≤ discount ≤ subtotal`.
- **`type` pembayaran (`dp`/`cicilan`/`pelunasan`) murni label cetakan.** Dipilih operator, tidak memengaruhi perhitungan apa pun — `payment_status` selalu dihitung dari nominal (§5.2), bukan dari label ini. Operator boleh menandai "pelunasan" walau ternyata masih bersisa; angkanya yang menentukan.
- **Klien baru dari form invoice selalu membuat baris master.** Mengisi blok klien secara manual menyimpan satu `ServiceClient` baru, lalu menyalinnya ke invoice. Tidak ada invoice tanpa `service_client_id` kecuali klien itu dihapus belakangan.
- **`destroy` adalah soft delete.** Invoice hilang dari daftar, item/pembayaran/log tetap ada di basis data, dan nomornya tetap terpakai selamanya (§5.1). Superadmin saja.
- **Menghapus katalog atau klien tidak pernah mengubah invoice.** `nullOnDelete` + snapshot; katalog yang tidak dipakai lagi sebaiknya dinonaktifkan (`is_active = false`), bukan dihapus.

---

## 6. Rute & Hak Akses

### 6.1 Rute (`routes/web.php`, di dalam grup auth)

```php
Route::prefix('layanan')->name('service.')->group(function () {
    // Invoice
    Route::get   ('invoice',                 [ServiceInvoiceController::class, 'index'])  ->name('invoice.index');
    Route::get   ('invoice/create',          [ServiceInvoiceController::class, 'create']) ->name('invoice.create');
    Route::post  ('invoice',                 [ServiceInvoiceController::class, 'store'])  ->name('invoice.store');
    Route::get   ('invoice/{id}',            [ServiceInvoiceController::class, 'show'])   ->name('invoice.show')->whereNumber('id');
    Route::get   ('invoice/{id}/edit',       [ServiceInvoiceController::class, 'edit'])   ->name('invoice.edit')->whereNumber('id');
    Route::put   ('invoice/{id}',            [ServiceInvoiceController::class, 'update']) ->name('invoice.update')->whereNumber('id');
    Route::delete('invoice/{id}',            [ServiceInvoiceController::class, 'destroy'])->name('invoice.destroy')->whereNumber('id');
    Route::post  ('invoice/{id}/status',     [ServiceInvoiceController::class, 'status']) ->name('invoice.status')->whereNumber('id');
    Route::post  ('invoice/{id}/cancel',     [ServiceInvoiceController::class, 'cancel']) ->name('invoice.cancel')->whereNumber('id');
    Route::get   ('invoice/{id}/pdf',        [ServiceInvoiceController::class, 'pdf'])    ->name('invoice.pdf')->whereNumber('id')->middleware('throttle:export');
    Route::post  ('invoice/{id}/send',       [ServiceInvoiceController::class, 'send'])   ->name('invoice.send')->whereNumber('id');
    // Pembayaran
    Route::post  ('invoice/{id}/payment',    [ServiceInvoicePaymentController::class, 'store'])  ->name('invoice.payment.store')->whereNumber('id');
    Route::delete('invoice/{id}/payment/{paymentId}', [ServiceInvoicePaymentController::class, 'destroy'])->name('invoice.payment.destroy')->whereNumber('id')->whereNumber('paymentId');
    // Katalog
    Route::get   ('katalog',      [ServiceCatalogController::class, 'index'])  ->name('catalog.index');
    Route::post  ('katalog',      [ServiceCatalogController::class, 'store'])  ->name('catalog.store');
    Route::put   ('katalog/{id}', [ServiceCatalogController::class, 'update']) ->name('catalog.update')->whereNumber('id');
    Route::delete('katalog/{id}', [ServiceCatalogController::class, 'destroy'])->name('catalog.destroy')->whereNumber('id');
    // Klien
    Route::get   ('klien',        [ServiceClientController::class, 'index'])  ->name('client.index');
    Route::get   ('klien/{id}',   [ServiceClientController::class, 'show'])   ->name('client.show')->whereNumber('id');
    Route::post  ('klien',        [ServiceClientController::class, 'store'])  ->name('client.store');
    Route::put   ('klien/{id}',   [ServiceClientController::class, 'update']) ->name('client.update')->whereNumber('id');
    Route::delete('klien/{id}',   [ServiceClientController::class, 'destroy'])->name('client.destroy')->whereNumber('id');
});
```

### 6.2 `config/permissions.php` (WAJIB — `EnforcePermission` fail-closed)

```php
'service_invoice' => [
    'label'   => 'Invoice Layanan',
    'actions' => [
        'view'    => ['service.invoice.index', 'service.invoice.show'],
        'create'  => ['service.invoice.create', 'service.invoice.store'],
        'edit'    => ['service.invoice.edit', 'service.invoice.update'],
        'status'  => ['service.invoice.status'],
        'payment' => ['service.invoice.payment.store', 'service.invoice.payment.destroy'],
        'export'  => ['service.invoice.pdf'],
        'send'    => ['service.invoice.send'],
        'cancel'  => ['service.invoice.cancel'],
        'delete'  => ['service.invoice.destroy'],
    ],
],
'service_catalog' => [
    'label'   => 'Katalog Layanan',
    'actions' => [
        'view'   => ['service.catalog.index'],
        'manage' => ['service.catalog.store', 'service.catalog.update', 'service.catalog.destroy'],
    ],
],
'service_client' => [
    'label'   => 'Klien Jasa',
    'actions' => [
        'view'   => ['service.client.index', 'service.client.show'],
        'manage' => ['service.client.store', 'service.client.update', 'service.client.destroy'],
    ],
],
```

### 6.3 `AccessMatrixSeeder`

Tidak perlu menambah hibah apa pun: `manager` memakai `'*'`, jadi permission baru otomatis diterimanya; `admin`, `marketing`, `production`, `accounting` memakai daftar eksplisit sehingga tidak kebagian — persis yang diinginkan. superadmin lolos lewat `Gate::before`.

Satu-satunya perubahan: tambahkan ke `$superadminOnly`

```php
'service_invoice.cancel',
'service_invoice.delete',
```

### 6.4 Menu sidebar

Grup baru **Layanan**, ditaruh setelah grup Pembayaran:

```blade
@canany(['service_invoice.view', 'service_catalog.view', 'service_client.view'])
    <li class="nav-item nav-category">Layanan</li>
    {{-- Invoice Layanan | Katalog Layanan | Klien Jasa --}}
@endcanany
```

Tidak digabung ke grup Pembayaran: semua item di grup itu bermuara ke kas, dan modul ini justru tidak.

---

## 7. Layar

| # | Layar | Isi |
|---|-------|-----|
| 1 | **Daftar Invoice Layanan** | DataTables (pola Arsip Judul). Kolom: No Invoice, Klien, Total, Sisa, Status Kerja, Status Bayar, Jatuh Tempo, Aksi. Filter: status kerja, status bayar, rentang tanggal terbit. Badge ganda per baris. |
| 2 | **Buat / Edit Invoice** | Blok klien (pilih dari master via select, atau isi baru → tersimpan sebagai klien baru); blok item dinamis (pilih katalog → nama+harga terisi, boleh ditimpa; qty; subtotal terhitung di JS sebagai pratinjau); diskon; catatan; catatan internal; jatuh tempo. Tombol tambah/hapus baris. |
| 3 | **Detail Invoice** | Ringkasan + dua badge status; tabel item; riwayat pembayaran + form catat pembayaran (modal); pengubah status kerja + catatan; tombol Unduh PDF, Kirim Email (menampilkan `sent_at` & `sent_count`), Batalkan (superadmin), Hapus (superadmin); panel riwayat/log. |
| 4 | **Katalog Layanan** | DataTables dikelompokkan per kategori; CRUD inline/modal; toggle aktif; urutan. |
| 5 | **Klien Jasa** | DataTables + CRUD; halaman detail klien menampilkan daftar invoice miliknya. |

Semua tabel memakai DataTables (`datatables.net-bs4`), mengikuti konvensi UI proyek.

---

## 8. PDF

Berkas baru `resources/views/services/invoice_pdf.blade.php`, disalin dari `resources/views/payments/invoices/book_invoice_pdf.blade.php`.

**Dipertahankan apa adanya:** seluruh blok `<style>`, logo latar `bg-pdf.png`, kop perusahaan, warna `#003366`, blok Metode Pembayaran (rekening BNI), tabel ringkasan total, tanda tangan `ttd.png`, blok "Informasi Penting", dan footer.

**Diubah:**

| Bagian | Invoice buku/artikel | Invoice layanan |
|---|---|---|
| Kepada Yth. | penulis pertama + afiliasi | `client_name`, `client_institution`, `client_email`, `client_phone` (snapshot) |
| Detail Order | jenis layanan, judul, scope, bab, penulis | **dihapus** |
| Rincian Biaya | satu baris "Biaya Publikasi" | tabel `No / Layanan / Qty / Harga / Subtotal`, lalu baris Diskon (bila ada) dan Total |
| Riwayat Pembayaran | `$order->payments` | `$invoice->payments` (tanggal, jenis, metode, jumlah) |
| Ringkasan total | Total / Terbayar / Sisa / Status Invoice | idem + baris **Status Pengerjaan**; kalau `remaining < 0` baris Sisa berganti label **Lebih Bayar** |

Data PDF dirakit oleh `App\Support\ServiceInvoicePdfData::for(ServiceInvoice $invoice)` — satu sumber yang dipakai bersama oleh route unduh dan job email, meniru peran `InvoicePdfData`.

Nama berkas: `Invoice_Layanan_{invoice_no}.pdf`.

---

## 9. Email

- `App\Mail\ServiceInvoiceMail` — subject: `Invoice Layanan #{invoice_no} — Avidpedia`.
- View `resources/views/pages/mails/service_invoice_mail.blade.php`, disalin dari `inv_book_mail.blade.php` (kop biru `#055eb6`, logo putih, tabel ringkas, tombol/penutup sama).
- Baris ringkasan: No Invoice, Rincian Layanan (daftar nama item), Total Biaya, Jumlah Dibayar, Sisa Bayar, Jatuh Tempo, Status Pengerjaan.
- PDF dilampirkan langsung ke email (`Attachment::fromData`).

> **Cacat yang tidak diwarisi:** `inv_book_mail.blade.php` dan `InvoiceMail::envelope()` memakai `$invoice->inv_no`, padahal kolomnya `invoice_no` — atributnya null, sehingga subjek email selama ini terkirim sebagai "Invoice Mail Order" tanpa nomor dan judulnya kosong. Modul baru memakai `invoice_no`. Perbaikan template lama **di luar scope spec ini**; dicatat sebagai utang terpisah.

---

## 10. Pengujian

`tests/Feature/ServiceInvoiceTest.php` (+ berkas pendukung). Semua terhadap `avidpedia_simapa_test` lewat `.env.testing`.

| Kode | Yang diuji |
|---|---|
| T-NO-1 | Nomor invoice berurutan per bulan; bulan berganti → urutan mulai dari 0001 |
| T-NO-2 | Invoice dihapus (soft) → nomor berikutnya **tidak** mendaur ulang nomor itu |
| T-CALC-1 | `recalcTotals` benar setelah simpan item, termasuk diskon dan `qty` desimal |
| T-CALC-2 | `payment_status` bergerak `belum → dp → lunas` seiring pembayaran dicatat |
| T-CALC-3 | Hapus pembayaran → `paid_total`/`remaining`/`payment_status` mundur dengan benar |
| T-CALC-4 | Lebih bayar → status `lunas`, `remaining` negatif, ditandai lebih bayar |
| T-WS-1 | `belum → proses` mengisi `work_started_at`; kembali ke `proses` tidak menimpanya |
| T-WS-2 | `→ selesai` mengisi `work_finished_at`; `selesai → proses` mengosongkannya |
| T-WS-3 | Setiap transisi menulis satu baris log dengan pelaku & catatan |
| T-WS-4 | `batal` hanya superadmin, wajib alasan; invoice batal menolak pembayaran baru |
| T-EDIT-1 | Invoice tanpa pembayaran & belum terkirim → manager boleh edit |
| T-EDIT-2 | Setelah ada pembayaran atau `sent_at` terisi → manager 403; superadmin boleh dengan alasan, tercatat di log |
| T-ACL-1 | `admin`, `marketing`, `production`, `accounting` → 403 di seluruh route `service.*` |
| T-ACL-2 | `manager` boleh semua kecuali `cancel` & `destroy`; superadmin boleh semua |
| T-PDF-1 | Route PDF mengembalikan 200 dan `Content-Type: application/pdf` |
| T-MAIL-1 | `send` men-dispatch job; job mengirim `ServiceInvoiceMail` ke `client_email` |
| T-MAIL-2 | **Drive melempar exception → email tetap terkirim**, `sent_at` tetap terisi, `pdf_drive_url` null |
| T-MAIL-3 | `client_email` kosong → tidak dispatch, pesan galat |
| T-SNAP-1 | Ubah harga katalog / alamat klien setelah invoice terbit → invoice lama tidak berubah |
| T-SNAP-2 | Hapus katalog & klien → invoice tetap utuh, FK jadi null, PDF tetap ter-render |
| T-CLIENT-1 | Isi blok klien secara manual → satu `ServiceClient` baru terbentuk dan tersalin ke invoice |
| **T-ISO** | **Buat invoice layanan + catat pembayaran → jumlah baris `tb_payments`, `tb_orders`, `tb_invoices`, dan tabel kas tidak bertambah sama sekali** |

T-ISO adalah penjaga keputusan §0/§2 — kalau nanti ada yang menyambungkan modul ini ke keuangan tanpa keputusan baru, tes ini merah lebih dulu.

---

## 11. Seed Katalog

`ServiceCatalogSeeder` mengisi daftar harga yang berlaku (harga berkisar → `price` = batas bawah, `price_max` = batas atas):

**instalasi** — Instalasi OJS Basic 500.000 · Instalasi + Konfigurasi OJS 750.000 · Instalasi + Desain Tampilan 1.250.000 · Setup Lengkap Jurnal 2.500.000 · Setup Multi Jurnal 3.500.000–5.000.000

**perbaikan** — Fix Error Ringan 250.000–500.000 · Fix Error Sedang 500.000–1.000.000 · Fix Error Berat 1.000.000–2.500.000 · Perbaikan SMTP 350.000 · Perbaikan DOI Crossref 500.000 · Perbaikan PKP PN 500.000 · Pembersihan Malware 750.000–2.000.000

**upgrade** — Upgrade Minor 750.000 · Upgrade Mayor (3.2→3.3, 3.3→3.4) 1.500.000–3.000.000 · Migrasi Hosting 1.000.000–2.500.000 · Migrasi VPS 1.500.000–3.500.000

**desain** — Redesign Homepage 750.000 · Custom Homepage Premium 1.500.000 · Desain Logo Jurnal 250.000 · Custom Theme OJS 2.500.000–5.000.000

**hosting** (unit: tahun) — Starter (5GB) 750.000 · Standard (10GB) 1.250.000 · Professional (25GB) 2.500.000 · VPS Managed 4.500.000–12.000.000

**maintenance** — Bulanan 300.000 (unit: bulan) · Semester 1.500.000 · Tahunan 2.500.000

**bundle** (unit: tahun, isi paket masuk ke `description`) — Paket Starter 1.750.000 · Paket Professional 3.500.000 · Paket Enterprise 6.500.000

**similarity** — kategori dibuat **kosong**. Tarif Turnitin dan penurunan plagiasi belum ditetapkan; diisi lewat CRUD katalog tanpa perlu deploy.

Seeder bersifat `firstOrCreate` berdasarkan `category + name`, sehingga aman dijalankan ulang dan tidak menimpa harga yang sudah disunting operator.

---

## 12. Berkas yang Disentuh

**Baru**
```
database/migrations/  6 migrasi (clients, catalogs, invoices, items, payments, logs)
database/seeders/ServiceCatalogSeeder.php
app/Models/            ServiceClient, ServiceCatalog, ServiceInvoice,
                       ServiceInvoiceItem, ServiceInvoicePayment, ServiceInvoiceLog
app/Http/Controllers/Pages/  ServiceInvoiceController, ServiceInvoicePaymentController,
                             ServiceCatalogController, ServiceClientController
app/Support/ServiceInvoicePdfData.php
app/Services/ServiceInvoiceWorkflow.php
app/Mail/ServiceInvoiceMail.php
app/Jobs/SendServiceInvoiceJob.php
resources/views/services/     invoices/{index,create,edit,show}, invoice_pdf,
                              catalogs/index, clients/{index,show}
resources/views/pages/mails/service_invoice_mail.blade.php
tests/Feature/ServiceInvoiceTest.php (+ pendukung)
```

**Diubah**
```
routes/web.php                              grup service.*
config/permissions.php                      3 modul baru
database/seeders/AccessMatrixSeeder.php     2 baris ke $superadminOnly
resources/views/layouts/sidebar.blade.php   grup menu "Layanan"
```

Tidak ada berkas modul keuangan, order, atau invoice yang disentuh.

---

## 13. Risiko & Utang yang Diterima

1. **`recalcTotals()` harus disiplin dipanggil.** Konsekuensi denormalisasi. Ditambatkan dengan T-CALC-1..4; kalau nanti muncul jalur tulis baru, tesnya harus ikut bertambah.
1b. **Dua pencatatan pembayaran yang benar-benar bersamaan bisa saling menimpa.** `SUM` di dalam `recalcTotals()` adalah consistent read tanpa kunci baris, jadi `DB::transaction` memberi atomisitas tetapi bukan serialisasi. Hitungannya derivatif sehingga panggilan berikutnya memulihkan, tapi tak ada yang memicunya otomatis. Diterima: alat internal dengan satu-dua operator. Penutupnya kelak `lockForUpdate()` pada baris invoice.
1c. **Uang dihitung dengan float yang dibulatkan ke 2 desimal, bukan integer sen.** Pembulatan sebelum perbandingan sudah menutup kasus "lunas terbaca DP" (ditambatkan tes regresi bersen-pecahan). Kalau modul ini kelak menangani nilai jauh lebih besar atau mata uang lain, pindah ke integer sen adalah langkah berikutnya.
2. **Tidak ada bukti transfer.** Pembayaran dicatat tanpa lampiran struk. Cukup untuk sekarang; kalau perlu, tambah kolom `proof_url` + `GoogleDriveService` menyusul — aditif.
3. **Tidak ada pengingat jatuh tempo / perpanjangan.** Hosting & maintenance tahunan harus dipantau manual lewat filter jatuh tempo. Notifikasi otomatis = pekerjaan berikutnya, bukan sekarang.
4. **`inv_book_mail.blade.php` yang lama tetap rusak** (`inv_no`). Sengaja dibiarkan di luar scope; dicatat agar tidak hilang.
5. **Modul ini tidak masuk laporan apa pun.** Omzet jasa tidak akan terlihat di Dashboard maupun Rekap Kas. Ini yang diminta — tapi harus diingat saat membaca angka pendapatan.
