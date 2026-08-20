# TODO — Tambahan Pelacakan Naskah & Arsip

**Dibuat:** 2026-08-21 · **Diperbarui:** 2026-08-21 (poin 3–5 ditambahkan)
**Status:** Ditangkap, belum dirancang — keputusan terbuka di §6
**Konteks:** lanjutan dari branch `feat/sinkronisasi-status-order-naskah`

Dokumen ini **menangkap** permintaan supaya tidak hilang. Ia belum rencana implementasi:
§6 harus dijawab dulu.

---

## 1. Informasi Publikasi bisa diperbarui dari Pelacakan Naskah

Sekarang "Informasi Publikasi" hanya ada di **detail judul**
(`resources/views/titles/show.blade.php:138`): Kode, Target Terbit, Jurnal Target + Link
Jurnal, Link Template Artikel, APC, Catatan, dan Opsi Jurnal Lain. Formnya POST ke
`title.info.update`, digerbangi permission `title.info` + `$canEditInfo`.

Yang diminta: informasi yang sama **tampil dan bisa langsung diperbarui** di `/naskah/{id}`
(`naskah.show`), tanpa pindah halaman.

- [ ] `DetailNaskahController::show()` sudah memuat `orderDetail.titleRef` sebagai `$book` —
      Title-nya sudah di tangan, tak perlu query baru
- [ ] Pakai ulang `title.info.update`; **jangan buat jalur tulis kedua** supaya aturannya
      tak bercabang dua versi
- [ ] Panel baru di `resources/views/naskah/detail.blade.php`
- [ ] Redirect harus kembali ke `naskah.show`, bukan ke halaman judul — butuh `_redirect`
      tersembunyi atau `back()`
- [ ] Gerbang izin: PJ (admin) dan pelaksana (production) belum tentu punya `title.info` — §6.C
- [ ] Untuk buku kolaborasi panel ini **level judul**, bukan per-order — sebutkan di UI

---

## 2. Link artikel terbit sebagai syarat naik ke Terbit/Publish

Selama link kosong, naskah **tidak boleh** dinyatakan terbit/publish, tidak dihitung
selesai, dan **tidak masuk arsip**. Untuk jurnal ini ditegaskan lagi di poin 5.

Keadaan sekarang:

| Jenis | Link disimpan di | Digerbangi? |
|---|---|---|
| Buku | `tb_book_isbns.link_terbit` | Ya tapi **bocor** — `BookIsbnController` mewajibkannya untuk status `cetak`, tapi `advance()` dari `cetak` → `terbit` tak memeriksa apa pun |
| Jurnal | `tb_journal_submissions.link_publish` | **Tidak sama sekali** — Direktori Jurnal tak tersambung ke tahap naskah (temuan A1) |

- [ ] Putuskan tempat penyimpanan (§6.A)
- [ ] Field "Link Artikel Terbit" di `/naskah/{id}`, muncul saat `nextStage()` final
- [ ] Gerbang di `TitleProgressService::advance()`, sejajar `assertLayoutUnlocked()`
- [ ] Gerbang **wajib** ikut menutup `ChapterManuscriptService::advanceBookToStage()`
      (jalur ISBN `cetak` → `terbit`) — kalau tidak, bocor persis seperti temuan Task 3
- [ ] `correct()` superadmin **dikecualikan** — koreksi memang wewenang membetulkan keadaan
- [ ] Arsip: `archiveEligible()` sudah menuntut `manuscriptIsFinal()`, jadi syarat "tidak
      masuk arsip" **terpenuhi sendiri**. Kunci dengan test; jangan tambah gerbang kedua
- [ ] Prefill dari `BookIsbn::link_terbit` / `JournalSubmission::link_publish` bila ada
- [ ] Validasi `url|max:500`

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

- [ ] Putuskan bentuknya (§6.E) — gabung dua kolom, atau perbaiki label kolom lama
- [ ] Apa pun pilihannya, **jangan** menambah nilai baru ke `tb_orders.status`; itu
      melanggar K3 dan merusak Laporan Keuangan + Piutang. Ini murni soal tampilan
- [ ] Istilah yang diminta user: "Selesai" / "Arsip" / "Terbit" — pilih SATU dan pakai
      konsisten; "Arsip" dan "Terbit" berarti dua hal berbeda (lihat §6.E)

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

- [ ] Perluas `defaultArtifacts()` supaya membaca `ManuscriptFile` (slot berkas naskah dan
      slot ISBN) serta `BookIsbn.link_terbit`
- [ ] Baca berkas dalam **satu query** untuk seluruh slot — `BookIsbn::berkas()` sudah
      memperingatkan jangan dipanggil di dalam perulangan
- [ ] Hanya hitung `ManuscriptFile` berstatus `selesai`; yang `antre`/`gagal` belum ada
      URL-nya dan menampilkannya sebagai "sudah lengkap" itu bohong
