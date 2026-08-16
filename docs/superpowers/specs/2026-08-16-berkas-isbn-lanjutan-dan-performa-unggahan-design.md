# Berkas ISBN Lanjutan & Performa Unggahan — Desain

Tanggal: 2026-08-16
Status: disetujui owner, siap masuk rencana implementasi
Lanjutan dari: `2026-08-16-isbn-berkas-dan-bab-mandiri-design.md`

## 1. Latar

Owner meminta tiga hal: tambahkan unggahan **sertifikat HKI** (opsional) dan **barcode ISBN**
ke blok Berkas & Publikasi, periksa apakah ada bug, dan tangani unggahan supaya tidak
memperlambat web.

Permintaan ketiga membuka temuan yang lebih besar dari permintaannya sendiri. Yang
memperlambat web ternyata **bukan** unggahan — melainkan pemuatan halaman biasa.

## 2. Temuan pemeriksaan

Semua angka di bawah diukur, bukan diperkirakan.

### T1 — Setiap halaman membayar panggilan OAuth ke Google (kritis)

`GoogleDriveService::__construct()` memanggil `fetchAccessTokenWithRefreshToken()`, sebuah
round-trip HTTPS ke server OAuth Google. Service itu disuntik ke **constructor** enam
controller, dan Laravel membangun dependensi controller pada setiap request — terlepas dari
method mana yang dipanggil dan apakah halaman itu menyentuh berkas.

| Controller | Biaya konstruksi | Halaman |
|---|---|---|
| `OrderBookController` | **263 ms** | Daftar Order |
| `PaymentBookController` | **210 ms** | Pembayaran |
| `ProfileController` | **223 ms** | Profil |
| `ManagementUserController` | **233 ms** | Manajemen User |
| `JournalSubmissionController` | **216 ms** | Submission Jurnal |
| `DailyReportController` | **221 ms** | Laporan Harian |
| `TitleController` *(pembanding)* | 2 ms | Direktori Judul |
| `BookIsbnController` *(pembanding)* | 0 ms | Direktori ISBN |

Service ini juga **bukan singleton**: tiga instansiasi berturut-turut memakan 280/215/222 ms,
jadi tiap pemakaian di satu request membayar ulang.

### T2 — Seluruh email antrian tidak pernah terkirim (kritis)

`SendInvoiceJob`, `SendRefundJob`, `SendSalarySlipJob`, dan `SendServiceInvoiceJob` keempatnya
`implements ShouldQueue`. `.env` produksi memakai `QUEUE_CONNECTION=database`. Dan **tidak ada
`queue:work` di mana pun** — tidak di `deploy.sh`, tidak di `app/Console/Kernel.php`, tidak di
dokumen deploy. Job ditulis ke tabel `jobs` lalu diam selamanya.

`.env.example` mendokumentasikan `QUEUE_CONNECTION=sync` (yang akan menjalankan job langsung).
Nilai `database` datang belakangan tanpa worker pendampingnya.

### T3 — `.env` dev punya dua baris `QUEUE_CONNECTION`

Baris 21 `sync`, baris 61 `database`. Yang belakangan menang, jadi menyunting baris 21 tidak
berefek apa pun — jebakan diam bagi siapa pun yang mencoba mengubahnya.

### T4 — Unggahan memuat seluruh berkas ke memori

`GoogleDriveService::uploadFile()` memakai `file_get_contents()` lalu `uploadType: 'multipart'`
sekali jalan. Berkas 20 MB berarti 20 MB di memori PHP, dan seluruh transfer ke Google terjadi
di dalam request HTTP.

### T5 — Penyimpanan ISBN belum transaksional

Di `store()`, `BookIsbn::create()` dijalankan lebih dulu, baru `simpanBerkas()`. Bila unggahan
ke Drive gagal, `ManuscriptFileService::upload()` melempar — meninggalkan registrasi berstatus
Cetak/Terbit **tanpa berkas**, persis keadaan yang baru saja dilarang aturan kelengkapan.

### T6 — Formulir ISBN terbuka karena galat milik form lain

`class="collapse {{ $errors->any() ? 'show' : '' }}"` bereaksi pada galat APA PUN di halaman
Detail Judul, termasuk milik form Cek Kelengkapan Dokumen.

## 3. Keputusan yang mengikat

1. **Daftar berkas ISBN jadi satu sumber tunggal.** Nama slot kini tersebar di empat tempat
   (`SLOTS`, `SLOTS_ISBN`, `BERKAS_RULES`, peta `$nama` di `assertBerkasLengkap`). Dengan slot
   ketiga dan keempat itu jadi rapuh. Satukan jadi satu konstanta berisi label, aturan
   validasi, dan wajib-atau-tidak; formulir, direktori, dan validasi membacanya dari situ.
