# Revisi Sesudah Submit — Putaran Perbaikan Berberkas

**Menutup A9** dari audit `2026-08-20-sinkronisasi-status-order-naskah-design.md`.

**Tujuan:** `revisi` pindah ke belakang `submit` sesuai realita jurnal, dan tahap itu
berhenti jadi label kosong — ia mendapat formulir permintaan revisi berberkas yang
ditujukan ke Pelaksana, balasan berupa naskah perbaikan, dan jalan mundur dari LoA
yang tak perlu superadmin.

**Tidak termasuk:** perapian struktur folder Google Drive. Itu spec terpisah; berkas
revisi yang lahir dari pekerjaan ini ikut dirapikan di sana.

---

## 1. Latar

### 1.1 Keadaan sekarang, diverifikasi 2026-08-22

```php
const ARTICLE_STAGES = [
    'menunggu_proses', 'pembuatan', 'editing',
    'revisi', 'submit', 'loa', 'publish',
];
```

`revisi` terletak **sebelum** `submit`. Alur sebenarnya di jurnal adalah kebalikannya:
naskah di-submit, reviewer meminta revisi, naskah diperbaiki dan disubmit ulang, baru
LoA. Urutan ini bukan sekadar label — `advance()` maju satu langkah lewat array ini,
`Title::manuscriptStatus()` mencari tahap paling belakang lewat **posisi indeks**, dan
Meja Kerja, SLA, serta dashboard produksi menghitung dari posisi yang sama.

### 1.2 Empat temuan yang mengubah bentuk pekerjaan

| | Temuan | Akibat pada rancangan |
|---|---|---|
| T1 | **`revisi` hari ini bukan langkah mundur — ia `advance()` biasa.** `DetailNaskahController::revisi()` isinya `$stages->advance()` dengan catatan kalengan; ia mendarat di `revisi` semata karena kebetulan tahap berikutnya sesudah `editing` | Memindahkan urutan otomatis mematikan tombolnya. Ia harus berubah arti, bukan sekadar digeser |
| T2 | **Bug aktif: tombol "Perlu Revisi" juga muncul di BUKU, dan di sana ia melompat ke Layout.** Gerbangnya cuma `$progress->status === 'editing'` tanpa memeriksa jenis; `BOOK_STAGES` tak punya `revisi`, jadi `advance()` dari editing mendarat di `layout` | Wajib ditutup di pekerjaan ini. Sudah salah sebelum kita menyentuh apa pun |
| T3 | **`applyGroup()` diam-diam menolak perpindahan mundur non-koreksi.** Penjaga `if (! $isCorrection && $idx > $targetIdx) continue;` melewati setiap anggota grup yang indeksnya di depan target | Tombol mundur akan melapor "berhasil" sambil tak menggerakkan apa pun. Penjaga itu harus dipecah — lihat §5.3 |
| T4 | **Urutan tahap punya salinan kedua.** `PelacakanNaskahController::ZONA['artikel']` menulis ulang `['pembuatan','editing','revisi','submit']` dengan tangan | Kalau hanya `ARTICLE_STAGES` yang diubah, papan Pelacakan akan menampilkan urutan lama. Diturunkan dari konstanta, jangan disalin |

### 1.3 Risiko migrasi: hampir nol

Hitungan `tb_title_progress` di DB dev (2026-08-22):

```
menunggu_proses 91 · pembuatan 23 · isbn 10 · publish 5
terbit 4 · editing 2 · cetak 1 · submit 1 · revisi 0
```

**Nol baris duduk di `revisi`.** Ada 8 baris `tb_title_progress_logs` yang pernah
menyentuhnya, jadi tahap itu memang pernah dipakai — tapi tak ada naskah yang posisinya
harus ditebak saat migrasi. Ini menghapus keberatan terbesar yang dicatat di backlog
`2026-08-22-backlog-kelompok-uang-dan-terbit-design.md`.

Angka yang sama **wajib diperiksa ulang di DB produksi** sebelum backfill dijalankan.
Aturannya bila ternyata ada isinya: lihat §7.2.

---

