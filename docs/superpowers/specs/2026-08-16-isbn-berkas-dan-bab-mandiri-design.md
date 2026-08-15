# Berkas ISBN & Alur Bab Naskah Mandiri — Desain

Tanggal: 2026-08-16
Status: disetujui owner (16 Agustus 2026), siap masuk rencana implementasi

## 1. Latar

Tiga permintaan owner, ditambah satu perbaikan seakar yang ikut disetujui:

| Kode | Permintaan |
|---|---|
| **A** | Bab bernaskah author pada buku kolaborasi tidak bisa berubah jadi Selesai meski berkasnya sudah diunggah, sehingga admin tak bisa memajukan buku dari Editing ke Layout |
| **B** | Direktori ISBN menyediakan unggahan e-book & sertifikat ISBN, plus kode ISBN dan link terbit di web avidpedia, agar marketing bisa mengunduh dan melihatnya langsung |
| **C** | Formulir Kelola registrasi ISBN mewajibkan seluruh kolom saat status Cetak/Terbit |
| **D** | *(tambahan, seakar dengan A)* Antrian produksi berhenti menawarkan bab bernaskah mandiri yang tak mungkin diambil |

A dan D berasal dari audit `Buntu di Alur Naskah` (15 Agustus 2026), temuan T1 dan T2.

## 2. Keputusan yang mengikat

Keputusan berikut diambil bersama owner dan **tidak boleh dilanggar diam-diam** oleh implementasi:

1. **Bab bernaskah mandiri melewati tahap Pembuatan.** Alurnya `menunggu → editing → selesai`, bukan `menunggu → pembuatan → editing → selesai`. Tahap Pembuatan berarti "tim menulis naskah"; untuk bab yang naskahnya dikirim author, tahap itu tak punya makna dan tak akan pernah punya pelaksana. Aturan ini menyalin persis yang sudah berlaku di tingkat judul (`menunggu_proses → editing` pada `autoAdvanceOnUpload()`).
2. **Berkas ISBN menumpang `tb_manuscript_files`,** dengan slot baru `ebook` dan `sertifikat_isbn`. Bukan kolom baru di `tb_book_isbns`.
3. **Blok berkas & link muncul mulai status Ber-ISBN,** tidak di status Pendaftaran.
4. **Hak unggah = hak kelola ISBN hari ini** (`isbn.create` / `isbn.edit`; efektif superadmin, manager, admin, production). Marketing tetap hanya melihat dan mengunduh lewat `isbn.view`. **Tidak ada permission baru.**
5. **Saat status Cetak/Terbit, seluruh kolom wajib kecuali Catatan.** Berkas yang sudah pernah diunggah dihitung terisi.

## 3. Revisi A — bab bernaskah mandiri punya jalan keluar

### Masalah hari ini

Kolom Aksi tiap baris bab adalah rantai `@elseif`; hanya satu cabang menang. Dua cabang teratas menangkap semua bab bermuatan mandiri apa pun statusnya, sehingga cabang `✓ Selesaikan Bab` di bawahnya tak pernah tercapai:

```
bab-table.blade.php:172  @elseif (mandiri && status!=='selesai' && izin.upload)  → form unggah
bab-table.blade.php:185  @elseif (mandiri)                                       → teks mati
bab-table.blade.php:217  @elseif (status!=='selesai' && izin.advance)            → tak terjangkau
```

`autoAdvanceChapterOnUpload()` juga tak menolong: ia menuntut status `pembuatan` **dan** pengunggah = pelaksana. Bab mandiri tak pernah punya pelaksana, jadi berapa kali pun berkasnya diunggah, statusnya tidak bergerak.

### Perubahan

**A1 · `ChapterProgress::nextStage()`** — bab bernaskah mandiri melompati `pembuatan`:

```
status 'menunggu' + naskahDariAuthor()  → 'editing'
selain itu                              → perilaku sekarang (langkah berikutnya di CHAPTER_STAGES)
```

Metode ini hanya dipanggil dari `TitleProgressService::advanceChapter()` (sudah diverifikasi lewat pencarian seluruh repo), jadi dampak perubahannya terkurung.

**A2 · `TitleProgressService::autoAdvanceChapterOnUpload()`** — tambah satu pemicu:

| Slot | Status bab | Syarat | Hasil |
|---|---|---|---|
| `masuk` | `pembuatan` | pengunggah = pelaksana | `editing` *(sudah ada)* |
| `masuk` | `menunggu` | bab bernaskah mandiri, pengunggah siapa pun | `editing` **(baru)** |

Pengunggah tidak dibatasi karena naskahnya datang dari luar tim — marketing, admin, maupun produksi sama-sama mungkin jadi orang yang menaruhnya. Ini konsisten dengan cabang `'menunggu_proses' => true` di `autoAdvanceOnUpload()` tingkat judul.

**A3 · `bab-table.blade.php`** — hentikan saling-meniadakan. Untuk bab mandiri yang belum `selesai`, sel Aksi merender **keduanya**:

- form unggah "⬆ Naskah dari Author" bila `izin['upload']` (versi baru menambah versi, tidak menimpa);
- tombol maju bila `izin['advance']`, dengan **label mengikuti tahap tujuan** supaya tidak berbohong:
  - tujuan `editing` → `→ Naskah sudah ada, mulai Editing`
  - tujuan `selesai` → `✓ Selesaikan Bab`
- bila pengguna tak punya keduanya, tetap tampil teks `Menunggu naskah dari author` seperti sekarang.

Label bertahap itu penting untuk 25 bab mandiri yang hari ini tertahan di `menunggu` di basis data dev: sebagiannya sudah punya berkas `masuk`, jadi admin bisa memajukannya tanpa memaksa author mengirim ulang.

Tombol maju **tidak** disyaratkan adanya berkas. Naskah author kerap datang lewat email atau WhatsApp dan baru diunggah belakangan; memblokir admin sampai berkasnya masuk sistem hanya memindahkan kebuntuan, bukan menghapusnya. Admin yang menekan tombol itu bertanggung jawab atas keputusannya, dan setiap perpindahan tercatat di riwayat lengkap dengan nama penekannya.

Bab bernaskah `dibuatkan` **tidak berubah sama sekali** — seluruh cabang existing tetap seperti sekarang.

### Hasil yang diharapkan

Pada naskah 64 (judul 34, bab 1 & 2 dipesan lewat order bernaskah mandiri): kedua bab memperoleh jalur ke `selesai`, `chaptersDone()` jadi true, dan `assertLayoutUnlocked()` meloloskan buku ke Layout tanpa campur tangan superadmin.

## 4. Revisi B — berkas & link di Direktori ISBN

### Penyimpanan

Slot baru pada `ManuscriptFile::SLOTS`:

| Slot | Label |
|---|---|
| `ebook` | E-book |
| `sertifikat_isbn` | Sertifikat ISBN |

Keduanya **tidak** dimasukkan ke `SLOTS_BUKU` maupun `SLOTS_ARTIKEL`, sehingga tidak muncul di kartu berkas halaman Detail Naskah. Ditambahkan konstanta `SLOTS_ISBN = ['ebook', 'sertifikat_isbn']` sebagai sumber tunggal untuk formulir dan direktori.

Alasan menumpang tabel ini alih-alih menambah kolom: versi, pengunggah, tanggal, ukuran, dan integrasi Google Drive sudah tersedia; kolom `slot` bertipe `string(20)` sehingga tak perlu migrasi untuk slotnya; dan `ManuscriptFileService::autoAdvance()` hanya bereaksi pada slot `masuk`, jadi mengunggah e-book tidak akan menggerakkan tahap naskah.

### Migrasi

Satu kolom, satu tabel:

```
tb_book_isbns.link_terbit  varchar(500) nullable  after catatan
```

`BookIsbn::$fillable` bertambah `link_terbit`.

### Formulir Kelola (Detail Judul)

Formulir `#isbnForm` berubah jadi `enctype="multipart/form-data"` dan mendapat blok baru yang **hanya dirender saat status tersimpan atau terpilih adalah `ber_isbn` atau `cetak`**:

- **E-book** — input file. Bila sudah ada, tampil tautan Drive + `v{versi}` + nama pengunggah + tanggal.
- **Sertifikat ISBN** — idem.
- **Link Terbit di web avidpedia** — input url.

Blok disembunyikan/ditampilkan mengikuti dropdown status lewat skrip yang sudah ada di `titles/show.blade.php` (`applyIsbnRequired`), diperluas untuk mengatur visibilitas blok ini sekalian.

