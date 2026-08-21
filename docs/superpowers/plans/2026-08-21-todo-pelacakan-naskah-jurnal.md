# TODO — Tambahan Pelacakan Naskah & Arsip

**Dibuat:** 2026-08-21 · **Diperbarui:** 2026-08-21 (poin 3–5 ditambahkan)
**Status:** SELESAI — kelima poin terjawab lewat `2026-08-21-pelacakan-naskah-jurnal.md`.
**Konteks:** lanjutan dari branch `feat/sinkronisasi-status-order-naskah`

Dokumen ini **menangkap** permintaan dan keputusannya. Seluruh keputusan di §6 sudah
diambil, jadi ia siap dijadikan rencana implementasi bertahap — tapi ia sendiri belum
rencana: langkah, kode, dan testnya belum ditulis.

---

## 1. Informasi Publikasi bisa diperbarui dari Pelacakan Naskah

Sekarang "Informasi Publikasi" hanya ada di **detail judul**
(`resources/views/titles/show.blade.php:138`): Kode, Target Terbit, Jurnal Target + Link
Jurnal, Link Template Artikel, APC, Catatan, dan Opsi Jurnal Lain. Formnya POST ke
`title.info.update`, digerbangi permission `title.info` + `$canEditInfo`.

Yang diminta: informasi yang sama **tampil dan bisa langsung diperbarui** di `/naskah/{id}`
(`naskah.show`), tanpa pindah halaman.

- [x] `DetailNaskahController::show()` sudah memuat `orderDetail.titleRef` sebagai `$book` —
      Title-nya sudah di tangan, tak perlu query baru
- [x] Pakai ulang `title.info.update`; **jangan buat jalur tulis kedua** supaya aturannya
      tak bercabang dua versi
- [x] Panel baru di `resources/views/naskah/detail.blade.php`
- [x] Redirect harus kembali ke `naskah.show`, bukan ke halaman judul — butuh `_redirect`
      tersembunyi atau `back()`
- [x] Gerbang izin (§6.C): `@can('title.info')` — admin sudah memilikinya, production read-only
- [x] Untuk buku kolaborasi panel ini **level judul**, bukan per-order — sebutkan di UI

---

## 2. Link artikel terbit sebagai syarat naik ke Terbit/Publish

Selama link kosong, naskah **tidak boleh** dinyatakan terbit/publish, tidak dihitung
selesai, dan **tidak masuk arsip**. Untuk jurnal ini ditegaskan lagi di poin 5.

Keadaan sekarang:

| Jenis | Link disimpan di | Digerbangi? |
|---|---|---|
| Buku | `tb_book_isbns.link_terbit` | Ya tapi **bocor** — `BookIsbnController` mewajibkannya untuk status `cetak`, tapi `advance()` dari `cetak` → `terbit` tak memeriksa apa pun |
| Jurnal | `tb_journal_submissions.link_publish` | **Tidak sama sekali** — Direktori Jurnal tak tersambung ke tahap naskah (temuan A1) |

- [x] Tempat penyimpanan diputuskan (§6.A): `tb_titles.link_terbit` + cermin ke direktori
- [x] Field "Link Artikel Terbit" di `/naskah/{id}`, muncul saat `nextStage()` final
- [x] Gerbang di `TitleProgressService::advance()`, sejajar `assertLayoutUnlocked()`
- [x] Gerbang **wajib** ikut menutup `ChapterManuscriptService::advanceBookToStage()`
      (jalur ISBN `cetak` → `terbit`) — kalau tidak, bocor persis seperti temuan Task 3
- [x] `correct()` superadmin **dikecualikan** — koreksi memang wewenang membetulkan keadaan
- [x] Arsip: `archiveEligible()` sudah menuntut `manuscriptIsFinal()`, jadi syarat "tidak
      masuk arsip" **terpenuhi sendiri**. Kunci dengan test; jangan tambah gerbang kedua
- [x] Prefill dari `BookIsbn::link_terbit` / `JournalSubmission::link_publish` bila ada
- [x] Validasi `url|max:500`

---

## 3. "Status Orderan" tak boleh tetap "Diproses" setelah selesai

`/management/order` = `order.book.index` = `resources/views/orders/book/index.blade.php`
(layar yang sama dengan `/order/book`). Kolom **Status Orderan** hari ini:

```
isCancelled()          -> Dibatalkan
status == 'pending'    -> Menunggu
selain itu             -> Diproses      <- macet di sini selamanya
```