## 2. Keputusan yang dikunci

| # | Keputusan | Alasan |
|---|---|---|
| K1 | `revisi` pindah ke **antara `submit` dan `loa`**. `BOOK_STAGES` tak berubah | Buku memang tak melewati revisi jurnal |
| K2 | Revisi adalah **tahap yang boleh dilewati**, bukan tahap wajib berisi. Tanpa permintaan revisi, tekan maju dan naskah lanjut ke LoA | Revisi itu perkecualian, bukan langkah normal |
| K3 | Berkas mengalir **dua arah**: PJ mengunggah permintaan (`revisi_minta`), Pelaksana **atau PJ** mengunggah hasil (`revisi_hasil`) | Permintaan datang dari reviewer lewat PJ; perbaikan boleh dikerjakan siapa pun yang memegang berkasnya |
| K4 | Putaran perbaikan jadi **baris tabel sendiri** (`tb_manuscript_revisions`), bukan kolom di `tb_manuscript_files` | Putaran bisa ada sebelum berkasnya ada; catatan + tujuan tak boleh tersalin ke tiap berkas dan saling bertentangan |
| K5 | Putaran **dibuat malas** — hanya saat permintaan benar-benar dikirim atau tombol mundur ditekan | Naskah yang lewat tanpa revisi tak meninggalkan putaran kosong |
| K6 | Mundur LoA→Revisi dan Editing→Pembuatan boleh dilakukan **PJ/admin** (`naskah.advance`), wajib alasan, dan tercatat sebagai **alur normal** bukan koreksi | Supaya "Koreksi" tetap berarti "ada yang salah" dan tak tercampur kerja harian |
| K7 | Mundur membuka **putaran baru**; putaran lama tetap terlist utuh dan hanya-baca | Supaya terbaca permintaan mana yang datang sesudah LoA ditolak |
| K8 | Maju dari Revisi **ditahan** selama ada putaran terbuka yang punya permintaan tapi belum ada hasilnya — dengan jalan keluar **"Tutup putaran tanpa berkas"** (wajib catatan, PJ) | Gerbang tanpa pintu darurat mengunci naskah selamanya dan hanya superadmin yang bisa membebaskan. Orang akan berhenti memakai sistemnya |
| K9 | Status **`ditolak`** TIDAK dibangun | Di luar permintaan, dan menyeret pertanyaan uang ("order dari naskah yang ditolak jadi apa"). Tetap tercatat di backlog |

---

## 3. Kenapa tabel sendiri, bukan kolom di `tb_manuscript_files`

Alternatif yang ditolak: menambah `round`, `note`, dan `for_user_id` ke
`tb_manuscript_files` lalu mengelompokkan di tampilan.

Tiga alasan penolakan:

1. **Putaran bisa ada sebelum berkasnya ada.** PJ menulis permintaan lebih dulu dan
   berkasnya menyusul, atau permintaannya memang berupa catatan saja. Tanpa baris
   putaran, keadaan itu tak bisa diwakili sama sekali.
2. **Catatan dan tujuan akan tersalin ke tiap berkas** dan karenanya bisa saling
   bertentangan. Satu permintaan = satu catatan, bukan tiga catatan yang kebetulan sama.
3. **"Kapan putaran dibuka" tak punya tempat.** Yang ada hanya `created_at` berkas
   pertama, yang bukan hal yang sama.

Alternatif kedua yang ditolak: memakai `tb_title_progress_logs` yang sudah punya
`note`. Ditolak karena log terikat ke **satu progress (order)** sementara berkas
terikat ke **judul** — untuk artikel kolaborasi keduanya tak sejajar, dan menyambungkan
berkas ke baris log lewat kedekatan waktu itu rapuh.

---

## 4. Model data

### 4.1 `ARTICLE_STAGES`

```php
const ARTICLE_STAGES = [
    'menunggu_proses', 'pembuatan', 'editing',
    'submit', 'revisi', 'loa', 'publish',
];
```

`BOOK_STAGES`, `STAGE_HANDLER`, `STAGE_LABELS_ID`, dan `FINAL_STAGES` tak berubah.

