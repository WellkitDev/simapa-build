# Sinkronisasi Status Order ↔ Naskah

**Tanggal:** 2026-08-20
**Status:** Disetujui, siap direncanakan
**Ruang lingkup:** Kelompok Tulang Punggung (A2, A6, A7) dari audit status

---

## 1. Latar

SiMAPA punya **13 mesin status yang hidup terpisah**, dari tagihan sampai arsip judul.
Audit 2026-08-20 menemukan bahwa sebagian besar tidak saling bicara, dan tiga di
antaranya membentuk lubang di tulang punggung: order tidak pernah tahu naskahnya
sudah terbit.

### 1.1 Inventaris status

| # | Tabel | Nilai | Ditulis oleh |
|---|---|---|---|
| 1 | `tb_tagihan` | `diajukan → disetujui/ditolak → jadi_order`, `dibatalkan` | TagihanController, OrderCancellationService |
| 2 | `tb_titles` | `draft → menunggu → disetujui/ditolak` (+ `deactivated_at`) | TitleService |
| 3 | `tb_orders` | `pending`, `lunas`, `dibatalkan` | PaymentBookController, InvoiceController |
| 4 | `tb_invoices` | `draft, diterbitkan, jatuh_tempo, lunas, dibatalkan, refund` | PaymentBookController, InvoiceController |
| 5 | `tb_payments` | `paid, rejected, batal` | PaymentBookController, RefundController |
| 6 | `tb_payments.payment_type` | `dp, lunas, pelunasan, refund` | idem |
| 7 | `tb_payment_approvals` | `pending → approved/rejected` | PaymentBookController |
| 8 | `tb_title_progress` (artikel) | `menunggu_proses → pembuatan → editing → revisi → submit → loa → publish` | TitleProgressService |
| 9 | `tb_title_progress` (buku) | `menunggu_proses → pembuatan → editing → layout → proofreading → isbn → cetak → terbit` | TitleProgressService |
| 10 | `tb_chapter_progress` | `menunggu → pembuatan → editing → selesai` | TitleProgressService |
| 11 | `tb_manuscript_files` | `antre, selesai, gagal` | ManuscriptFileService (queue) |
| 12 | `tb_book_isbns` | `pendaftaran → ber_isbn → cetak` | BookIsbnController |
| 13 | `tb_journal_submissions` | `submitted → loa → published` | JournalSubmissionController |
| 14 | `tb_title_archives` | `draft → diajukan → disetujui/ditolak` | TitleArchivalService |

### 1.2 Sambungan yang sudah jalan

- Tagihan `disetujui` → Order → `jadi_order`; order dibatalkan → tagihan balik `disetujui`
- OrderDetail lahir → `TitleProgressService::createForDetail()` membuat TitleProgress,
  mewarisi tahap dari order sejudul (grup)
- Payment `lunas`/`pelunasan` disetujui → Order `lunas` + Invoice `lunas`
- Order dibatalkan → payment `batal`, invoice `dibatalkan`, OrderDetail + TitleProgress
  soft-deleted, tagihan pulih
- Upload berkas slot `masuk` selesai → tahap naik otomatis ke `editing`
- Semua bab `selesai` → gerbang `layout` buku terbuka
- BookIsbn `ber_isbn` → progress `cetak`; `cetak` → progress `terbit` (maju-saja)
- Progress final (`terbit`/`publish`) → `archived_at` otomatis
- Arsip boleh diajukan hanya bila semua order lunas + naskah final

### 1.3 Temuan audit

| | Temuan | Kelompok |
|---|---|---|
| A1 | Jurnal tak punya sinkronisasi apa pun; `submitted/loa/published` dan tahap `submit/loa/publish` adalah kembar yang tak saling bicara | Terbit |
| **A2** | **Order tak pernah "selesai". `completed_at` tidak pernah ditulis di mana pun** — order tak punya cara menyatakan naskahnya sudah terbit (lihat koreksi §1.4) | **Tulang Punggung** |
| A3 | Order jadi `lunas` **sebelum** pembayaran disetujui (`PaymentBookController::store()`) | Uang |
| A4 | `lunas` tak dihitung ulang saat `cost_amount` order diedit | Uang |
| A5 | Invoice `jatuh_tempo` manual; `isOverdue()` ada tapi tak ada scheduled command | Uang |
| **A6** | **Pembayaran tak menggerbangi produksi** | **Tulang Punggung** |
| **A7** | **Refund tak menyentuh naskah sama sekali** | **Tulang Punggung** |
| A8 | Title tak punya status terbit; judul terbit tetap `disetujui` | Terbit |
| A9 | Tak ada status "ditolak" di jalur jurnal; `revisi` terletak **sebelum** `submit`, kebalikan realita | Terbit |
| A10 | Nilai status tersebar sebagai literal; `Order` dan `Payment` tak punya `const STATUSES` | Uang |
| **A11** | **`TitleArchive` berstatus `draft` tidak pernah dibuat kode mana pun** → ubin dashboard "Arsip Menunggu Artefak" selalu 0 | **Tulang Punggung** |
| **A12** | **`/management/archive` hanya menampilkan judul yang sudah punya baris arsip.** `archive.show` tak ditaut dari halaman lain → praktis tak ada pintu masuk untuk mengajukan arsip | **Tulang Punggung** |