- [ ] UI: artefak terisi tampil sebagai baris informasi + **sebutkan sumbernya**
      ("dari Direktori ISBN", "dari Detail Naskah") supaya orang tahu mengubahnya di mana;
      input hanya untuk yang kurang, plus tombol "ganti" untuk yang terisi
- [ ] Ringkasan "kurang N dari M artefak" di kartu, sejalan dengan gaya peringatan
      kekurangan bayar yang sudah ada
- [ ] Jangan menimpa nilai yang sudah pernah disimpan manual di `TitleArchiveArtifact` —
      `defaultArtifacts()` sekarang sudah memprioritaskan baris tersimpan di atas prefill;
      pertahankan urutan itu

---

## 5. Jurnal: tak bisa ke Publish sebelum link artikel terbit diisi

Penegasan poin 2 khusus jalur artikel: di `/naskah/{id}`, tombol menuju `publish` harus
**menolak** selama link kosong, dengan pesan yang menyebut apa yang kurang — bukan sekadar
"Naskah sudah berada di tahap akhir".

- [ ] Pesan `ValidationException` berbahasa Indonesia yang menyebut field-nya
- [ ] Formnya muncul di layar yang sama, bukan menyuruh pindah ke Direktori Jurnal
- [ ] Test: `advance()` ke `publish` gagal tanpa link, berhasil dengan link

---

## 6. Keputusan yang masih terbuka

### A. Di mana link artikel terbit disimpan?

1. **Kolom baru `tb_titles.link_terbit`** — satu tempat untuk kedua jenis; link itu milik
   KARYA, bukan satu order (buku 20 order = satu link). Biaya: tumpang tindih dengan dua
   kolom yang sudah ada.
2. **Pakai ulang yang ada** — tak menambah kolom, tapi butuh baris induk yang mungkin belum
   dibuat, dan jurnal wajib punya `JournalSubmission` yang hari ini sepenuhnya manual.
3. **Kolom di `tb_title_progress`** — salah tempat: per-order, padahal linknya satu per judul.

Condong ke **1**, dengan prefill dari 2.

### B. Gerbangnya untuk jurnal saja, atau buku juga?

Buku sudah mewajibkan `link_terbit` di form ISBN, jadi memasang gerbang untuk buku sebagian
besar hanya menambal kebocoran `advance()`. Condong ke **keduanya** — gerbang yang berlaku
separuh justru membingungkan.

### C. Siapa yang boleh mengedit Informasi Publikasi dari layar naskah?

`title.info` belum tentu dipegang PJ (admin) maupun pelaksana (production) — perlu dicek di
`AccessMatrixSeeder`. Kalau panelnya read-only bagi mereka, permintaan "dapat diupdate
langsung" tidak terpenuhi. Kalau dibuka, itu perluasan hak akses yang harus disengaja.

### D. Naskah yang SUDAH terbit tanpa link

Gerbang hanya berlaku di jalur maju, jadi naskah lama tetap final tanpa link. Diterima
apa adanya, atau perlu daftar "naskah terbit tanpa link" supaya bisa dilengkapi menyusul?

### E. Bentuk kolom status di /management/order

1. **Gabung jadi satu kolom** yang menampilkan keadaan paling informatif
   (`Dibatalkan` > `Ditarik` > `Selesai` > `Menunggu`/`Diproses`). Paling ringkas, tapi
   menyembunyikan keadaan uang saat pekerjaannya sudah selesai.
2. **Pertahankan dua kolom, perbaiki labelnya** — ganti judul kolom lama jadi "Pembayaran"
   dengan nilai `Menunggu` / `Lunas` / `Dibatalkan`. Menghapus kata "Diproses" yang
   menyesatkan tanpa kehilangan informasi. **Condong ke sini.**
3. Biarkan dua kolom apa adanya — permintaan tidak terpenuhi.

Istilah "Arsip" vs "Terbit" vs "Selesai" juga harus dipilih: `Selesai` = naskah final;
`Arsip` = arsip judulnya sudah **disetujui** manager (langkah manual terpisah, bisa lama
menyusul). Keduanya tidak sama, dan memakai "Arsip" berarti kolom ini ikut menunggu
persetujuan manusia.

---

## 7. Catatan pengerjaan

- Baca memori proyek `sinkronisasi-status-order-naskah` — sembilan penjelajahan grup,
  jebakan `$dates`, aturan `notWithdrawn()` masih berlaku.
- Route baru **wajib** dipetakan di `config/permissions.php` (`EnforcePermission` fail-closed).
- Suite penuh ~10 menit / 1169 test; pakai `--filter` saat bekerja.
- Data uji: `php artisan simapa:demo-status` (buang lagi dengan `--bersihkan`).
