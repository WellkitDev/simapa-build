# Spec: Koreksi Order (Edit & Batal) + Pengelolaan Judul

**Tanggal:** 2026-08-03
**Status:** **Terimplementasi seluruhnya 2026-08-05.** Bagian 1 lewat [`plans/2026-08-03-order-cancel-edit.md`](../plans/2026-08-03-order-cancel-edit.md), Bagian 2a/2b/2c lewat [`plans/2026-08-03-title-management.md`](../plans/2026-08-03-title-management.md). Sisa satu langkah manual: `php artisan titles:strip-code-prefix --apply` **menunggu izin user** — dry run di database dev tidak menemukan kandidat sama sekali, produksi belum diperiksa.
**Area:** Order Buku/Jurnal, Pembayaran, Direktori Judul, Papan Manuskrip

---

## Ringkasan

Tiga keluhan operasional yang sebenarnya berakar pada satu hal yang sama: **data yang sudah terlanjur masuk tidak punya jalan koreksi.**

1. **Order salah input tidak bisa diperbaiki maupun dibatalkan.** Di daftar order, tombol Edit baru muncul *setelah* order melewati pembayaran. Order yang baru dibuat — justru saat kesalahan paling sering ketahuan — hanya punya tombol Payment. Tombol Hapus tidak ada sama sekali.
2. **Judul otomatis menyimpan data lengkap, bukan judul.** Dropdown judul di form Tambah Order menampilkan `KODE — Judul`; yang tersimpan di `tb_order_details.title` bisa ikut berisi string lengkap itu.
3. **Judul yang sudah dibuat tidak bisa diperbaiki atau dihapus.** Judul yang lahir dari order otomatis berstatus `disetujui`, dan `Title::isEditable()` hanya mengizinkan `draft`/`ditolak` — jadi judul salah ketik terkunci selamanya. Tombol Hapus tidak ada di direktori.

Perubahan ini bersifat **penambahan jalur koreksi + pembersihan ambiguitas data**, bukan perombakan model.

### Keputusan yang sudah dikunci (hasil brainstorming)

1. **Batal order = soft delete berjenjang** + status `dibatalkan`. Bukan hapus permanen. Nomor `ORD-xxxx` tidak pernah dipakai ulang.
2. **Gerbang Edit & Batal:** terbuka selama belum ada pembayaran yang **disetujui** (`tb_payment_approvals.status = 'approved'`). Setelah disetujui, Edit tetap ada dan pembatalan ditempuh lewat alur Refund yang sudah berjalan.
3. **Hak akses:** Edit & Batal untuk marketing pemilik order, plus manager/superadmin. Pulihkan hanya manager/superadmin.
4. **Alasan pembatalan opsional** — tetap disimpan bila diisi.
5. **Dropdown judul menampilkan judul saja.** Kode/bidang ilmu/indeksasi jadi keterangan visual, tidak pernah ikut tersimpan. Mengetik judul baru tetap diizinkan.
6. **Judul berstatus `disetujui` boleh di-edit penuh** oleh admin/manager/superadmin, dengan pencatatan ke `TitleLog`. Perubahan teks judul disinkron ke order yang tertaut.
7. **Hapus judul hanya bila belum terpakai.** Judul yang sudah terpakai tidak bisa dihapus tapi bisa **dinonaktifkan**.

### Koreksi yang muncul saat implementasi (Bagian 1, 2026-08-05)

Enam hal berikut **berbeda dari rancangan di bawah**. Semuanya ditemukan saat implementasi/review dan sudah diterapkan — jangan "dikembalikan" ke teks spec.

1. **Gerbang Batal juga menutup order yang sudah di-refund.** `RefundController::paidIn()` tidak memeriksa approval dan payment refund dibuat tanpa `PaymentApproval`, jadi order yang sudah di-refund tetap lolos `hasApprovedPayment()`. Membatalkannya menghapus entri kas pemasukan **dan** pengeluaran refund-nya sekaligus, padahal uangnya benar-benar sudah masuk lalu keluar. `isCancellable()` karena itu juga memanggil `hasRefund()`.
2. **`cancelPayments()` hanya menyentuh payment ber-status `'paid'`.** `PaymentBookController::reject()` menulis `tb_payments.status = 'rejected'` (bukan hanya status approval-nya). Tanpa penyaringan ini, payment yang sudah ditolak ikut jadi `'batal'` lalu **hidup lagi sebagai `'paid'`** saat order dipulihkan — beserta entri kasnya — dan catatan penolakan aslinya hilang.
3. **Status invoice yang dibatalkan = `'dibatalkan'`, bukan `'batal'`** (§1.3 menulis `'batal'`). `Invoice::STATUSES` tidak mengenal `'batal'`, dan `isOverdue()` menyaring `'dibatalkan'`.
4. **Status order setelah dipulihkan diturunkan dari payment**, tidak dipaksa `'pending'` (§1.3). `PaymentBookController::store()` menyetel `'lunas'` sejak payment lunas/pelunasan disubmit — sebelum approval — sehingga memaksa `'pending'` akan menghilangkannya. Invoice juga dipulihkan ke status aslinya (dibaca dari `InvoiceLog`), bukan dipatok `'diterbitkan'`.
5. **Penjagaan pakai `App\Exceptions\OrderCancellationException`, bukan `DomainException`** (§1.3). Repo sudah punya pola `CashEntryGuardException`: factory bernama + `render()` sendiri, sehingga controller tidak perlu `try/catch`.
6. **`show()` kini menolak marketing yang membuka order milik marketing lain.** Sebelum `withTrashed()`, order yang dibatalkan 404 untuk semua orang; tanpa penjagaan ini membukanya justru jadi lebih longgar daripada saat order masih aktif.