### 1.4 Koreksi: siapa sebenarnya pembaca `completed_at`

Versi pertama dokumen ini menyatakan `tb_orders.completed_at` dibaca `DailyReportService`
sehingga Laporan Harian selamanya kosong. **Itu salah.** `DailyReportService` membaca
`Task::completed_at` (tugas harian pegawai), bukan kolom milik order.

Pembaca sebenarnya satu-satunya adalah `FinancialReportService::orderSelesai()`, yang
menyurfacekannya sebagai kolom **"Tanggal Lunas"** di Laporan Keuangan, PDF, dan ekspor CSV:

```php
$o->tanggal_lunas = $o->completed_at ?? $o->updated_at;
```

Karena `completed_at` selama ini SELALU null, cabang `?? $o->updated_at` selalu menang dan
tak seorang pun pernah melihat cabang satunya.

**Dua akibat yang mengubah pekerjaan:**

1. Begitu `completed_at` mulai terisi, ia bocor ke laporan **uang** sebagai tanggal lunas —
   padahal isinya tanggal **pekerjaan**. Itu persis percampuran yang K3 dibuat untuk
   mencegah. Karena itu `FinancialReportService` dilepas dari kolom ini, kembali ke
   `updated_at` yang memang selama ini benar-benar tampil.

   **Koreksi atas kalimat di atas — pelepasannya tidak tuntas.** Yang dicabut hanya
   ketergantungan **eksplisit**. Sisa ketergantungan tetap ada lewat `updated_at`:
   `OrderFulfillmentService::apply()` memanggil `$order->save()` tepat pada saat naskah
   mencapai `publish`/`terbit`, dan `Order` memakai timestamps — jadi `updated_at`
   tercap momen terbit. Kolom "Tanggal Lunas" karenanya masih menampilkan tanggal
   terbit untuk order yang baru terbit, dan `orderByDesc('updated_at')` menaikkannya ke
   puncak laporan. Sebelum pekerjaan ini tak ada yang menyentuh order saat terbit,
   sehingga kolom itu tak pernah bergerak.

   Ini **diketahui dan diterima**, bukan terlewat. `updated_at` memang selalu proksi
   lemah, dan perbaikan sebenarnya — tanggal lunas diturunkan dari `paid_at` payment
   yang disetujui — masuk **Kelompok Uang**, bukan pekerjaan ini. Lihat §9.

2. `Order` masih memakai `protected $dates` yang mati sejak Laravel 10, jadi `completed_at`
   kembali sebagai string, bukan Carbon. Kolomnya harus pindah ke `$casts` atau justru
   kolom "Tanggal Lunas" jadi kosong.

Lubang A2 sendiri tetap nyata dan pekerjaannya tidak berubah: kolomnya memang tak pernah
ditulis, dan order memang tak punya keadaan "selesai". Hanya alasan yang dikutip yang keliru.

---

## 2. Keputusan yang dikunci