Cabang terakhir hanya berarti `status == 'lunas'`. Jadi labelnya **sudah menyesatkan
sebelum pekerjaan ini**: ia diturunkan dari kolom UANG tapi berbunyi seperti status
pekerjaan. Order yang naskahnya sudah terbit tetap tertulis "Diproses".

Task 11 menambahkan kolom **Pekerjaan** di sebelahnya (`Berjalan` / `Selesai` / `Ditarik` /
`Dibatalkan`) yang sudah menjawab pertanyaannya — tapi dua kolom berdampingan itu kini
terbaca berulang dan bisa tampak bertentangan ("Diproses" + "Selesai" di satu baris).

- [x] Bentuk diputuskan (§6.E): dua kolom dipertahankan, kolom lama jadi **Pembayaran**
- [x] Apa pun pilihannya, **jangan** menambah nilai baru ke `tb_orders.status`; itu
      melanggar K3 dan merusak Laporan Keuangan + Piutang. Ini murni soal tampilan
- [x] Lima nilai: `Menunggu` / `DP` / `Lunas` / `Dibatalkan` / `Refund` — lihat §6.E
      untuk urutan menang dan jebakan `isLunas()`

---

## 4. Artefak Penyelesaian menampilkan yang sudah terisi, bukan form kosong

Di `/management/archive/{id}`, "Artefak Penyelesaian" hari ini menampilkan **form kosong
penuh** meski datanya sudah diisi di tempat lain. `TitleArchivalService::defaultArtifacts()`
sudah punya mekanisme prefill, tapi baru mencakup empat nilai.

Yang diminta: kalau data sudah diisi/diunggah di `/naskah/{id}` atau di Direktori ISBN,
Artefak **tinggal menampilkan informasinya**, dan input hanya muncul untuk yang kurang.

Peta sumber yang sebenarnya tersedia:

| Artefak buku | Sumber yang sudah ada di aplikasi | Prefill sekarang |
|---|---|---|
| `isbn` | `BookIsbn.no_isbn` | ✅ sudah |
| `barcode_file` | `ManuscriptFile` slot `barcode_isbn` | ❌ belum |
| `publish_link` | `BookIsbn.link_terbit` | ❌ belum |
| `hki_file` | `ManuscriptFile` slot `sertifikat_hki` | ❌ belum |
| `final_book_file` | `ManuscriptFile` slot `ebook` | ❌ belum |
| `scholar_link` | — tak ada sumber | manual selamanya |

| Artefak artikel | Sumber | Prefill sekarang |
|---|---|---|
| `loa` | `JournalSubmission.loa_url` **atau** `ManuscriptFile` slot `loa` | ⚠️ hanya dari submission |
| `publish_link` | `JournalSubmission.link_publish` | ✅ sudah |
| `apc_bukti` | `JournalSubmission.bukti_bayar_url` | ✅ sudah |
| `final_naskah` | `ManuscriptFile` slot `final` | ❌ belum |

- [x] Perluas `defaultArtifacts()` supaya membaca `ManuscriptFile` (slot berkas naskah dan
      slot ISBN) serta `BookIsbn.link_terbit`
- [x] Baca berkas dalam **satu query** untuk seluruh slot — `BookIsbn::berkas()` sudah
      memperingatkan jangan dipanggil di dalam perulangan
- [x] Hanya hitung `ManuscriptFile` berstatus `selesai`; yang `antre`/`gagal` belum ada
      URL-nya dan menampilkannya sebagai "sudah lengkap" itu bohong
- [x] UI: artefak terisi tampil sebagai baris informasi + **sebutkan sumbernya**
      ("dari Direktori ISBN", "dari Detail Naskah") supaya orang tahu mengubahnya di mana;
      input hanya untuk yang kurang, plus tombol "ganti" untuk yang terisi
- [x] Ringkasan "kurang N dari M artefak" di kartu, sejalan dengan gaya peringatan
      kekurangan bayar yang sudah ada
- [x] Jangan menimpa nilai yang sudah pernah disimpan manual di `TitleArchiveArtifact` —
      `defaultArtifacts()` sekarang sudah memprioritaskan baris tersimpan di atas prefill;
      pertahankan urutan itu

---

## 5. Jurnal: tak bisa ke Publish sebelum link artikel terbit diisi

Penegasan poin 2 khusus jalur artikel: di `/naskah/{id}`, tombol menuju `publish` harus
**menolak** selama link kosong, dengan pesan yang menyebut apa yang kurang — bukan sekadar
"Naskah sudah berada di tahap akhir".