`PelacakanNaskahController::ZONA['artikel']` **berhenti menulis urutannya sendiri**.
Kolom zona Produksi diturunkan dari `ARTICLE_STAGES` — ambil irisan antara daftar tahap
dan daftar kolom zona, urut menurut `ARTICLE_STAGES`. Nol salinan kedua (T4).

### 4.2 Tabel baru `tb_manuscript_revisions`

Satu baris = satu **putaran perbaikan**.

| kolom | tipe | isi |
|---|---|---|
| `id` | bigint | |
| `title_id` | FK `tb_titles` cascade | Putaran milik JUDUL, bukan order — mengikuti pola `tb_manuscript_files`. Untuk artikel kolaborasi satu putaran berlaku sejudul |
| `title_chapter_id` | FK nullable `tb_title_chapters` nullOnDelete | Bab, bila putaran menyangkut satu bab saja |
| `round` | unsignedInteger | Nomor urut **per judul**: 1, 2, 3. Bukan per tahap |
| `stage` | string(20) | Tahap tempat putaran dibuka: `revisi` atau `pembuatan` |
| `from_stage` | string(20) | Asalnya: `submit`, `loa`, atau `editing` |
| `requested_by` | FK nullable `users` | PJ yang membuka |
| `assigned_to` | FK nullable `users` | Pelaksana yang dituju |
| `request_note` | text | Catatan permintaan — **wajib** |
| `closed_at` | timestamp nullable | Terisi = putaran selesai |
| `closed_by` | FK nullable `users` | |
| `close_note` | text nullable | Wajib hanya bila ditutup tanpa berkas (K8) |
| `created_at` / `updated_at` | | |

Indeks: `['title_id', 'stage', 'closed_at']` untuk pertanyaan "masih ada putaran
terbuka di tahap ini?", plus `['title_id', 'round']`.

**Model `ManuscriptRevision`:** semua kolom waktu di `$casts`, **tidak** di
`protected $dates` — properti itu mati sejak Laravel 10 dan sudah tiga kali menjebak
repo ini.

### 4.3 Perubahan pada `tb_manuscript_files`

Satu kolom baru:

```php
$table->foreignId('manuscript_revision_id')->nullable()
      ->after('title_chapter_id')
      ->constrained('tb_manuscript_revisions')->nullOnDelete();
```

Dua slot baru di `ManuscriptFile::SLOTS` — kolom `slot` bertipe `string(20)` sehingga
**tak butuh migrasi**:

```php
'revisi_minta' => 'Permintaan Revisi',
'revisi_hasil' => 'Hasil Revisi',
```

**Koreksi saat implementasi (2026-08-22).** Rancangan awal memasukkan kedua slot ke
`SLOTS_ARTIKEL` dan `SLOTS_BUKU`. Itu **tidak dilakukan**, dan sengaja.

Kedua daftar itu menyetir kartu berkas di layar naskah — satu baris tetap per slot.
Memasukkan slot revisi ke sana berarti setiap naskah yang tak pernah direvisi menampilkan
"Permintaan Revisi — belum ada" selamanya, dan naskah yang pernah direvisi menampilkan
berkas yang sama **dua kali**: sekali datar di kartu berkas, sekali berkelompok per
putaran di kartu Revisi. Berkas revisi milik PUTARAN, bukan tahap, dan kartu datar tak
bisa mewakilinya.

`ManuscriptFile::slotSah()` membaca `SLOTS`, bukan daftar per-jenis, jadi validasi
unggahnya tetap lolos tanpa keduanya. Dikunci tes `slot_revisi_sah_tapi_tak_muncul_di_kartu_berkas`.

`version` yang sudah ada tetap mengurus berkas berulang di slot yang sama; putaran
diurus `manuscript_revision_id`. Banyak berkas per putaran otomatis didukung tanpa
aturan baru.

Unggahannya lewat jalur antre yang sudah ada (`ManuscriptFileService::upload()` →
`UnggahBerkasKeDrive`) **tanpa perubahan** — kecuali satu hal di §5.5.

---

## 5. Aturan