| # | Keputusan | Alasan |
|---|---|---|
| K1 | **Pembayaran TIDAK memblokir laju naskah** | Tim harus bisa bekerja tanpa gesekan; uang diurus di jalurnya sendiri |
| K2 | Peringatan pembayaran muncul di **Arsip Judul** (daftar + detail), bukan di papan naskah | Arsip adalah titik penutup resmi; di situlah lunas benar-benar berarti |
| K3 | Order menyimpan keadaan pekerjaan di **kolom terpisah** `fulfillment_status`, bukan menambah nilai ke `status` | Query lama `status='lunas'` tetap benar; tak perlu audit seluruh laporan keuangan |
| K4 | Order jadi `selesai` **saat naskah mencapai Terbit/Publish**, bukan saat lunas | Sejalan K1; `completed_at` jatuh di bulan naskah terbit, bukan bulan pelunasan menyusul |
| K5 | Order yang di-refund **ditandai "ditarik" dan dikeluarkan dari grup judul**, bukan ditahan | Hold bekerja se-grup → membekukan seluruh buku untuk penulis lain |
| K6 | Bab hanya dicabut bila tahap buku **masih di bawah `isbn`** | Sejak ISBN terdaftar, susunan bab sudah resmi dan buku mungkin sudah dicetak |
| K7 | **"Batalkan Penarikan"** dibangun (superadmin) | Penarikan kini merusak (mencabut penulis, mengosongkan bab) dan refund tak punya `restore` |

### 2.1 Ruang lingkup

**Dikerjakan:** A2, A6 (sebagai peringatan, bukan blokir), A7, A11, A12, dan
perbaikan R3 pada jalur pembatalan order.

**Tidak dikerjakan sekarang:** A1, A3, A4, A5, A8, A9, A10 (kecuali konstanta
`Order::STATUSES` / `Order::FULFILLMENTS` yang ikut lahir).

Catatan soal A3: karena `fulfillment_status` terpisah dari `status`, bug A3
**tidak menular** ke pembacaan "order ini masih hidup". Menundanya aman.

---

## 3. Kenapa "tahan naskah" ditolak

Rancangan pertama menahan (`is_on_hold`) naskah order yang di-refund. Rancangan itu
**dibatalkan** setelah penelusuran menemukan tiga fakta struktural:

1. `AssignmentService::hold()` dan `cancel()` bekerja lewat `onGroup()` — satu panggilan
   menyentuh **seluruh order sejudul**. Refund satu penulis membekukan buku untuk 19
   penulis lain.
2. `TitleProgress` **bukan status per-penulis**. Ia satu baris per OrderDetail, tapi
   `applyGroup()` menjaganya lockstep. `applyGroup()` **tidak melihat `is_on_hold`** —
   baris yang ditahan tetap ikut maju saat penulis lain menekan "Lanjut Tahap".
3. `manuscriptStatus()` adalah **bottleneck** dan `isPaidOff()` menuntut **semua** order
   lunas. Satu baris yang tertahan atau ter-refund melumpuhkan seluruh judul.

### 3.1 Risiko yang ditemukan (1 judul = banyak order)

| | Risiko | Ditangani oleh |
|---|---|---|
| R1 | `hold`/`cancel` bekerja se-grup | §4.3 (`onGroup` melewati yang ditarik) |
| R2 | `applyGroup` mengabaikan `is_on_hold` | §4.3 (`applyGroup` melewati yang ditarik) |
| R3 | `tb_title_chapter_authors` tak pernah dibersihkan — **sudah jadi bug untuk order dibatalkan hari ini** | §5.3 |
| R4 | `remapFromOrders()` sengaja melewati bab yang ordernya hilang | §5.3 (pencabutan eksplisit, bukan remap) |
| R5 | `manuscriptStatus()` = bottleneck semua order | §4.3 |
| R6 | `isPaidOff()` menuntut semua order lunas → 1 refund mematikan arsip 20 bab | §4.3 |
| R7 | `alreadyRefunded()` mengunci 1 refund per order, tak ada `restore` | §5.4 (K7) |
| R8 | Bab yang penulisnya mundur | §5.2 (dikosongkan, siap dijual ulang) |
| R9 | Refund datang setelah ISBN/cetak | §5.2 (batas `isbn`, K6) |

---

## 4. Model data

### 4.1 `tb_orders`

```
status              pending | lunas | dibatalkan              <- UANG, tak disentuh
fulfillment_status  berjalan | selesai | ditarik | dibatalkan <- BARU, default 'berjalan', ber-index
completed_at        terisi saat fulfillment_status = 'selesai'
```

`ditarik` = order di-refund penuh. Menyatukan A2 dan A7 dalam satu kolom karena
keduanya menjawab pertanyaan yang sama: *"order ini masih hidup sebagai pekerjaan?"*

Konstanta baru di `App\Models\Order`:

```php
public const STATUSES     = ['pending', 'lunas', 'dibatalkan'];
public const FULFILLMENTS = ['berjalan', 'selesai', 'ditarik', 'dibatalkan'];
```

### 4.2 `tb_title_progress`

