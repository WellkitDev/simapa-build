# Stepper Bisa Diklik + Tata Letak Putaran Perbaikan

**Tujuan:** Tiap tahap di linimasa naskah bisa diklik untuk melihat apa yang benar-benar
terjadi di situ — siapa memindahkan, kapan, catatannya, berkas apa yang lahir, dan data
tahap-khususnya. Sekaligus memindahkan kartu Putaran Perbaikan ke tempat yang masuk akal.

**Lingkup:** Satu layar — `/naskah/{id}`. Tak menyentuh alur, aturan, atau izin.

---

## 1. Dua masalah

### 1.1 Putaran Perbaikan terkubur

Kartu Putaran Perbaikan duduk di **paling bawah kolom kiri**, di bawah lima kartu lain
(Informasi & PJ, catatan jurnal, Brief, Informasi Publikasi, Berkas). Kartu Aksi — yang
memuat tombol "Selesaikan tahap" — ada di **kolom kanan, paling atas**.

Akibatnya saat naskah tertahan, pesannya muncul di satu ujung layar dan kartu untuk
membebaskannya di ujung yang lain:

> Putaran revisi ke-1 belum dijawab. Unggah hasil revisi, atau tutup putarannya dengan catatan.

Orang membaca pesan itu di kolom kanan, lalu harus menggulir ke dasar kolom kiri untuk
menemukan tempat menjawabnya. Sebab dan akibat dipisahkan seluruh tinggi halaman.

### 1.2 Linimasa tak bisa ditanyai

Stepper menampilkan tahap mana yang sudah lewat, tapi tak ada cara menanyakan **apa yang
terjadi** di tahap itu. Datanya semua ada — log perpindahan, berkas per slot, catatan
jurnal, putaran perbaikan, registrasi ISBN — tapi tersebar di kartu-kartu berbeda yang
menyusunnya menurut jenis, bukan menurut waktu.

Untuk menjawab "berkas mana yang dikirim waktu Submit?" orang harus mencocokkan sendiri
tanggal di kartu Berkas dengan tanggal di Riwayat.

---

## 2. Keputusan yang dikunci

| # | Keputusan | Alasan |
|---|---|---|
| K1 | Kartu Putaran Perbaikan pindah ke **kolom kanan, tepat di atas Aksi** | Kartu yang menahan laju berada persis di atas tombol yang tertahan |
| K2 | Panel rincian **melebar di bawah stepper**, bukan modal | Mata sudah di situ; tak menutupi konteks; di ponsel tak ada jendela yang harus ditutup |
| K3 | Panel **hanya-baca**. Tak ada formulir edit di dalamnya | Lihat §3 — ini keputusan terpenting di dokumen ini |
| K4 | **Semua** tahap bisa diklik, termasuk yang belum dijalani | Klik yang tak merespons membingungkan. Tahap depan menjawab "nanti butuh apa" |
| K5 | Satu panel terbuka pada satu waktu; klik tahap yang sama menutupnya | Dua panel terbuka membuat orang lupa sedang membaca yang mana |
| K6 | Seluruh panel dirender di server sekaligus, ditampilkan bergantian | Tak ada endpoint baru, tak ada pemuatan ulang. Maksimal 8 tahap — volumenya kecil |
| K7 | **Sandi OJS TIDAK pernah dirender di panel ini** | Halaman naskah terbuka untuk semua role termasuk marketing. Lihat §4.3 |

---

## 3. Kenapa panelnya hanya-baca

Pertanyaan "bolehkah mengedit progress sebelumnya dari sini" diserahkan ke rekomendasi.
Jawabannya **tidak**, dan alasannya bukan kemalasan.

**Sudah ada tepat satu pintu resmi.** Mengubah tahap yang sudah lewat dilakukan lewat
**Koreksi**: superadmin saja, wajib catatan, tercatat `is_correction = true`. Menambah
pintu kedua dari stepper berarti dua jalan ke ruangan yang sama dengan kunci berbeda —
dan itu persis pola yang melahirkan kekacauan A9, di mana `revisi` sekaligus jadi tahap
dan jadi langkah mundur sampai tak ada yang tahu lagi mana yang mana.