### 5.1 Membuka putaran di tahap Revisi

PJ mengisi formulir di tahap `revisi`: catatan (wajib), pelaksana tujuan (default =
`pelaksana_user_id` naskah), dan nol-atau-lebih berkas.

- Membuat baris `tb_manuscript_revisions` dengan `stage='revisi'`,
  `from_stage='submit'`, `round` = putaran terbesar judul itu + 1.
- Berkas diunggah ke slot `revisi_minta` dengan `manuscript_revision_id` terisi.
- Notifikasi ke `assigned_to` — **ini bagian yang membuat "ditujukan untuk Pelaksana"
  berarti sesuatu.** Lihat §5.6.

Tanpa formulir ini dikirim, tak ada putaran, dan naskah boleh langsung maju (K2, K5).

### 5.2 Menjawab putaran

Pelaksana **atau** PJ mengunggah nol-atau-lebih berkas ke slot `revisi_hasil` dengan
`manuscript_revision_id` putaran yang sedang terbuka, plus catatan opsional.

Putaran dianggap **terjawab** begitu ada minimal satu berkas `revisi_hasil` berstatus
`selesai` **atau** `antre`. Berkas yang masih antre dihitung terjawab: berkasnya sudah
dikirim orangnya, dan menahan naskah karena Google Drive sedang lambat adalah menghukum
orang atas hal yang bukan urusannya. Berkas berstatus `gagal` **tidak** dihitung.

**Putaran ber-`stage='pembuatan'` tidak digerbangi.** Gerbang §5.4 berlaku hanya untuk
`stage='revisi'`. Alasannya: pengembalian ke Pembuatan meminta naskahnya **dikerjakan
ulang**, dan hasil kerja itu masuk lewat slot `masuk`/`hasil_editing` yang sudah ada —
bukan lewat `revisi_hasil`. Menuntut berkas balasan di situ akan meminta orang mengunggah
naskah yang sama dua kali. Putaran `pembuatan` ditutup otomatis saat tahapnya maju.

### 5.3 Mundur satu tahap — dan penjaga yang harus dipecah (T3)

Dua pasangan yang dibolehkan, tak ada yang lain:

| Dari | Ke | Berlaku untuk |
|---|---|---|
| `loa` | `revisi` | artikel |
| `editing` | `pembuatan` | artikel **dan buku** |

Keduanya: wajib alasan, boleh melampirkan berkas, membuka putaran baru (K7), bergerak
se-grup lewat `applyGroup()`.

**Masalahnya:** `applyGroup()` hari ini berbunyi

```php
if (! $isCorrection && $idx !== false && $idx > $targetIdx) {
    continue; // sudah lebih maju dari target
}
```

Untuk perpindahan mundur, **setiap** anggota grup punya `$idx > $targetIdx`, jadi
semuanya dilewati dan `applyGroup()` mengembalikan 0. Tombolnya akan melapor berhasil
sambil tak menggerakkan apa pun — kegagalan senyap, jenis yang paling mahal.

Penjaga itu mencampur dua pertanyaan berbeda: "apakah ini koreksi" dan "bolehkah
bergerak mundur". Pecah jadi dua:

```php
private function applyGroup(
    TitleProgress $progress,
    string $target,
    User $actor,
    ?string $note,
    bool $isCorrection,
    string $event,
    bool $bolehMundur = false        // BARU
): int {
    ...
    if (! $isCorrection && ! $bolehMundur && $idx !== false && $idx > $targetIdx) {
        continue;
    }
```

`advance()` tetap memanggil tanpa parameter itu, jadi perilakunya tak berubah sedikit
pun. Hanya jalur mundur yang menyalakannya.

**Lantai pengaman:** mundur ditolak bila naskah `archived_at` terisi, `cancelled_at`
terisi, atau tahapnya final (`terbit`/`publish`). Untuk kasus itu Koreksi superadmin
tetap satu-satunya jalan — dan itu memang benar, karena membuka kembali naskah yang
sudah diarsipkan menyentuh laporan.

### 5.4 Gerbang maju dari Revisi (K8)