```
archived_at       (sudah ada)
cancelled_at      (sudah ada)
withdrawn_at      <- BARU, ber-index
withdrawn_reason  <- BARU, teks
```

Denormalisasi **disengaja**, mengikuti pola `archived_at`/`cancelled_at` yang sudah ada
di tabel yang sama. Alternatifnya JOIN ke `tb_orders` di setiap `groupOf()`,
`manuscriptStatus()`, dan `applyGroup()` — tiga jalur terpanas modul naskah.

### 4.3 Enam titik yang harus melewati baris "ditarik"

Inti perbaikan R1–R6. Tanpa keenamnya, satu refund tetap melumpuhkan judul.

| Titik | Sekarang | Menjadi |
|---|---|---|
| `TitleProgress::scopeActive()` | `archived_at`, `cancelled_at` | `+ whereNull('withdrawn_at')` |
| `TitleProgressService::groupOf()` | semua se-`group_key` | `+ whereNull('withdrawn_at')` |
| `TitleProgressService::applyGroup()` | majukan semua anggota grup | lewati yang ditarik |
| `AssignmentService::onGroup()` | hold/cancel/assign/withdraw se-grup | lewati yang ditarik |
| `Title::manuscriptStatus()` | bottleneck semua orderDetail | **abaikan yang ditarik** → R5 |
| `Title::isPaidOff()` | semua order harus lunas | **abaikan yang ditarik** → R6 |

---

## 5. Aturan

### 5.1 Order jadi "selesai"

Satu-satunya penulis `fulfillment_status` adalah **`OrderFulfillmentService`** (baru),
dikaitkan ke `TitleProgressService::applyStatus()` — satu-satunya tempat
`TitleProgress.status` ditulis, sehingga tak ada jalur yang bisa lolos.

```
tahap -> 'terbit' / 'publish'   =>  selesai    + completed_at = now()
koreksi mundur superadmin       =>  berjalan   + completed_at = null
order dibatalkan                =>  dibatalkan
order di-refund penuh           =>  ditarik
```

### 5.2 Refund menarik satu order

```
RefundController::store()
  |
  +- OrderWithdrawalService::withdraw($order, $refundPayment)
       |
       +- order.fulfillment_status = 'ditarik'
       +- titleProgress.withdrawn_at / withdrawn_reason
       +- bila buku kolaborasi DAN tahap buku < 'isbn':
            +- cabut penulis order itu dari tb_title_chapter_authors
            +- ChapterProgress bab itu -> 'menunggu', pelaksana dikosongkan
            +- bab siap dijual ulang: orderForChapter() otomatis menunjuk
               order baru, seedFromOrders() mengisi penulisnya
```

- **Refund sebagian** (`amount < paidIn`) hanya memberi lencana — tidak menarik apa pun.
  Bisa jadi potongan harga atau kompensasi, bukan pembatalan.
- **Batas `isbn`** (K6): sejak tahap `isbn` ke atas, refund tetap dicatat dan diberi
  lencana, tapi bab dan penulis **tidak diubah**. Uang kembali, karya sudah terlanjur
  resmi.

Tiga bentuk order diperlakukan berbeda:

| Bentuk | Yang terjadi saat refund penuh |
|---|---|
| **Buku kolaborasi** (`bk_kolab`) | Order ditandai `ditarik`; bab & penulisnya dicabut bila tahap < `isbn`. Judul jalan terus untuk order lain. |
| **Buku mandiri** (`bk_mandiri`) | Order ditandai `ditarik`. Satu order = seluruh buku, jadi tak ada bab yang dicabut — babnya milik order itu sendiri. Judul kehilangan seluruh order aktif (lihat catatan di bawah). |
| **Artikel** (`at_mandiri`, `at_kolab`) | Order ditandai `ditarik`. Artikel tak punya `TitleChapter` sama sekali, jadi tak ada pencabutan bab/penulis — hanya penandaan. |

**Judul tanpa order aktif.** Bila seluruh order sebuah judul ditarik (lazimnya buku
mandiri atau artikel dengan satu order), `manuscriptStatus()` mengembalikan `null` —
perilaku yang sama dengan judul yang belum pernah dipesan. Akibatnya `manuscriptIsFinal()`
false dan judul **tidak** muncul di "Siap Diarsipkan". Ini disengaja: judul yang seluruh
pemesannya menarik diri memang bukan judul yang layak diarsipkan. Judulnya sendiri tidak
dinonaktifkan — `deactivated_at` tetap keputusan manusia.