Satu utang teknis ditemukan tapi **sengaja tidak dikerjakan** karena menyentuh kode akuntansi bersama: `PaymentCashSyncService::sync()` menghapus `CashEntry` lewat bulk delete query builder, yang melewati model event — sehingga `CashEntryObserver::deleted()` tak pernah jalan dan `CashLog` tak pernah ditulis. Pra-ada (sudah berlaku untuk `PaymentBookController::reject()`), tapi pembatalan order kini jadi pemicu volume tertingginya. Layak jadi spec tersendiri.

### Koreksi yang muncul saat implementasi (Bagian 2a/2b/2c, 2026-08-05)

1. **Urutan dibalik: resolusi `new:` (§2.2) dikerjakan SEBELUM partial dropdown (§2.1).** Urutan asli menciptakan jendela di mana form sudah mengirim `new:Judul` sementara `resolveForOrder()` belum memahaminya — judul baru akan tersimpan bernama harfiah `new:Judul`. Jalur kompatibilitas string polos membuat urutan terbalik ini aman.
2. **Dua migrasi lama harus diperbaiki** karena memakai model Eloquent langsung: `2026_07_02_000009_backfill_title_codes` (untuk `Title`), menyusul `2026_06_14_000002` dan `2026_07_02_000010` di Bagian 1 (untuk `OrderDetail`). Global scope `SoftDeletes` yang baru ikut terbawa ke titik migrasi **sebelum** kolomnya ada, sehingga `migrate:fresh` — yang dijalankan `RefreshDatabase` di setiap test — gagal total. Semua diganti ke query builder.
3. **`deleteBlockReason()` memakai hasil agregasi** (`withCount` + `withExists` di `TitleController::index()`), dengan query per-relasi sebagai fallback. Tanpa itu kolom Aksi direktori menembak tiga query per baris — sampai 150 query untuk satu halaman berisi 50 judul.
4. **Tombol "Ajukan" tidak lagi digerbangi `isEditable()`.** Setelah `isEditable()` diperlebar ke `disetujui` (§3.1), memakainya akan memunculkan "Ajukan" pada judul yang sudah disetujui. Kini digerbangi `status ∈ {draft, ditolak}` secara eksplisit.

---

## 0. Temuan kode yang menentukan desain

Lima temuan berikut mengubah bentuk solusi dan wajib dipahami sebelum implementasi.

### 0.1 Payment berstatus `paid` sejak di-submit, bukan sejak di-approve