`advance()` dari `revisi` ditolak bila ada putaran `stage='revisi'` milik judul itu
dengan `closed_at IS NULL` yang **punya** berkas `revisi_minta` tapi **belum punya**
`revisi_hasil` yang sah (§5.2).

Pesannya menyebut nomor putarannya, bukan sekadar "tidak bisa maju":

> Putaran revisi ke-2 belum dijawab. Unggah hasil revisi, atau tutup putarannya
> dengan catatan.

Jalan keluar: tombol **"Tutup putaran tanpa berkas"** (PJ, wajib `close_note`).
Mengisi `closed_at`/`closed_by`/`close_note` dan mencatat di riwayat naskah.

**Penutupan otomatis.** Saat naskah maju melewati tahap sebuah putaran dengan sah,
putaran itu ditutup sendiri: `closed_at = now()`, `closed_by` = orang yang memajukan,
`close_note = null`. `close_note` yang kosong itulah yang membedakan penutupan wajar
dari penutupan paksa lewat pintu darurat — dan bedanya perlu terbaca di riwayat.

### 5.5 Berkas yang gagal diunggah

`ManuscriptFileService::majukanTahapSetelahUnggah()` memajukan tahap otomatis untuk
slot tertentu. Slot `revisi_minta` dan `revisi_hasil` **tidak** ikut memajukan tahap —
mengunggah jawaban revisi tak boleh diam-diam mendorong naskah ke LoA. Yang memajukan
tetap tombol PJ.

### 5.6 Notifikasi

Yang sudah ada, `naskahTahapBerubah()`, punya dua kekurangan untuk pekerjaan ini:

1. Judulnya selalu `'Naskah maju ke ' . label` — salah untuk perpindahan mundur.
   Ditambah cabang ketiga: `'Naskah dikembalikan ke '` saat targetnya di belakang asal.
2. Penerimanya hanya superadmin + PJ. **Pelaksana tidak termasuk** — padahal dialah
   yang dituju. Ditambahkan `$progress->pelaksana` ke daftar penerima untuk
   perpindahan mundur dan pembukaan putaran.

Notifikasi baru `naskahRevisiDiminta(ManuscriptRevision $putaran, User $actor)` ke
`assigned_to`, mengikuti bentuk `naskahDistribusi()` yang sudah ada.

---

## 6. Tampilan

### 6.1 Kartu Revisi di `/naskah/{id}`

Muncul bila tahap naskah `revisi` (artikel), atau bila judul itu punya putaran mana pun
(supaya riwayat tetap terbaca sesudah naskah melewatinya).

Judul kartunya mengikuti `stage` putaran, bukan dipatok "REVISI": putaran `revisi`
tampil sebagai **Revisi**, putaran `pembuatan` sebagai **Dikembalikan ke Pembuatan**.
Buku hanya akan pernah melihat yang kedua. Kartu berjudul "Revisi" pada buku — yang tak
punya tahap revisi sama sekali — adalah cara membuat orang meragukan seluruh layarnya.

```
┌─ REVISI ─────────────────────────────────────────┐
│ Putaran 2 · dibuka dari LoA · 22 Agu             │
│ Diminta: Rina (PJ)  →  Ditujukan: Budi           │
│ "Reviewer minta metodologi bab 3 diperjelas"     │
│                                                  │
│ Permintaan (2 berkas)                            │
│   reviewer-notes.pdf              ✓  [buka]      │
│   naskah-markup.docx           antre …           │
│                                                  │
│ Hasil                                            │
│   — belum ada —                                  │
│   [ pilih berkas… ] [ Unggah hasil revisi ]      │
│                                                  │
│ [ Tutup putaran tanpa berkas ]         (PJ saja) │
├──────────────────────────────────────────────────┤
│ ▸ Putaran 1 · 12 Agu · selesai   (2 berkas)      │
└──────────────────────────────────────────────────┘
```

Putaran lama terlipat (`collapse`) dan hanya-baca. Ini yang memenuhi "file revisi lama
masih terlist" saat mundur dari LoA.