Batas berkas: 20 MB, sejalan dengan seluruh unggahan naskah di aplikasi ini.
Format: e-book `pdf,epub,zip`; sertifikat `pdf,jpg,jpeg,png`.

### Direktori ISBN

`isbn/index.blade.php` mendapat tiga kolom baru setelah **No. ISBN**:

| Kolom | Isi |
|---|---|
| E-book | tautan `Unduh` ke `drive_url` versi terbaru, atau `—` |
| Sertifikat | tautan `Unduh` ke `drive_url` versi terbaru, atau `—` |
| Link Terbit | tautan `Buka` ke `link_terbit`, atau `—` |

Kode ISBN sudah ada di tabel dan tidak perlu ditambah.

`BookIsbnController::index()` memuat berkas dalam **satu query** untuk seluruh judul yang tampil — `ManuscriptFile` dengan `title_id in (…)`, `title_chapter_id null`, `slot in SLOTS_ISBN`, diambil versi tertinggi per pasangan judul+slot — lalu dipetakan ke tiap baris. Bukan lewat accessor per baris, yang akan melahirkan N+1.

Tombol **Kelola** dibungkus `@if($canManage)`. Hari ini tombol itu tampil untuk semua role termasuk marketing, padahal mereka tak berhak mengubah apa pun.

## 5. Revisi C — kelengkapan wajib saat Cetak/Terbit

`BookIsbnController::validated()` diperluas: saat `status === 'cetak'`, kolom berikut wajib.

| Kolom | Pendaftaran | Ber-ISBN | Cetak/Terbit |
|---|---|---|---|
| No. Pendaftaran | wajib | opsional | **wajib** |
| No. ISBN | opsional | wajib | **wajib** |
| No. Buku Cetak | opsional | opsional | **wajib** |
| Penerbit | opsional | opsional | **wajib** |
| Tgl Daftar · Tgl ISBN · Tgl Terbit | opsional | opsional | **wajib** |
| Link Terbit | — | opsional | **wajib** |
| E-book · Sertifikat ISBN | — | opsional | **wajib** |
| Catatan | opsional | opsional | opsional |

Aturan existing per status (`required_if`) tetap berlaku dan tidak dilonggarkan.

**Berkas dihitung terisi bila sudah pernah diunggah.** Pemeriksaannya dilakukan setelah `validate()`: untuk tiap slot ISBN, bila status `cetak` dan tidak ada berkas baru **dan** tidak ada `ManuscriptFile` tersimpan, lempar `ValidationException` dengan pesan Bahasa Indonesia (`'E-book wajib diunggah untuk status Cetak/Terbit.'`). Dengan begitu menyimpan ulang tanpa memilih berkas tidak ditolak.

Galat validasi sudah dirender di `layouts/sidebar.blade.php` sejak modul Penugasan Naskah, jadi pesan-pesan ini akan terlihat tanpa pekerjaan tambahan. Formulir dibuka kembali dengan `old()` agar isian tidak hilang — hari ini formulir ISBN belum memakai `old()` sama sekali, dan itu ikut dibereskan karena tanpa itu aturan wajib baru akan terasa menghukum.

### Efek samping yang disengaja

`BookIsbnController::syncManuscript()` memajukan tahap manuskrip buku ke `terbit` begitu status ISBN menjadi Cetak/Terbit. Dengan aturan wajib ini, **buku tidak bisa lagi didorong ke `terbit` lewat jalur ISBN sebelum datanya lengkap.** Ini konsekuensi yang diinginkan, bukan kecelakaan.

## 6. Revisi D — antrian produksi berhenti menawarkan bab mandiri

`MejaKerjaController::antrian()` menyaring bab dengan dua syarat (belum ada pelaksana, author sudah dipetakan) tetapi tidak menyaring naskah mandiri — padahal `AssignmentService::assertChapterButuhPelaksana()` akan menolaknya saat diklik. Di dev: 64 baris ditawarkan, **25 di antaranya mustahil diambil**.

Perubahan: tambahkan penyaring `! $c->naskahDariAuthor()` pada cabang bab.

Karena `naskahDariAuthor()` menelusuri bab → judul → order pemesan bab, penyaringan ini **wajib disertai eager load** `chapter.title.orderDetails` pada query antrian. Tanpa itu, 64 bab berarti puluhan query tambahan di layar yang dibuka tiap hari.