### 5.3 R3 ikut diperbaiki: order dibatalkan juga meninggalkan penulis basi

`OrderCancellationService::cancel()` men-soft-delete OrderDetail dan TitleProgress, tapi
**tidak pernah menyentuh `tb_title_chapter_authors`**. Penulis dari order yang dibatalkan
tetap tercantum di babnya selamanya. `remapFromOrders()` tidak menolongnya: ia sengaja
melewati bab yang ordernya sudah hilang.

Karena `cancel()` sudah menyentuh baris yang sama, memanggil pencabutan yang sama di sana
hampir gratis. `restore()` memasangnya kembali dari snapshot (§5.4).

### 5.4 Batalkan Penarikan (K7)

Penarikan menyimpan snapshot ke `TitleProgressLog`: ID penulis yang dicabut beserta
`position`-nya, dan status `ChapterProgress` sebelum direset.

Route baru `order.refund.undo` (superadmin) memasangnya kembali:
`fulfillment_status` balik ke `berjalan`/`selesai` sesuai tahap naskah saat itu,
`withdrawn_at` dikosongkan, penulis dan bab dipulihkan dari snapshot.

Undo **ditolak** bila bab sudah dipesan order lain sejak penarikan — pemulihan akan
menabrak pemilik baru. Pesannya menyebut order penabraknya.

---

## 6. Tampilan

### 6.1 `/management/archive` — bagian "Siap Diarsipkan"

```
Arsip Judul

+-- Siap Diarsipkan (7) ------------------------------------ BARU --+
| Kode     Judul           Naskah   Pembayaran            Aksi      |
| BK-0012  Metode Pen...   Terbit   * Lunas               Siapkan   |
| BK-0031  Pengantar S...  Terbit   * Kurang Rp2.500.000  Siapkan   |
| BK-0044  Statistika...   Terbit   * Belum ada bayaran   Siapkan   |
| AT-0090  Analisis Ko...  Publish  * Lunas · 1 ditarik   Siapkan   |
+-------------------------------------------------------------------+

+-- Menunggu Persetujuan (2) --+     sudah ada
+-- Judul Selesai / Arsip (18) --+   sudah ada
```

Isi: judul yang naskahnya **final** tapi belum punya arsip `diajukan`/`disetujui`
(termasuk yang `ditolak`, supaya bisa diajukan ulang).

Ini satu-satunya pintu masuk ke `archive.show` untuk judul baru — sekarang pintu itu
tidak ada sama sekali (A12).

Kolom **Pembayaran** menyebut **angka kekurangannya**, bukan sekadar "Belum Lunas".
Order yang ditarik tidak dihitung ke kekurangan, tapi disebut jumlahnya.

Tabel memakai DataTables `datatables.net-bs4` seperti daftar lain, bukan tabel polos
seperti dua bagian lama.

### 6.2 `archive.show` — kartu "Kelayakan Arsip" diperjelas

```
Kelayakan Arsip
 * Pembayaran Belum Lunas — kurang Rp 2.500.000 dari 3 order
 * Manuskrip Final
 [Ajukan ke Arsip]  (nonaktif)
 Bisa diajukan setelah pembayaran lunas dan manuskrip final.
 +--------------------------------------------------+
 | ORD-2608-007  Prof. Budi   kurang Rp 2.500.000   |
 +--------------------------------------------------+
```

Tabel **Info Order** yang sudah ada dapat kolom kekurangan. Baris order ditarik ditandai
`Ditarik · Refund 20 Agu 2026` dengan teks diredupkan.

### 6.3 Daftar order — dua lencana

```
ORD-2608-001   [Lunas]    [Selesai]
ORD-2608-002   [Pending]  [Berjalan]
ORD-2608-007   [Lunas]    [Ditarik]
```

### 6.4 Meja Kerja · Pelacakan · Arsip Naskah

Baris yang ditarik hilang dari papan aktif (`scopeActive` menyaringnya) dan muncul di
**Arsip Naskah** dengan lencana `Ditarik — Refund`, bersebelahan dengan tab Batal.

### 6.5 Ubin dashboard yang mati dihidupkan (A11)

`AdminDashboardService` menghitung `TitleArchive` berstatus `draft` — yang tak pernah
dibuat siapa pun, jadi selalu 0. Diarahkan ke hitungan **Siap Diarsipkan** dari §6.1,
sehingga ubin dan halamannya menunjuk hal yang sama.

---

## 7. Migrasi, backfill, izin

### 7.1 Berkas