Kartu ditempatkan di kolom kiri `naskah/detail.blade.php`, di bawah `file-naskah`,
sebagai partial baru `naskah/partials/revisi.blade.php`. Ia **tidak** dititipkan ke
`aksi.blade.php` yang sudah 250+ baris.

### 6.2 Kartu Aksi

- Tombol `↩ Perlu Revisi` di tahap `editing` → **`↩ Kembalikan ke Pembuatan`**, wajib
  alasan, boleh melampirkan berkas. Muncul untuk artikel **dan buku** (menutup T2).
- Tombol baru `↩ Kembalikan ke Revisi` di tahap `loa`, wajib alasan, artikel saja.
- Formulir permintaan revisi di tahap `revisi` — tidak di kartu Aksi, tapi di kartu
  Revisi (§6.1), karena tempatnya bersebelahan dengan berkas yang dibicarakannya.

### 6.3 Papan Pelacakan

Kolom zona Produksi artikel jadi `pembuatan · editing · submit · revisi`, diturunkan
dari `ARTICLE_STAGES` (T4). Kartu di kolom Revisi diberi lencana jumlah putaran terbuka.

---

## 7. Route, izin, migrasi

### 7.1 Route & peta izin

Peta di `config/permissions.php` **wajib** diperbarui bersamaan — `EnforcePermission`
gagal-tertutup, jadi route yang tak dipetakan menghasilkan 403 dan suite merah.

| Route baru | Nama | Kelompok izin |
|---|---|---|
| `POST naskah/{id}/revisi/minta` | `naskah.revisi.minta` | `advance` |
| `POST naskah/{id}/revisi/hasil` | `naskah.revisi.hasil` | `upload` |
| `POST naskah/{id}/revisi/tutup` | `naskah.revisi.tutup` | `advance` |
| `POST naskah/{id}/kembalikan` | `naskah.kembalikan` | `advance` |

`naskah.revisi` yang lama **dihapus** dan digantikan `naskah.kembalikan`; barisnya di
kelompok `advance` ikut diganti.

`revisi.hasil` masuk kelompok `upload` (bukan `advance`) justru supaya Pelaksana bisa
menjawab — kelompok `upload` terbuka untuk semua role, `advance` tidak.

**Catatan penolakan.** `EnforcePermission` membalas submit form (non-GET) dengan
**redirect + flash error**, bukan 403 mentah — hanya request AJAX/JSON yang dapat 403.
Tes yang memagari penolakan harus meng-assert `assertRedirect()->assertSessionHas('error')`,
bukan `assertForbidden()`.

Seluruh unggahan putaran memakai aturan mime yang sama dengan jalur unggah naskah yang
sudah ada: `pdf,doc,docx,zip`, batas lewat `BatasUnggah::kb(20480)`.

### 7.2 Migrasi data

Urutan tahap adalah konstanta PHP, jadi memindahkannya tak butuh migrasi skema. Yang
butuh perhatian hanya baris yang sedang duduk di `revisi`.

**Periksa dulu di produksi:**

```sql
SELECT COUNT(*) FROM tb_title_progress WHERE status = 'revisi';
```

Bila **0** (seperti dev): tak ada backfill sama sekali.

Bila **> 0**, aturannya eksplisit — untuk tiap baris `revisi`, cari jejak `submit` di
`tb_title_progress_logs` (`to_value = 'submit'`):

- **ada jejak** → biarkan di `revisi`. Ia memang sudah pernah disubmit, jadi posisinya
  di urutan baru sudah benar.
- **tak ada jejak** → kembalikan ke `editing`. Di urutan lama ia belum pernah submit,
  dan di urutan baru `revisi` berarti "sudah submit" — membiarkannya di situ akan
  memajukan naskah satu tahap secara palsu.

Backfill memakai `DB::table()`, **bukan** model — migrasi lama yang meng-query model
sudah tiga kali memecahkan `migrate:fresh` di repo ini, dan gejalanya menyesatkan.

Tiap baris yang dipindahkan menulis satu baris `tb_title_progress_logs` dengan
`event='status_corrected'`, `note` menyebut migrasi ini. Perpindahan diam-diam tanpa
jejak adalah hal yang tak bisa ditelusuri enam bulan kemudian.