2. **Barcode ISBN wajib saat Cetak/Terbit; sertifikat HKI opsional selamanya.**
3. **`GoogleDriveService` jadi malas + singleton.** Otentikasi pindah dari constructor ke
   pemakaian pertama. Tidak ada controller yang perlu diubah.
4. **Antrian digerakkan scheduler, bukan worker permanen.** Produksi berjalan di cPanel yang
   tidak mengizinkan proses panjang. `queue:work --stop-when-empty` dipanggil tiap menit oleh
   cron `schedule:run` yang memang sudah harus ada.
5. **Unggahan tetap SINKRON.** Tidak dipindah ke job. Yang diperbaiki: urutannya (berkas naik
   sebelum record ditulis) dan kejujurannya di layar (tombol terkunci + keterangan sedang
   mengunggah). Memindahkannya ke antrian berarti tautan berkas kosong sampai cron jalan dan
   berkas bisa menumpuk diam-diam bila cron mati — harga yang tidak sepadan sekarang.
6. **Direktori ISBN memakai empat kolom berkas terpisah** (pilihan owner), diterima dengan
   konsekuensi tabelnya perlu digulir menyamping.

## 4. Revisi A — dua slot baru + konsolidasi daftar

Konstanta tunggal di `ManuscriptFile`:

```
BERKAS_ISBN = [
  'ebook'           => label 'E-book',           rules 'mimes:pdf,epub,zip',      wajibCetak true
  'sertifikat_isbn' => label 'Sertifikat ISBN',  rules 'mimes:pdf,jpg,jpeg,png',  wajibCetak true
  'barcode_isbn'    => label 'Barcode ISBN',     rules 'mimes:pdf,jpg,jpeg,png',  wajibCetak true
  'sertifikat_hki'  => label 'Sertifikat HKI',   rules 'mimes:pdf,jpg,jpeg,png',  wajibCetak false
]
```

`SLOTS_ISBN` diturunkan dari `array_keys()`-nya. Keempatnya ditambahkan ke `SLOTS` (untuk
`slotLabel()`) dan tetap **di luar** `SLOTS_BUKU`/`SLOTS_ARTIKEL` agar tidak bocor ke kartu
berkas Detail Naskah. Batas ukuran seragam 20 MB.

`BookIsbnController::BERKAS_RULES` dihapus; aturan diturunkan dari konstanta itu.
`assertBerkasLengkap()` juga membacanya, sehingga menambah slot kelima kelak cukup satu baris.

Formulir Kelola dan Direktori ISBN sama-sama melakukan perulangan atas konstanta ini —
tidak ada lagi daftar berkas yang ditulis tangan di Blade.

## 5. Revisi B — GoogleDriveService malas & singleton

Constructor dikosongkan dari kerja jaringan. Client dan service dibangun saat pertama kali
benar-benar dipakai, lalu disimpan di properti. Seluruh pemakaian internal `$this->client` /
`$this->service` diganti pemanggil malas.

Didaftarkan `singleton` di `AppServiceProvider`, sehingga satu request membayar otentikasi
paling banyak sekali — dan halaman yang tak menyentuh berkas tidak membayar sama sekali.

**Perilaku yang harus tetap sama:** `uploadFile()` mengembalikan `null` bila gagal (bukan
melempar), karena `ManuscriptFileService` mengandalkan itu untuk melempar
ValidationException berbahasa Indonesia. Kegagalan otentikasi yang dulu terjadi di
constructor kini muncul saat pemakaian — dan harus tetap berakhir sebagai `null`, bukan
Exception yang bocor ke halaman 500.

## 6. Revisi C — antrian benar-benar berjalan

Ditambahkan ke `app/Console/Kernel.php`:

```
queue:work --stop-when-empty --max-time=50 --tries=3
  ->everyMinute()
  ->withoutOverlapping()
  ->appendOutputTo(storage_path('logs/queue-work.log'))
```

`--stop-when-empty` membuatnya berhenti begitu antrian habis, bukan menetap sebagai daemon.
`--max-time=50` menjamin ia mati sebelum menit berikutnya memanggil lagi. `withoutOverlapping`
sebagai penjaga kedua.

`.env.example` diperjelas: `QUEUE_CONNECTION=database` disertai catatan bahwa nilai itu
menuntut cron `schedule:run` hidup, dan bahwa `sync` adalah pilihan sah bila tidak ada cron.

**Duplikat di `.env` dev dibereskan manual** (berkas `.env` tidak pernah masuk git). Ini
dicatat sebagai langkah, bukan perubahan kode.

**Verifikasi wajib**, bukan opsional: setelah perubahan, jalankan satu job sungguhan lewat
antrian dan buktikan tabel `jobs` kembali kosong. Menambahkan baris ke scheduler tanpa
membuktikan job benar-benar dieksekusi hanya memindahkan asumsi.