- [x] Pesan `ValidationException` berbahasa Indonesia yang menyebut field-nya
- [x] Formnya muncul di layar yang sama, bukan menyuruh pindah ke Direktori Jurnal
- [x] Test: `advance()` ke `publish` gagal tanpa link, berhasil dengan link

---

## 6. Keputusan — SUDAH DIAMBIL (2026-08-21)

### A+B. Link terbit: disimpan di judul, menyambung ke direktori bila bisa

Gabungan dua pendekatan, karena link terbit adalah bagian dari **Informasi Publikasi
judul** — jadi ia ikut aturan yang sama: diisi di `/titles/{id}`, dan kalau belum, bisa
diisi langsung di `/naskah/{id}`.

- Kolom baru **`tb_titles.link_terbit`** = sumber kanonik untuk KEDUA jenis.
- Prefill saat kosong: `BookIsbn.link_terbit` (buku), `JournalSubmission.link_publish`
  (artikel). Data yang sudah diisi di modul lain tak perlu diketik ulang.
- Menyimpan dari `/naskah/{id}`:
  - **selalu** menulis `tb_titles.link_terbit`;
  - artikel: bila sebuah jurnal dipilih ATAU baris `JournalSubmission` sudah ada, tulis
    juga `link_publish` di sana (buat barisnya bila jurnal dipilih).
- **Jurnal yang belum terdaftar di direktori TIDAK menghalangi publish.** `journal_id`
  tetap NOT NULL — tak ada perubahan skema di modul jurnal; sambungan ke direktori
  terjadi ketika bisa, bukan sebagai prasyarat.
- Gerbang naik ke final membaca `tb_titles.link_terbit`, dengan fallback ke dua sumber
  lama supaya buku yang sudah mengisi form ISBN tidak ikut terkunci.

Gerbangnya berlaku untuk **kedua jenis**: buku hanya menambal kebocoran `advance()`
(`cetak` → `terbit`), artikel mendapat gerbang yang selama ini tak ada sama sekali.

### C. Yang boleh mengedit Informasi Publikasi dari layar naskah: **admin**

Tak butuh pekerjaan tambahan — `admin` **sudah** memegang `title.info` dan `journal.*`
(`AccessMatrixSeeder`). Panel cukup digerbangi `@can('title.info')`; `production`
(pelaksana) otomatis melihatnya read-only. Tidak ada perluasan hak akses.

### D. Naskah yang sudah terbit tanpa link

Diterima apa adanya — gerbang hanya berlaku di jalur maju, naskah lama tidak diusik.

### E. Kolom status di /management/order

Dua kolom dipertahankan; kolom lama diperbaiki labelnya jadi **Pembayaran** dengan lima
nilai yang diturunkan (BUKAN kolom baru — `tb_orders.status` tak boleh ditambahi nilai,
itu melanggar K3):

| Nilai | Diturunkan dari |
|---|---|
| `Menunggu` | belum ada payment masuk sama sekali |
| `DP` | ada payment masuk, tapi `sisa > 0` |
| `Lunas` | `sisa <= 0` |
| `Dibatalkan` | `isCancelled()` |
| `Refund` | ada payment `payment_type = 'refund'` yang `paid` |

Kolom **Pekerjaan** (Task 11) tetap apa adanya di sebelahnya.

Urutan menang saat lebih dari satu benar: `Dibatalkan` > `Refund` > `Lunas` > `DP` >
`Menunggu`.

**Catatan penting:** `Lunas` di sini WAJIB memakai perhitungan uang (`biaya − paidNet`),
bukan `Order::isLunas()` — jalan pintas invoice membuat satu DP terbaca lunas (lihat
memori `sinkronisasi-status-order-naskah`). Kalau tidak, kolom ini akan menampilkan
`Lunas` untuk order yang baru bayar DP, dan nilai `DP` tak akan pernah muncul.

Istilah "Arsip"/"Terbit" tidak dipakai di kolom ini: `Selesai` (kolom Pekerjaan) sudah
berarti naskah final, sedangkan "Arsip" menuntut persetujuan manager yang bisa lama
menyusul.

## 7. Catatan pengerjaan

- Baca memori proyek `sinkronisasi-status-order-naskah` — sembilan penjelajahan grup,
  jebakan `$dates`, aturan `notWithdrawn()` masih berlaku.
- Route baru **wajib** dipetakan di `config/permissions.php` (`EnforcePermission` fail-closed).
- Suite penuh ~10 menit / 1169 test; pakai `--filter` saat bekerja.
- Data uji: `php artisan simapa:demo-status` (buang lagi dengan `--bersihkan`).