[`PaymentBookController::store()`](../../../app/Http/Controllers/Pages/PaymentBookController.php#L141) membuat `Payment` dengan `'status' => 'paid'` bersamaan dengan `PaymentApproval` berstatus `pending`. Konsekuensinya, di titik submit itu juga sudah terbentuk:

- **Invoice** berstatus `diterbitkan` + `InvoiceLog`;
- **CashEntry** — [`PaymentObserver::saved()`](../../../app/Observers/PaymentObserver.php) memanggil `PaymentCashSyncService::sync()`, yang membuat entri kas untuk setiap payment ber-`status='paid'`;
- **`Order::paidNet()`** sudah menghitung uang itu sebagai masuk.

**Implikasi desain:** definisi "belum ada payment approved" **harus** dibaca dari `tb_payment_approvals.status === 'approved'`, **bukan** dari `tb_payments.status === 'paid'`. Menggunakan yang kedua akan menutup gerbang Batal pada kasus yang justru paling butuh dibatalkan.

### 0.2 KONDISI 2 di daftar order adalah dead code

Karena temuan 0.1, `$hasApprovedPayment` di [`orders/book/index.blade.php:76`](../../../resources/views/orders/book/index.blade.php#L76) bernilai `true` sejak bukti bayar diunggah. Cabang `@elseif($order->status == 'pending' && !$hasApprovedPayment)` di [baris 89](../../../resources/views/orders/book/index.blade.php#L89) tidak pernah tercapai. Kolom Aksi ditulis ulang di spec ini, sehingga cabang mati itu ikut hilang.

### 0.3 `OrderDetail` dan `TitleProgress` di-query langsung, tanpa join ke Order

`OrderDetail::` dipakai langsung di sedikitnya 12 tempat (`ManuscriptTrackerController`, `TitleController`, `ManuscriptStageStatsService`, `TitleProgressService`, `TitleBackfillService`, model `Author`/`Scope`/`Title`/`TitleProgress`, dua controller order). `TitleProgress::` juga di-query langsung di `PerformanceService` dan `ProductionDashboardService` — tanpa menyentuh `tb_orders` sama sekali.

**Implikasi desain:** soft delete pada `tb_orders` saja **tidak cukup**. Order yang dibatalkan akan tetap muncul di papan manuskrip, distribusi, dan dashboard produksi. Soft delete harus berjenjang sampai `tb_order_details` dan `tb_title_progress` agar *global scope* Eloquent membersihkan semua query itu tanpa satu pun call site disentuh.

### 0.4 `title_id` membawa dua makna sekaligus

Field `title_id` di form order dikirim sebagai id angka **atau** teks judul baru, dan pembedanya `is_numeric()` di [`TitleService::resolveForOrder()`](../../../app/Services/TitleService.php). Dua akibatnya:

- Judul yang kebetulan berupa angka (mis. `"2026"`) salah dibaca sebagai id.
- Teks apa pun yang lolos ke sana akan **dibuat menjadi Title baru apa adanya** — termasuk string `KODE — Judul`.

Diperparah Select2: view memasang `data-tags="true"`, dan default `insertTag` Select2 4.x menaruh opsi "buat baru" di **paling atas** hasil pencarian. Ketik-lalu-Enter karena itu cenderung membuat judul kembar alih-alih memilih judul yang sudah ada.

Label yang jadi sumber string kotor ada di empat view, semuanya berpola `{{ $t->code ? $t->code.' — ' : '' }}{{ $t->title }}`:
[`orders/book/create.blade.php:57`](../../../resources/views/orders/book/create.blade.php#L57) · [`orders/journal/create.blade.php:56`](../../../resources/views/orders/journal/create.blade.php#L56) · [`orders/edit.blade.php:72`](../../../resources/views/orders/edit.blade.php#L72) · [`orders/journal/edit.blade.php`](../../../resources/views/orders/journal/edit.blade.php)

### 0.5 Judul dari order selalu `disetujui`, sehingga tak pernah editable

`resolveForOrder()` membuat Title dengan `'status' => 'disetujui'` dan `'asal' => 'order'`. `Title::isEditable()` ([`Title.php:71`](../../../app/Models/Title.php#L71)) hanya mengembalikan `true` untuk `draft`/`ditolak`. Jadi **setiap** judul yang lahir dari order langsung terkunci dari pengeditan. `updateInfo()` yang ada hanya menyentuh informasi publikasi (kode, target terbit, jurnal), bukan teks judulnya.

---

## 1. Bagian 1 — Edit & Batal Order

### 1.1 Perubahan skema

| Migrasi | Tabel | Perubahan |
|---|---|---|
| `2026_08_03_000001_add_cancel_fields_to_tb_orders` | `tb_orders` | `cancel_reason` (text, nullable), `cancelled_by` (FK users, nullable, nullOnDelete), `cancelled_at` (timestamp, nullable). Kolom `deleted_at` **sudah ada** sejak migrasi awal. |
| `2026_08_03_000002_add_soft_deletes_to_order_details_and_progress` | `tb_order_details`, `tb_title_progress` | `softDeletes()` di keduanya. |

### 1.2 Perubahan model

**`App\Models\Order`** — trait `SoftDeletes` (import-nya sudah ada di [`Order.php:8`](../../../app/Models/Order.php#L8) tapi belum dipakai; baris `use HasFactory;` yang terduplikasi di baris 13 & 15 sekalian dirapikan). Tambahan `fillable`: `cancel_reason`, `cancelled_by`, `cancelled_at`. Cast `cancelled_at` ke datetime.

Metode gerbang baru:

```php
/** Ada pembayaran yang benar-benar sudah disetujui approver. */
public function hasApprovedPayment(): bool

/** Order dibatalkan (status 'dibatalkan' atau sudah soft-deleted). */
public function isCancelled(): bool

/** Boleh diedit: selama belum dibatalkan. */
public function isEditable(): bool

/** Boleh dibatalkan: belum dibatalkan DAN belum ada payment yang disetujui. */
public function isCancellable(): bool
```

**Kedua gerbang ini sengaja berbeda.** Edit berlaku di seluruh siklus hidup order kecuali setelah dibatalkan — sesuai keputusan bahwa Edit tetap tersedia pada order yang pembayarannya sudah disetujui (perilaku hari ini yang dipertahankan). Yang dipersempit oleh persetujuan pembayaran hanyalah **Batal**, karena uang sudah masuk dan jalur yang benar adalah Refund.

`hasApprovedPayment()` memakai `payments()->whereHas('approval', fn ($q) => $q->where('status', 'approved'))->exists()` — **bukan** `payments->where('status','paid')` (lihat 0.1).

**`App\Models\OrderDetail`** dan **`App\Models\TitleProgress`** — tambah trait `SoftDeletes`.

### 1.3 Service baru: `App\Services\OrderCancellationService`

Logika pembatalan menyentuh lima tabel dalam satu transaksi dan dipakai dua controller (buku & jurnal). Ditaruh di service agar bisa diuji terpisah dan tidak digandakan.

```php
public function cancel(Order $order, ?string $reason, User $actor): void
public function restore(Order $order, User $actor): void
```

**`cancel()` — urutan dalam satu `DB::transaction`:**

1. **Penjagaan.** Lempar `DomainException` bila `! $order->isCancellable()`, atau bila periode kas CashEntry terkait sudah terkunci (lihat 1.8).
2. **Payment yang belum disetujui** → `status = 'batal'`; `PaymentApproval` terkait → `status = 'rejected'` dengan catatan `"Order dibatalkan"`. `PaymentObserver::saved()` otomatis menghapus CashEntry-nya, karena `PaymentCashSyncService::sync()` menghapus entri untuk payment ber-status ≠ `paid`. Kolom `tb_payments.status` bertipe `string` biasa (bukan enum), jadi nilai `'batal'` aman secara skema.
3. **Invoice** milik order → `status = 'batal'` + baris `InvoiceLog` (`from_status` → `batal`, `changed_by`, catatan).
4. **`TitleProgress`** dari detail order → soft delete. Hilang dari papan manuskrip, distribusi, dan dashboard produksi.
5. **`OrderDetail`** → soft delete.
6. **`Order`** → isi `status = 'dibatalkan'`, `cancel_reason`, `cancelled_by`, `cancelled_at`; lalu soft delete.
7. **Tagihan tertaut** (`tb_tagihan.status = 'jadi_order'` dengan `order_id` ini) → dikembalikan ke `disetujui`, `order_id` & `order_code` dikosongkan, plus `TagihanLog`. Tanpa ini, tagihan yang sudah disetujui akan mati bersama order dan tidak bisa dipakai membuat order pengganti.
8. **`Title` tidak disentuh.** Judul yang jadi yatim akibat pembatalan dibereskan lewat Direktori Judul (Bagian 4) — dan karena `OrderDetail`-nya sudah soft-deleted, judul itu otomatis memenuhi syarat Hapus.

Di luar transaksi: notifikasi ke manager/superadmin lewat `Notifier` (non-fatal, dibungkus `try/catch` dan `Log::warning` — pola yang sama dengan `paymentSubmitted`).

**`restore()`** membalik langkah 2–6 (Payment `batal` → `paid` beserta approval kembali `pending`, Invoice → `diterbitkan`, `TitleProgress`/`OrderDetail`/`Order` di-restore, `status` kembali `pending`, field pembatalan dikosongkan) dan menulis notifikasi yang sama. Pemeriksaan kunci periode kas berlaku dua arah: memulihkan payment membuat ulang CashEntry-nya, jadi pemulihan ditolak juga bila periodenya terkunci. Tagihan **tidak** ikut ditarik kembali — bila sudah dipakai order lain, menariknya kembali justru merusak data; cukup catat di log.

### 1.4 Kolom Aksi di daftar order

Kolom Aksi di [`orders/book/index.blade.php:73-126`](../../../resources/views/orders/book/index.blade.php#L73-L126) ditulis ulang. Cabang KONDISI 2 yang mati (0.2) hilang bersamanya.

| Kondisi order | Aksi |
|---|---|
| Belum ada payment | Payment · **Edit** · **Batal** |
| Bukti bayar ada, approval masih `pending` | Lihat · **Edit** · **Batal** |
| Payment sudah disetujui | Lihat · Edit · Refund *(persis seperti sekarang)* |
| Sudah dibatalkan | Lihat (read-only) · **Pulihkan** (manager/superadmin) |

Status order menampilkan badge `Dibatalkan` (abu-abu) sebagai kondisi ketiga di samping `Menunggu`/`Diproses`.

**Modal konfirmasi Batal** memakai pola modal Bootstrap yang sudah dipakai di alur Tolak Judul / Tolak Pembayaran: menampilkan kode order, judul, dan total biaya; satu `textarea` alasan **opsional**; tombol submit merah. Form-nya `@method('DELETE')`.

**Daftar order dibatalkan** — `index()` menerima query `?trashed=1` dan memakai `onlyTrashed()`; tautannya berupa toggle "Tampilkan order dibatalkan" di atas tabel. Filter kepemilikan marketing tetap berlaku.

### 1.5 Controller & route

`OrderBookController::destroy()` dan `restore()` diisi (keduanya kini stub kosong di [baris 490](../../../app/Http/Controllers/Pages/OrderBookController.php#L490)) dan mendelegasikan ke `OrderCancellationService`. `OrderJournalController::destroy()` dan `restore()` mengarahkan ke route buku, mengikuti pola `show()` yang sudah begitu ([`OrderJournalController.php:230`](../../../app/Http/Controllers/Pages/OrderJournalController.php#L230)).

Penjagaan di kedua controller: `abort_unless` untuk permission, plus cek kepemilikan bagi marketing (`$order->user_id === Auth::id()`), sejalan dengan filter yang sudah dipakai `index()`.

`edit()`/`update()` diberi penjagaan `abort_unless($order->isEditable(), 403)` — yakni menolak **order yang sudah dibatalkan**, dan hanya itu (lihat 1.2). Selain penjagaan ini, logika `update()` **tidak berubah**.

Route baru di grup `order.` ([`routes/web.php:63`](../../../routes/web.php#L63)):

```php
Route::delete('{code_order}',         [OrderBookController::class, 'destroy'])->name('cancel');
Route::post('{code_order}/restore',   [OrderBookController::class, 'restore'])->name('restore');
```

> Catatan penempatan: kedua route ini harus didaftarkan **setelah** route `buku/*` dan `jurnal/*` agar segmen `{code_order}` tidak menelan path statis.

### 1.6 Permission

`config/permissions.php`, modul `order` ([baris 24](../../../config/permissions.php#L24)):

```php
'cancel'  => ['order.cancel'],
'restore' => ['order.restore'],
```

`AccessMatrixSeeder`: `order.cancel` ditambahkan ke daftar `marketing`. `order.restore` **tidak** — manager dan superadmin sudah mendapatkannya lewat hibah `'*'`.

### 1.7 Halaman detail order yang dibatalkan

Karena `OrderDetail` ikut soft-deleted, `$order->details` (relasi `hasOne`) akan **null** untuk order yang dibatalkan. Setiap surface yang menampilkan order dibatalkan harus memakai `withTrashed()` pada relasi:

- `OrderBookController::show()` — eager load `details` dengan `withTrashed()` bila order-nya trashed;
- view [`orders/book/show.blade.php`](../../../resources/views/orders/book/show.blade.php) — tambah panel "Order ini dibatalkan" (alasan, oleh siapa, kapan) dan sembunyikan semua tombol aksi.

Audit yang wajib dilakukan saat implementasi: telusuri seluruh pemakaian `->details` di `app/` dan `resources/views/` dan pastikan tidak ada yang pecah ketika bernilai null. Ini risiko regresi terbesar dari Bagian 1.

### 1.8 Kunci periode kas

`CashPeriodLock` mengunci periode akuntansi yang sudah ditutup. Menghapus CashEntry di periode terkunci (efek langkah 2) melanggar kunci itu. `OrderCancellationService::cancel()` karena itu memeriksa lebih dulu: bila ada CashEntry milik payment order ini yang jatuh di periode terkunci, pembatalan **ditolak** dengan pesan jelas — "Periode kas <bulan> sudah dikunci. Minta superadmin membuka periode atau gunakan alur Refund." Aturan mainnya dibaca dari `CashPeriodService` yang sudah ada, bukan diduplikasi.

---

## 2. Bagian 2a — Field Judul di form order

Perbaikannya tiga lapis. Lapis 2 yang paling penting: selama `title_id` masih ambigu, memperbaiki label saja hanya menutup satu jalan masuk data kotor.

### 2.1 Lapis 1 — Label

Empat view (0.4) diganti satu partial bersama: **`resources/views/orders/partials/title-select.blade.php`**, menerima `$titles` dan nilai terpilih. Ini sekaligus menghapus duplikasi empat kali lipat markup + JS yang ada sekarang.

- `<option>` berisi **judul saja**.
- Kode, bidang ilmu, dan indeksasi pindah ke atribut `data-*` dan ditampilkan lewat `templateResult` Select2 sebagai baris kedua kecil abu-abu. Karena hanya menyentuh tampilan, string itu tidak pernah bisa ikut tersimpan.
- `templateSelection` menampilkan judul saja.
- Auto-isi jenis/scope/indeksasi dari `data-*` yang sudah berjalan hari ini dipertahankan apa adanya.

### 2.2 Lapis 2 — Nilai yang tak ambigu

Select2 dikonfigurasi ulang di partial:

```js
createTag: term => ({ id: 'new:' + term.trim(), text: term.trim(), newTag: true }),
insertTag: (data, tag) => data.push(tag)   // opsi "buat baru" di BAWAH, bukan di atas
```

`TitleService::resolveForOrder()` memutuskan berdasarkan bentuk nilai, bukan tebakan:

| Nilai masuk | Arti |
|---|---|
| angka | id judul yang sudah ada |
| berawalan `new:` | judul baru, namanya = sisa string setelah prefix |
| string polos | judul baru (jalur kompatibilitas: `old()`, form lama, prefill dari tagihan) |

Ini memperbaiki kasus judul berupa angka (`"2026"`) yang hari ini salah dibaca sebagai id. Pencarian judul kembar berdasarkan nama + jenis yang sudah ada di method itu tetap dipertahankan.

Validasi di `store()`/`update()` kedua controller order: setelah prefix dipangkas, nama judul tidak boleh kosong dan maksimal 255 karakter.

Query `$titles` di empat method (`OrderBookController::create/edit`, `OrderJournalController::create/edit`) ditambah `->whereNull('deactivated_at')` supaya judul nonaktif tidak muncul di dropdown (judul terhapus sudah tersaring otomatis oleh soft delete — lihat Bagian 4).

### 2.3 Lapis 3 — Membersihkan data yang terlanjur kotor

Artisan command **`php artisan titles:strip-code-prefix`**, bukan migrasi — supaya bisa dilihat dulu sebelum mengubah data produksi.

- **Aturan:** baris `tb_titles.title` atau `tb_order_details.title` yang cocok pola `^(?<code>[A-Za-z0-9\-\/]+)\s*[—-]\s*(?<rest>.+)$` **dan** `code`-nya benar-benar cocok dengan sebuah `tb_titles.code` yang ada → disetel menjadi `rest`.
- Tanpa flag = **dry run**: mencetak tabel `id · nilai sekarang · nilai sesudah` dan berhenti.
- `--apply` menjalankan perubahan dalam satu transaksi dan menulis `TitleLog` (`event: 'code_prefix_stripped'`) per judul yang berubah.
- Syarat kecocokan kode sengaja diperketat agar judul yang memang sah mengandung tanda hubung (mis. "Pendidikan Anak Usia Dini — Sebuah Tinjauan") tidak ikut terpangkas.

Isi database produksi **belum diperiksa** — koneksi `.env` menunjuk host remote dan tidak disentuh selama perancangan ini. Dry run adalah langkah verifikasi pertama sebelum apa pun diubah.

---

## 3. Bagian 2b — Edit judul di Direktori Judul

### 3.1 Gerbang

`Title::isEditable()` dilebarkan menjadi: `draft`, `ditolak`, **dan** `disetujui`. Status **tidak** turun kembali ke `menunggu` setelah diedit.

Karena `title.edit` juga dipegang `production` di matriks akses, penjagaan tambahan dipasang di `TitleController`: mengedit judul berstatus `disetujui` memerlukan role `superadmin|manager|admin` — himpunan yang sama persis dengan `canEditInfo` yang sudah dipakai di [`TitleController::show()`](../../../app/Http/Controllers/Pages/TitleController.php#L99). `production` tetap bisa mengedit judul `draft`/`ditolak` seperti sekarang.

### 3.2 Efek samping perubahan teks judul

`TitleService::update()` — bila nilai `title` berubah:

1. `tb_titles.slug` diregenerasi.
2. `tb_order_details.title` disinkron untuk semua detail bertaut (`withTrashed()`, agar order yang dibatalkan tidak menyimpan versi judul yang basi).
3. `TitleLog` ditulis dengan daftar field yang berubah — mengikuti pola ringkasan perubahan yang sudah dipakai `updateInfo()`.

`tb_titles.code` **tidak** ikut berubah otomatis: kode sudah tercetak di invoice dan dokumen arsip. Regenerasi kode tetap lewat halaman Info Publikasi yang sudah ada.

---

## 4. Bagian 2c — Hapus & nonaktifkan judul

### 4.1 Skema

Migrasi `2026_08_03_000003_add_lifecycle_fields_to_tb_titles`:

- `softDeletes()`;
- `deactivated_at` (timestamp, nullable), `deactivated_by` (FK users, nullable, nullOnDelete).

Istilah **"nonaktif"** dipakai, bukan "arsip", karena `TitleArchive` sudah menempati konsep berbeda (arsip karya yang sudah selesai) dan menumpuk dua makna di satu kata akan membingungkan.

### 4.2 Aturan

| Aksi | Syarat | Efek |
|---|---|---|
| **Hapus** | Tidak punya order aktif, tidak punya `BookIsbn`, tidak punya `TitleArchive` | Soft delete. Hilang dari direktori & dropdown |
| **Nonaktifkan** | Belum nonaktif | Hilang dari dropdown Tambah Order & daftar direktori default. Laporan, papan manuskrip, dan arsip tetap utuh |
| **Aktifkan lagi** | Sedang nonaktif; manager/superadmin | Muncul kembali di dropdown |

"Order aktif" dihitung dari `orderDetails()` **tanpa** `withTrashed()`. Ini disengaja: judul yang jadi yatim karena order-nya dibatalkan (Bagian 1) memang seharusnya bisa dibersihkan. Karena hapusnya *soft*, `tb_order_details.title_id` yang ber-`nullOnDelete` tidak ikut dikosongkan — riwayat tetap tertaut.

**Tidak ada tombol Pulihkan untuk judul pada iterasi ini.** Datanya tetap utuh di database (soft delete) dan bisa dikembalikan lewat `tinker` bila benar-benar perlu. Menambahkan UI pemulihan judul dinilai belum sepadan: syarat hapusnya sudah ketat, jadi yang bisa terhapus hanyalah judul yang belum terpakai apa pun.

Metode pendukung di model `Title`: `isDeletable(): bool`, `deleteBlockReason(): ?string`, `isActive(): bool`, dan scope `active()`.

### 4.3 UI direktori

Kolom Aksi di [`titles/index.blade.php:43-58`](../../../resources/views/titles/index.blade.php#L43-L58):

- Tombol **Edit** kini juga muncul untuk judul `disetujui` (bagi role yang berhak).
- Tombol **Hapus** selalu tampil, tapi **nonaktif dengan alasannya** bila syarat tidak terpenuhi — mis. "Dipakai 3 order", "Sudah punya ISBN". Alasan ditampilkan sebagai tooltip. Tombolnya sengaja tidak disembunyikan supaya user paham *kenapa* tidak bisa dihapus dan langsung melihat Nonaktifkan sebagai jalan keluarnya.
- Tombol **Nonaktifkan** / **Aktifkan** sesuai keadaan.
- Toggle filter "Tampilkan judul nonaktif" di atas tabel; secara default judul nonaktif disembunyikan.

Semua aksi menulis `TitleLog` (`deactivated`, `activated`, `deleted`).

### 4.4 Controller, route, permission

`TitleController`: `destroy()` diperbarui (aturan lama "hanya draft milik sendiri" diganti aturan 4.2), plus `deactivate()` dan `activate()`.

```php
Route::post('titles/{id}/deactivate', [TitleController::class, 'deactivate'])->name('title.deactivate')->whereNumber('id');
Route::post('titles/{id}/activate',   [TitleController::class, 'activate'])->name('title.activate')->whereNumber('id');
```

`config/permissions.php`, modul `title` ([baris 94](../../../config/permissions.php#L94)): `'deactivate' => ['title.deactivate', 'title.activate']`. Di `AccessMatrixSeeder`, `title.deactivate` ditambahkan ke `admin`; manager & superadmin mendapatkannya lewat `'*'`; `production` **tidak**.

---

## 5. Dampak lintas modul

| Modul | Dampak |
|---|---|
| Papan Manuskrip, Distribusi Artikel/Buku | Order dibatalkan hilang sendirinya lewat global scope soft delete `OrderDetail`/`TitleProgress`. Tanpa perubahan kode. |
| Dashboard Produksi, Performance | Sama — `TitleProgress::` yang di-query langsung ikut tersaring. |
| Jurnal Kas | CashEntry milik payment yang dibatalkan terhapus lewat `PaymentObserver` yang sudah ada. Ditolak bila periodenya terkunci (1.8). |
| Laporan Keuangan, Target Marketing | `Payment::scopeIncome()` menyaring `status='paid'`; payment ber-status `batal` otomatis keluar dari perhitungan. |
| Tagihan | Tagihan yang tertaut kembali ke `disetujui` dan bisa dipakai membuat order pengganti. |
| Invoice | Invoice order yang dibatalkan berstatus `batal`, tetap tampil di daftar dengan badge-nya. |
| Arsip Judul | Tidak berubah. Judul yang punya `TitleArchive` diblokir dari penghapusan. |

---

## 6. Rencana pengujian

Test baru di `tests/Feature`:

**`OrderCancelTest`**
- Batal saat belum ada payment → order soft-deleted, `status='dibatalkan'`, `OrderDetail` & `TitleProgress` ikut soft-deleted.
- Batal saat payment sudah disubmit tapi approval masih `pending` → payment jadi `batal`, invoice jadi `batal`, CashEntry-nya hilang.
- Batal **ditolak** (403) saat sudah ada payment ber-approval `approved`.
- Tagihan tertaut kembali ke `disetujui` dengan `order_id` kosong.
- Order yang dibatalkan tidak muncul di papan manuskrip maupun daftar order default.
- Marketing tidak bisa membatalkan order milik marketing lain.
- Alasan kosong diterima (opsional); alasan yang diisi tersimpan.
- Batal ditolak bila periode kas terkunci.

**`OrderRestoreTest`** — pulihkan mengembalikan order, detail, progress, payment, dan invoice; hanya manager/superadmin.

**`OrderEditGateTest`** — route edit dapat diakses sejak order dibuat; **tetap** dapat diakses setelah payment disetujui; ditolak (403) hanya untuk order yang sudah dibatalkan. `isEditable()` dipakai untuk *membuka* Edit lebih awal, bukan untuk menutupnya belakangan.

**`TitleSelectResolveTest`** — `new:` menghasilkan judul baru; angka menghasilkan tautan ke judul yang ada; judul bernama `"2026"` jadi judul baru, bukan id; string polos tetap diterima.

**`TitleEditApprovedTest`** — admin bisa mengedit judul `disetujui`; production ditolak; teks judul tersinkron ke `tb_order_details`; `TitleLog` tertulis.

**`TitleLifecycleTest`** — hapus diblokir bila ada order aktif / ISBN / arsip, dengan alasan yang benar; nonaktif menyembunyikan judul dari dropdown order tapi tidak dari laporan; aktifkan mengembalikannya; judul yatim akibat order dibatalkan bisa dihapus.

**`StripCodePrefixCommandTest`** — dry run tidak mengubah apa pun; `--apply` hanya memangkas baris yang kodenya benar-benar cocok; judul sah bertanda hubung tidak tersentuh.

Regresi yang wajib tetap hijau: **seluruh 100 test yang ada**, khususnya `PaidNetTest` — soft delete tidak boleh menggeser satu angka pun pada order yang aktif.

---

## 7. Di luar lingkup

- **Memperbaiki semantik `payment.status='paid'` saat submit** (temuan 0.1). Ini bug tersendiri yang menyentuh Laporan Keuangan, Target Marketing, `paidNet()`, dan `PaidNetTest`. Spec ini **beradaptasi** dengan perilaku itu (memakai `approval.status`), tidak mengubahnya. Layak jadi spec terpisah.
- Alur Refund — tidak berubah sama sekali.
- Peringatan judul mirip / fuzzy match saat mengetik judul baru — sempat dipertimbangkan, ditolak agar lingkup tetap terkendali.
- Hapus permanen (hard delete) order — semua penghapusan bersifat soft.

---

## 8. Urutan implementasi yang disarankan

1. **Fondasi:** tiga migrasi + trait `SoftDeletes` + metode gerbang di model, **plus audit menyeluruh pemakaian `->details`** terhadap kemungkinan null (1.7). Jalankan test suite; harus tetap hijau sebelum lanjut.
2. **`OrderCancellationService`** + route + permission + `destroy`/`restore` di controller. Test dulu, UI belakangan.
3. **UI daftar order:** kolom Aksi ditulis ulang, modal Batal, toggle order dibatalkan, panel pembatalan di halaman detail.
4. **Partial `title-select`** + `resolveForOrder()` berprefix + penyesuaian validasi di empat method controller.
5. **Command `titles:strip-code-prefix`** — dry run dulu, laporkan temuannya, baru minta izin untuk `--apply`.
6. **Edit judul `disetujui`** + sinkronisasi ke order + `TitleLog`.
7. **Nonaktif & hapus judul** + UI direktori.

Langkah 1–3 (**Order**) dan 4–7 (**Judul**) tidak saling bergantung setelah langkah 1 selesai, dan sebaiknya dipecah menjadi **dua rencana implementasi terpisah** — masing-masing punya titik selesai yang bisa diverifikasi sendiri, dan keduanya bisa dikerjakan paralel.