### 7.3 Setelah migrasi

`php artisan migrate` pada DB dev `avidpedi_simapa` — tabel baru tak akan ada di sana
kalau hanya suite yang dijalankan, dan aplikasi hidup akan 500.

---

## 8. Uji

Yang wajib punya tes, disusun menurut apa yang bisa gagal senyap:

| # | Tes | Menjaga |
|---|---|---|
| U1 | `advance()` dari `submit` mendarat di `revisi`, dari `revisi` mendarat di `loa` | K1 |
| U2 | Naskah di `revisi` tanpa putaran boleh langsung maju ke `loa` | K2 |
| U3 | Putaran dengan `revisi_minta` tanpa `revisi_hasil` **menahan** maju; pesannya menyebut nomor putaran | K8 |
| U4 | Menutup putaran dengan catatan membebaskan naskah untuk maju | K8, pintu darurat |
| U5 | **Mundur LoA→Revisi benar-benar memindahkan seluruh grup** — assert `status` tiap anggota, bukan hanya nilai kembalian | **T3.** Tanpa ini bug penjaga `applyGroup` lolos |
| U6 | Mundur membuka putaran ber-`round` baru; putaran lama tetap ada dan `closed_at`-nya tak berubah | K7 |
| U7 | Mundur ditolak bila naskah sudah diarsipkan / dibatalkan / final | Lantai §5.3 |
| U8 | Mundur tercatat `is_correction = false` di log | K6 |
| U9 | **Buku: "Kembalikan ke Pembuatan" mendarat di `pembuatan`, bukan `layout`** | **T2**, bug hari ini |
| U10 | Pelaksana (bukan PJ) boleh mengunggah `revisi_hasil`; marketing tidak boleh membuka putaran | §7.1 |
| U11 | Berkas `revisi_hasil` berstatus `antre` sudah menghitung sebagai terjawab; `gagal` tidak | §5.2 |
| U12 | Unggah `revisi_hasil` **tidak** memajukan tahap otomatis | §5.5 |
| U13 | Kolom zona Pelacakan artikel berurut `pembuatan · editing · submit · revisi` | **T4** |
| U14 | Backfill: baris `revisi` tanpa jejak `submit` pindah ke `editing`; yang ada jejaknya tetap | §7.2 |

Mock `GoogleDriveService` **lewat container** (`$this->mock`), bukan konstruktor —
pernah membuat tes mengunggah ke Drive sungguhan.

Jalankan dengan `--filter`, bukan suite penuh tiap kali. Dan pastikan tak ada sesi lain
yang sedang menjalankan tes terhadap `avidpedi_simapa_test` — kegagalan palsu dari dua
proses yang berbagi satu DB uji sudah pernah menghabiskan waktu di repo ini.

---

## 9. Yang sengaja ditinggalkan

- **Status `ditolak`** (K9). Butuh jawaban soal uang lebih dulu: order dari naskah yang
  ditolak masuk keadaan apa? `fulfillment_status = 'selesai'` jelas salah, dan memakai
  ulang `ditarik` dengan arti berbeda adalah persis kesalahan yang K3 audit dibuat untuk
  mencegah. Tetap di backlog.
- **Mundur dari tahap mana pun.** Hanya dua pasangan yang dibolehkan (§5.3). Mekanisme
  umum "mundur satu tahap di mana saja" dipertimbangkan dan ditolak: ia butuh lantai
  pengaman di banyak tempat dan tak ada yang memintanya.
- **Perapian folder Google Drive.** Spec terpisah. Berkas `revisi_minta`/`revisi_hasil`
  yang lahir di sini akan mendarat di folder root yang sama berantakannya dengan berkas
  naskah lain sampai pekerjaan itu dikerjakan — diketahui, bukan terlewat.
- **Putaran revisi per bab untuk buku.** `title_chapter_id` disediakan di skema supaya
  tak perlu migrasi kedua, tapi tak ada UI yang mengisinya di pekerjaan ini.