**Datanya sudah punya pemiliknya masing-masing.** Berkas diubah lewat kartu Berkas, data
jurnal lewat Direktori Jurnal, informasi publikasi lewat panelnya sendiri, ISBN lewat
Direktori ISBN. Menyalin formulir-formulir itu ke dalam panel tahap berarti empat salinan
aturan validasi yang akan bercabang diam-diam.

**Yang kurang bukan kemampuan mengedit, melainkan kemampuan melihat.** Itu yang diminta,
dan itu yang dibangun.

Sebagai gantinya panel **menautkan** ke pemilik datanya ("Ubah di Direktori Jurnal →"),
dan untuk superadmin menyediakan satu pintasan ke formulir **Koreksi** yang sudah ada
dengan tahap itu terpilih. Satu pintu, hanya dipermudah jalannya.

---

## 4. Isi panel per tahap

### 4.1 Yang selalu ada

Diturunkan dari `tb_title_progress_logs`:

| Baris | Sumber |
|---|---|
| Masuk tahap | log dengan `to_value = <tahap>`, `created_at`-nya |
| Keluar tahap | log dengan `from_value = <tahap>` |
| Lama | selisih keduanya; tahap berjalan memakai `now()` |
| Oleh | `changed_by` pada log keluar (atau log masuk untuk tahap berjalan) |
| Catatan | `note` pada log tersebut |
| Penanda koreksi | `is_correction` — perpindahan koreksi ditandai, tak dicampur alur normal |

Tahap yang belum dijalani menampilkan "Belum dijalani" beserta daftar apa yang akan
diminta di situ (berkas apa, data apa) — jadi kliknya tetap menjawab sesuatu (K4).

Satu tahap bisa dijalani **lebih dari sekali** (mundur dari LoA ke Revisi). Panelnya
menampilkan **tiap kunjungan** sebagai baris terpisah, bukan hanya yang terakhir —
kalau tidak, justru riwayat yang paling menarik yang hilang.

### 4.2 Berkas per tahap

Peta slot → tahap. Berkas yang slotnya tak terpetakan tak muncul di panel mana pun,
dan itu benar — ia bukan keluaran sebuah tahap.

| Tahap | Slot |
|---|---|
| `pembuatan` | `masuk` |
| `editing` | `hasil_editing` |
| `layout` | `hasil_layout` |
| `proofreading` | `hasil_proofread` |
| `isbn` | `cover` + berkas ISBN (`ebook`, `barcode_isbn`, `sertifikat_hki`) |
| `loa` | `loa` |
| `terbit`, `publish` | `final` |
| `revisi`, `pembuatan` | berkas putaran ditampilkan lewat putarannya, bukan sebagai berkas tahap |

### 4.3 Data tahap-khusus

| Tahap | Isi | Tautan |
|---|---|---|
| `submit` | Jurnal tujuan, tanggal submit, **akun OJS** | Direktori Jurnal |
| `loa` | Link artikel terbit, tanggal terbit | Direktori Jurnal |
| `isbn` | Nomor ISBN | Direktori ISBN |
| `revisi`, `pembuatan` | Jumlah putaran + catatan permintaannya | kartu Putaran Perbaikan |

**Sandi OJS tidak ditampilkan (K7).** Halaman `/naskah/{id}` terbuka untuk **semua role**
— kartu Aksi disembunyikan dari marketing, tapi halamannya sendiri tidak. Direktori
Jurnal punya gerbang izinnya sendiri; panel ini tidak boleh jadi pintu belakang yang
membocorkan kredensial ke orang yang tak berhak membukanya di sana.

---

## 5. Tampilan

### 5.1 Tahap harus terlihat bisa diklik