```
2026_08_20_000002_add_fulfillment_to_tb_orders.php
2026_08_20_000003_add_withdrawn_to_tb_title_progress.php
2026_08_20_000004_backfill_order_fulfillment.php
```

### 7.2 Backfill wajib memakai `DB::table()`

`Order`, `OrderDetail`, dan `TitleProgress` ketiganya memakai `SoftDeletes`. Migrasi yang
meng-query modelnya akan pecah saat `migrate:fresh` dan membuat seluruh suite merah
dengan gejala yang menyesatkan. Ini sudah terjadi tiga kali di repo ini.

Isi backfill:

| Kondisi | `fulfillment_status` | `completed_at` |
|---|---|---|
| `tb_orders.status = 'dibatalkan'` | `dibatalkan` | — |
| punya payment `payment_type = 'refund'` | `ditarik` | — |
| `tb_title_progress.status` ∈ (`terbit`, `publish`) | `selesai` | dari `tb_title_progress.archived_at` |
| sisanya | `berjalan` | — |

Urutan penilaian dari atas ke bawah: `dibatalkan` menang atas semuanya, lalu `ditarik`,
lalu `selesai`, sisanya `berjalan`.

### 7.3 Setelah migrasi

`php artisan migrate` **juga harus dijalankan di DB dev `avidpedi_simapa`** — test hijau
memakai DB test, dan tanpa langkah ini aplikasi live 500 di kolom yang belum ada.

### 7.4 Peta izin

Route baru `order.refund.undo` **wajib** didaftarkan di `config/permissions.php` di bawah
`order → refund`. `EnforcePermission` fail-closed: route yang tak terpetakan langsung 403
dan testnya merah.

---

## 8. Uji

| Berkas | Yang dikunci |
|---|---|
| `OrderFulfillmentTest` | terbit → `selesai` + `completed_at`; koreksi mundur → `berjalan` + `completed_at` null |
| `OrderWithdrawalTest` | refund penuh menarik; refund sebagian **tidak**; batas `isbn` dihormati |
| `WithdrawnExclusionTest` | keenam titik §4.3 — terutama: 20 order, 1 ditarik, judul **tetap** bisa diarsipkan (R5+R6) |
| `WithdrawalUndoTest` | penulis dan bab kembali persis seperti semula; undo ditolak bila bab sudah dipesan order lain |
| `ChapterAuthorCleanupTest` | order dibatalkan mencabut penulis; `restore()` memasangnya kembali (R3) |
| `ArchiveIndexTest` | bagian Siap Diarsipkan memuat judul final tanpa arsip, dan menyebut kekurangan bayar |

Catatan uji yang berlaku di repo ini:

- Test berjalan atas `avidpedi_simapa_test` lewat `.env.testing`, tak pernah DB asli.
- Role `accounting` di-seed lewat migrasi → pakai `Role::firstOrCreate`.
- Tidak ada factory `Payment` — bangun manual.
- Mock GoogleDrive **wajib** lewat container (`$this->mock`), bukan konstruktor.

---

## 9. Yang sengaja ditinggalkan

Supaya jelas dan tidak dikira terlewat:

- **A3** — order jadi `lunas` sebelum approval. Bug aktif hari ini, tapi milik Kelompok Uang.
- **A4** — `lunas` tak dihitung ulang saat biaya order diedit.
- **A5** — invoice `jatuh_tempo` masih manual; tak ada scheduled command.
- **A1** — jurnal masih tak tersambung ke tahap naskah.
- **A8** — judul masih tak punya penanda terbit.
- **A9** — `revisi` masih terletak sebelum `submit`.
- **Sisa kebocoran tanggal terbit → "Tanggal Lunas"** (Kelompok Uang). `FinancialReportService::orderSelesai()`
  tak lagi membaca `completed_at`, tapi memakai `updated_at` — dan sinkronisasi
  fulfillment menyimpan order tepat saat naskah terbit, sehingga tanggal terbit tetap
  sampai ke kolom uang lewat pintu belakang, plus mengubah urutan laporan. Tes
  `tanggal_lunas_tidak_lagi_membaca_completed_at` sengaja dinamai sempit karena hanya
  memagari pembacaan eksplisitnya. Tuntas hanya bila tanggal lunas diturunkan dari
  `paid_at` payment yang disetujui.

Rencana berikutnya: **Kelompok Uang** (A3, A4, A5, A10), lalu **Kelompok Terbit**
(A1, A8, A9).