Setelah Revisi A, bab mandiri punya alurnya sendiri (unggah → editing → selesai) sehingga tak ada lagi alasan ia muncul di antrian pelaksana.

## 7. Rencana test

**Bab mandiri (Revisi A)** — `tests/Feature/NaskahBabMandiriTest.php`

- unggah slot `masuk` pada bab mandiri berstatus `menunggu` → bab jadi `editing`, tercatat di riwayat
- unggah oleh pengguna non-pelaksana tetap memicu (bab mandiri tak punya pelaksana)
- `advanceChapter()` pada bab mandiri `menunggu` → `editing`, bukan `pembuatan`
- `advanceChapter()` pada bab mandiri `editing` → `selesai`
- bab bernaskah `dibuatkan` **tidak** melompati `pembuatan` — penjaga regresi
- buku kolaborasi dengan bab mandiri: setelah semua bab selesai, `advance()` ke `layout` lolos
- baris bab mandiri merender tombol maju sekaligus form unggah (uji render)

**Berkas & direktori ISBN (Revisi B)** — `tests/Feature/BookIsbnBerkasTest.php`

- unggah e-book & sertifikat tersimpan sebagai `ManuscriptFile` dengan slot benar, `title_chapter_id` null
- unggah kedua kalinya menaikkan versi, tidak menimpa
- slot ISBN tidak muncul di kartu berkas Detail Naskah — penjaga agar `SLOTS_BUKU` tetap bersih
- unggah slot ISBN tidak menggerakkan tahap naskah
- direktori menampilkan tautan unduh dan link terbit
- marketing (`isbn.view` saja) melihat tautan unduh tetapi **tidak** melihat tombol Kelola

**Kelengkapan wajib (Revisi C)** — `tests/Feature/BookIsbnValidasiTest.php`

- simpan status `cetak` tanpa link terbit → ditolak, pesan Indonesia
- simpan status `cetak` tanpa berkas → ditolak
- simpan status `cetak` saat berkas sudah pernah diunggah → lolos tanpa memilih berkas lagi
- Catatan kosong tidak pernah menghalangi
- status `pendaftaran` dan `ber_isbn` tetap memakai aturan lama

**Antrian (Revisi D)** — ditambahkan ke test Meja Kerja yang ada

- bab bernaskah mandiri tidak muncul di antrian
- bab `dibuatkan` tanpa pelaksana tetap muncul

## 8. Yang sengaja TIDAK dikerjakan

Temuan audit berikut **di luar lingkup** pekerjaan ini dan tetap terbuka:

- **T3** — tombol "Perlu Revisi" pada buku justru memajukan naskah ke Layout
- **T4** — admin tak punya daftar pekerjaan yang harus ia bagikan
- **T5** — alasan keterlambatan diwajibkan tapi formulirnya tidak ada
- **T6** — revisi hanya mungkin di tahap Editing
- **T7** — brief marketing hanya bisa diisi lewat form order penuh
- **T8** — pembatasan bidang tidak pernah menyala
- **T9** — 115 naskah tanpa PJ & target, tak bisa disaring di papan
- **T10** — tautan bab meleset, gerbang batal hanya di tampilan

## 9. Risiko

| Risiko | Penanganan |
|---|---|
| Validasi `mimes:epub` bisa meleset karena mime `application/epub+zip` | Ditutup test unggah `.epub`; bila gagal, longgarkan ke `zip` dan catat di plan |
| `naskahDariAuthor()` di dalam `nextStage()` menambah query | Hanya dipanggil pada satu record di `advanceChapter()`; jalur daftar (antrian) memakai eager load |
| Berkas 20 MB kurang untuk e-book bergambar | Batas mengikuti standar aplikasi; naikkan hanya bila owner meminta dan `php.ini` produksi diperiksa |
| Migrasi belum dijalankan di dev → halaman ISBN 500 | Jalankan `php artisan migrate` pada `avidpedi_simapa` sebagai langkah eksplisit di plan |

## 10. Referensi

- Audit `Buntu di Alur Naskah`, 15 Agustus 2026 — temuan T1 & T2
- `docs/superpowers/specs/2026-08-09-penugasan-naskah-design.md` — alur bab & roll-up
- `config/permissions.php` modul `isbn` — pemetaan izin yang dipakai apa adanya