Kalau tak terlihat bisa diklik, fiturnya tak ada. Yang dipakai:

- Tiap tahap jadi elemen `<button>` sungguhan, bukan `<div>` — papan ketik dan pembaca
  layar ikut bekerja tanpa pekerjaan tambahan
- `cursor: pointer` dan perubahan latar saat disorot
- Satu baris petunjuk di bawah stepper: *"Klik tahap untuk melihat rinciannya."*
- Tahap yang panelnya **sedang terbuka** diberi garis tepi tebal — supaya tak ada
  keraguan panel ini milik tahap yang mana (K5)

### 5.2 Keadaan awal

Tak ada panel terbuka. Halaman tampil persis seperti sekarang sampai ada yang mengklik —
tak ada kejutan bagi orang yang membukanya untuk keperluan lain.

### 5.3 Susunan kolom sesudah perubahan

```
        [ detail-header ]
        [ stepper — bisa diklik ]
        [ panel rincian tahap ]   ← muncul saat diklik, selebar halaman
        [ bab-table, bila kolaborasi ]

kiri (5)                     kanan (7)
  Informasi & PJ               Putaran Perbaikan   ← PINDAH KE SINI (K1)
  Catatan jurnal               Aksi
  Brief marketing              Riwayat
  Informasi Publikasi
  Berkas
```

---

## 6. Berkas

**Dibuat:**

| Berkas | Tanggung jawab |
|---|---|
| `app/Services/RincianTahapService.php` | Merakit isi panel per tahap dari log, berkas, jurnal, putaran, ISBN |
| `resources/views/naskah/partials/rincian-tahap.blade.php` | Panel rinciannya |
| `tests/Feature/RincianTahapTest.php` | |

**Diubah:**

| Berkas | Perubahan |
|---|---|
| `resources/views/naskah/partials/stepper.blade.php` | Tahap jadi tombol; panel disisipkan |
| `resources/views/naskah/detail.blade.php` | Putaran Perbaikan pindah kolom |
| `app/Http/Controllers/Pages/Naskah/DetailNaskahController.php` | Mengirim `rincian` |

Logikanya di **service**, bukan di Blade. Merakit "apa yang terjadi di tiap tahap" dari
lima sumber adalah logika sungguhan, dan Blade bukan tempatnya — juga tak bisa diuji
tanpa merender HTML.

---

## 7. Uji

| # | Tes | Menjaga |
|---|---|---|
| U1 | Tahap yang sudah dilewati melaporkan masuk, keluar, lama, dan pelakunya | §4.1 |
| U2 | Tahap yang dikunjungi **dua kali** melaporkan kedua kunjungan | §4.1 — bagian yang paling mudah hilang |
| U3 | Tahap berjalan memakai `now()` sebagai batas akhir, bukan null | §4.1 |
| U4 | Tahap yang belum dijalani ditandai, bukan kosong | K4 |
| U5 | Berkas muncul di tahap yang benar menurut slotnya | §4.2 |
| U6 | **Sandi OJS tak pernah ada di keluaran service maupun HTML halaman** | **K7** |
| U7 | Perpindahan koreksi ditandai berbeda dari alur normal | §4.1 |
| U8 | Putaran Perbaikan berada di kolom kanan sebelum kartu Aksi | K1 |
| U9 | Tiap tahap dirender sebagai `<button>`, bukan `<div>` | §5.1 |

---

## 8. Yang sengaja ditinggalkan

- **Mengedit dari panel** (§3). Tetap lewat Koreksi.
- **Memuat panel lewat AJAX.** Delapan tahap terlalu sedikit untuk membenarkan endpoint
  baru; kalau kelak isinya membengkak, barulah ini dipertimbangkan.
- **Rincian per bab** untuk buku kolaborasi. Tabel bab sudah menanganinya sendiri, dan
  menyalinnya ke panel tahap berarti dua tempat yang bisa berbeda pendapat.
