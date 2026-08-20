# TODO — Tambahan Pelacakan Naskah Jurnal

**Dibuat:** 2026-08-21
**Status:** Ditangkap, belum dirancang — tiga keputusan masih terbuka (§3)
**Konteks:** lanjutan dari branch `feat/sinkronisasi-status-order-naskah`

Dokumen ini **menangkap** dua permintaan supaya tidak hilang saat pekerjaan data uji
berjalan. Ia belum rencana implementasi: §3 harus dijawab dulu.

---

## 1. Informasi Publikasi bisa diperbarui dari Pelacakan Naskah

Sekarang "Informasi Publikasi" hanya ada di **detail judul**
(`resources/views/titles/show.blade.php:138`), berisi: Kode, Target Terbit, Jurnal Target
+ Link Jurnal, Link Template Artikel, APC, Catatan, dan Opsi Jurnal Lain. Formnya
POST ke `title.info.update`, digerbangi permission `title.info` + `$canEditInfo`.

Yang diminta: informasi yang sama **tampil dan bisa langsung diperbarui** di
`/naskah/{id}` (`naskah.show`), tanpa harus pindah ke halaman judul.

Modal yang sudah ada:

- [ ] `DetailNaskahController::show()` sudah memuat `orderDetail.titleRef` sebagai `$book` —
      Title-nya sudah di tangan, tak perlu query baru
- [ ] `title.info.update` sudah ada beserta validasinya — **jangan buat jalur tulis kedua**,
      pakai ulang yang itu supaya aturannya tak bercabang dua versi
- [ ] Tambah panel "Informasi Publikasi" di `resources/views/naskah/detail.blade.php`
- [ ] Form kembali ke `naskah.show`, bukan ke `title.show` — `title.info.update` sekarang
      me-redirect ke halaman judul, jadi butuh redirect yang sadar asal
      (mis. `_redirect` tersembunyi atau `back()`)
- [ ] Gerbang izin: siapa yang boleh mengedit dari layar naskah? PJ dan pelaksana **tidak**
      punya `title.info` hari ini — lihat §3.C
- [ ] Untuk buku kolaborasi, panel ini bersifat **level judul**, bukan per-order: sebutkan
      itu di UI supaya PJ tak mengira ia hanya mengubah ordernya sendiri

---

## 2. Link artikel terbit sebagai syarat naik ke Terbit/Publish

Yang diminta: form **link artikel terbit** muncul saat naskah hendak masuk tahap
`terbit`/`publish`. Selama link itu kosong, naskah **tidak boleh** dinyatakan
terbit/publish, tidak dihitung selesai, dan **tidak masuk arsip**.

Keadaan sekarang:

| Jenis | Link terbit disimpan di | Digerbangi? |
|---|---|---|
| Buku | `tb_book_isbns.link_terbit` | Ya, tapi **bocor** — `BookIsbnController` mewajibkannya untuk status `cetak`, namun `advance()` dari `cetak` → `terbit` tak memeriksa apa pun |
| Jurnal | `tb_journal_submissions.link_publish` | **Tidak sama sekali** — modul Direktori Jurnal tak tersambung ke tahap naskah (temuan A1) |

Langkah:

- [ ] Putuskan tempat penyimpanan (§3.A)
- [ ] Panel/field "Link Artikel Terbit" di `/naskah/{id}`, muncul saat `nextStage()` final
- [ ] Gerbang di `TitleProgressService::advance()` — sejajar dengan `assertLayoutUnlocked()`
      yang sudah ada, dengan `ValidationException` berbahasa Indonesia
- [ ] Gerbang harus ikut menutup `ChapterManuscriptService::advanceBookToStage()`
      (jalur ISBN `cetak` → `terbit`), bukan hanya `advance()` — kalau tidak, gerbangnya
      bocor persis seperti temuan Task 3
- [ ] `correct()` superadmin **dikecualikan**: koreksi memang wewenang membetulkan keadaan,
      termasuk memundurkan naskah yang terlanjur ditandai terbit
- [ ] Kelayakan arsip: `Title::archiveEligible()` sudah menuntut `manuscriptIsFinal()`, jadi
      begitu gerbangnya dipasang, syarat "tidak masuk arsip" **terpenuhi sendiri** —
      pastikan dengan test, jangan tambah gerbang kedua yang bisa berbeda pendapat
- [ ] Prefill dari `BookIsbn::link_terbit` / `JournalSubmission::link_publish` bila sudah ada,
      supaya data yang sudah diisi di modul lain tak perlu diketik ulang
- [ ] Validasi: `url` + `max:` — jangan angka tetap kalau menyangkut unggahan; ini teks jadi
      `max:500` cukup

---

## 3. Keputusan yang masih terbuka

### A. Di mana link artikel terbit disimpan?

1. **Kolom baru `tb_titles.link_terbit`** — satu tempat untuk kedua jenis, dan link itu
   memang milik KARYA, bukan milik satu order. Untuk buku kolaborasi 20 order, satu link.
   Biaya: satu kolom lagi yang tumpang tindih dengan dua kolom yang sudah ada.
2. **Pakai ulang yang sudah ada** (`tb_book_isbns.link_terbit`, `tb_journal_submissions.link_publish`)
   — tak menambah kolom, tapi keduanya butuh baris induk yang mungkin belum dibuat, dan
   jurnal wajib punya baris `JournalSubmission` yang hari ini sepenuhnya manual.
3. **Kolom di `tb_title_progress`** — salah tempat: per-order, padahal linknya satu per judul.

Condong ke **1**, dengan prefill dari 2.

### B. Gerbangnya untuk jurnal saja, atau buku juga?

Permintaan menyebut "pelacakan naskah jurnal", tapi syaratnya berbunyi "terbit/publish" —
`terbit` itu istilah buku. Buku sudah punya `link_terbit` yang diwajibkan di form ISBN,
jadi memasang gerbang untuk buku sebagian besar hanya menambal kebocoran `advance()`.
Condong ke **keduanya**, karena gerbang yang berlaku separuh justru membingungkan.

### C. Siapa yang boleh mengedit Informasi Publikasi dari layar naskah?

`title.info` hari ini tidak dipegang PJ (admin) maupun pelaksana (production) secara
otomatis — perlu dicek di `AccessMatrixSeeder`. Kalau panelnya read-only untuk mereka,
permintaan "dapat diupdate langsung" tidak terpenuhi. Kalau dibuka, itu perluasan hak
akses yang harus disengaja, bukan efek samping.

### D. Naskah yang SUDAH terbit tanpa link

Setelah gerbang dipasang, naskah lama yang sudah `terbit`/`publish` tanpa link akan
tetap final (gerbangnya hanya di jalur maju). Apakah itu diterima, atau perlu laporan
"naskah terbit tanpa link" supaya bisa dilengkapi menyusul?

---

## 4. Catatan pengerjaan

- Baca dulu memori proyek `sinkronisasi-status-order-naskah` — sembilan penjelajahan grup,
  jebakan `$dates`, dan aturan `notWithdrawn()` masih berlaku.
- Route baru **wajib** dipetakan di `config/permissions.php` (`EnforcePermission` fail-closed).
- Suite penuh ~10 menit / 1169 test; pakai `--filter` saat bekerja.