## 7. Revisi D — urutan simpan & umpan balik unggahan

**Urutan dibalik.** `simpanBerkas()` menerima `Title`, bukan `BookIsbn`, dan dijalankan
**sebelum** record ditulis:

```
validasi → assertBerkasLengkap → simpanBerkas → create/update BookIsbn → syncManuscript
```

Bila unggahan gagal, tidak ada satu pun baris `tb_book_isbns` yang tersentuh. Sengaja TIDAK
memakai transaksi DB: menahan transaksi terbuka selama panggilan jaringan lambat justru
menahan kunci tabel. Baris `ManuscriptFile` yatim yang mungkin tertinggal tidak berbahaya —
berkas memang berversi dan menumpuk secara alami.

**Umpan balik unggahan berlaku seluruh aplikasi.** Satu penangan terdelegasi di layout:
setiap `<form enctype="multipart/form-data">` yang dikirim akan mengunci tombol submit-nya
dan mengganti teksnya jadi "Mengunggah…". Menyelesaikan keluhan aslinya (halaman terasa
menggantung tanpa penjelasan) sekaligus mencegah kirim ganda — dan berlaku untuk seluruh
form unggah yang sudah ada, bukan cuma ISBN.

## 8. Revisi E — formulir hanya terbuka untuk galatnya sendiri

`$errors->any()` diganti `$errors->hasAny([...])` atas daftar kolom milik formulir ISBN,
diturunkan dari konstanta berkas + kolom teksnya.

## 9. Rencana test

**Revisi A** — `tests/Feature/BookIsbnBerkasTest.php` (menyusul yang sudah ada)
- keempat slot ISBN terdaftar, dan tak satu pun bocor ke `slotsFor()`
- unggah barcode & sertifikat HKI tersimpan dengan slot benar
- status `cetak` tanpa barcode → ditolak
- status `cetak` tanpa sertifikat HKI → **lolos** (opsional; penjaga agar HKI tak diam-diam jadi wajib)
- direktori menampilkan keempat kolom berkas

**Revisi B** — `tests/Feature/GoogleDriveLazyTest.php` (baru)
- membangun `GoogleDriveService` tidak memanggil jaringan: konstruksi harus jauh di bawah
  ambang waktu yang hanya bisa dicapai bila tak ada round-trip (mis. < 50 ms)
- resolusi `OrderBookController` juga di bawah ambang itu — penjaga langsung atas T1
- `app()` mengembalikan instance yang SAMA dua kali (bukti singleton)

**Revisi C** — `tests/Feature/QueueScheduleTest.php` (baru)
- daftar perintah terjadwal memuat `queue:work` dengan `--stop-when-empty`
- job yang di-dispatch benar-benar dieksekusi saat worker dijalankan (uji integrasi,
  bukan sekadar memeriksa string konfigurasi)

**Revisi D** — `tests/Feature/BookIsbnValidasiTest.php` (menyusul)
- unggahan gagal → **tidak ada** baris `tb_book_isbns` yang dibuat/diubah (penjaga T5)

**Revisi E** — ditambahkan ke test yang ada
- galat milik form lain tidak membuka formulir ISBN

## 10. Yang sengaja TIDAK dikerjakan

- **Unggahan ke antrian.** Keputusan #5. Ditinjau ulang bila berkas besar tetap terasa berat
  setelah perbaikan ini.
- **`file_get_contents()` diganti unggahan streaming/resumable** (T4). Perbaikan nyata untuk
  berkas besar, tapi menyentuh isi `GoogleDriveService` jauh lebih dalam daripada sekadar
  memalaskannya, dan batas aplikasi 20 MB membuatnya belum mendesak. Dicatat sebagai utang.
- Temuan audit alur naskah T3–T10 yang masih terbuka.

## 11. Risiko

| Risiko | Penanganan |
|---|---|
| `queue:work` tiap menit memberatkan shared hosting | `--stop-when-empty` + `--max-time=50`; proses mati sendiri saat antrian kosong |
| Cron `schedule:run` ternyata belum terpasang di cPanel | Wajib dikonfirmasi owner; tanpa itu Revisi C tak berefek dan email tetap tertahan. Disebut eksplisit di laporan akhir |
| Memalaskan otentikasi memindahkan letak kegagalan | `uploadFile()` tetap mengembalikan `null` saat gagal; ditutup test |
| Ambang waktu di test jadi rapuh di mesin lambat | Ambang dipasang longgar (< 50 ms lawan ~220 ms) — selisihnya terlalu besar untuk salah baca |
| Tabel direktori jadi 13 kolom | Diterima owner; pembungkus `overflow-x` sudah ada |
