# Perapian Folder Google Drive per Judul

**Tujuan:** Berkas naskah berhenti tertumpuk rata dalam satu folder dan tersusun per
judul, dengan kategori yang menerangkan isinya.

**Lingkup:** Empat titik unggah yang hari ini mengabaikan folder, ditambah satu perintah
pemindah untuk berkas yang sudah telanjur tersebar. Tak menyentuh alur kerja, izin, atau
tampilan.

---

## 1. Keadaan sekarang

### 1.1 Dua lokasi terpisah, bukan satu folder berantakan

`GoogleDriveService::getOrCreateFolderByPath()` selalu mulai dari **root Google Drive**:

```php
$parentId = null; // Mulai dari root
...
$query .= " and 'root' in parents";
```

`GOOGLE_DRIVE_FOLDER_ID` tak pernah dilihatnya. Sementara `uploadFile($file, null)`
justru jatuh ke folder itu:

```php
$configFolder = config('filesystems.disks.google.folderId');
```

Akibatnya berkas aplikasi terbelah dua tempat yang tak berhubungan:

| Di root Google Drive (terstruktur) | Di folder aplikasi (rata, tanpa struktur) |
|---|---|
| `Application/struk_pembayaran/{tahun}` | seluruh berkas naskah |
| `Application/Refunds/{tahun}` | seluruh berkas ISBN |
| `Application/SalarySlips/{tahun}` | LoA & bukti bayar jurnal |
| `Application/ServiceInvoices/{tahun}` | kelengkapan dokumen |
| `SiMAPA/Reports/{user}/{bulan}` | artefak arsip |

Yang dikeluhkan adalah kolom kanan — dan kolom kanan itu justru berisi berkas yang
paling sering dicari orang.

### 1.2 Empat titik yang mengunggah tanpa folder

| Berkas | Baris | Isi |
|---|---|---|
| `app/Jobs/UnggahBerkasKeDrive.php` | 49 | **seluruh berkas naskah & ISBN** |
| `app/Http/Controllers/Pages/JournalSubmissionController.php` | 78, 81 | LoA, bukti bayar |
| `app/Services/DocChecklistService.php` | 36 | kelengkapan dokumen |
| `app/Services/TitleArchivalService.php` | 177 | artefak arsip |

Keempatnya **sudah memegang judulnya** — `title_id` selalu ada di tangan pemanggil.
Itu yang membuat satu helper cukup untuk melayani semuanya.

### 1.3 Kode judul tak cukup jadi nama folder

**Koreksi atas dugaan awal.** Saat merancang, alasan yang diberikan adalah "dua judul
bisa berkode sama". **Itu salah** — `tb_titles.code` ternyata punya *unique index*, dan
basis data sudah mencegahnya. Ketahuan saat tes mencoba menyisipkan dua kode kembar dan
ditolak MariaDB.

Alasan sebenarnya tetap berlaku, dan justru lebih kuat: **kode boleh kosong.** 7 dari 75
judul produksi belum punya kode, dan unique index mengizinkan banyak `NULL`. Tanpa id,
semua judul tanpa kode akan berebut satu nama folder yang sama persis — dan berkas
mereka benar-benar bercampur.

Satu jebakan yang ikut tersingkap: kolase PADSPACE MariaDB menganggap `''` dan `'   '`
nilai yang **sama**, sehingga dua judul berkode "kosong tapi berisi spasi" bertabrakan di
unique index. Nama folder karena itu diturunkan dari `trim()`, bukan dari nilai mentahnya.

---

## 2. Keputusan yang dikunci

| # | Keputusan | Alasan |
|---|---|---|
| K1 | Nama folder judul = **`{KODE}-{id}`** (mis. `MAMT-1`); tanpa kode → `TANPA-KODE-{id}` | Kode di depan supaya terbaca dan bisa dicari; id memisahkan judul-judul **tanpa kode**, yang tanpanya akan berebut satu nama yang sama (lihat koreksi §1.3). Id tak pernah berubah, jadi nama tak perlu diperbaiki saat judul disunting |
| K2 | Dua lapis: kategori, lalu jenis untuk berkas naskah | Nama berkas aslinya sering tak menerangkan apa pun ("final.docx"); foldernyalah yang menerangkan |
| K3 | Pohon baru bercabang dari **folder aplikasi** (`GOOGLE_DRIVE_FOLDER_ID`), bukan root Drive | Di situlah berkas naskah sudah berada hari ini; memindahkannya ke root justru menambah tempat ketiga |
| K4 | Id folder judul **disimpan di `tb_titles.drive_folder_id`** | Tanpa itu tiap unggahan menelusuri ulang seluruh lapis folder — beberapa panggilan API hanya untuk mencari tempat menaruh berkas |
| K5 | Berkas lama dipindahkan lewat **perintah artisan opsional**, bukan migrasi | Memindahkan ratusan berkas bisa gagal di tengah. Itu harus tindakan sadar yang bisa diintip lebih dulu (`--dry-run`), bukan efek samping deploy |
| K6 | `getOrCreateFolderByPath()` lama **tidak diubah perilakunya** | Delapan pemanggil bergantung padanya mulai dari root Drive. Ditambah parameter opsional, bukan diubah maknanya |

---

## 3. Susunan folder

```
<GOOGLE_DRIVE_FOLDER_ID>/
    MAMT-1/                     ← {KODE}-{id}
        Naskah/
            Masuk/
            Hasil Editing/
            Revisi/
            Final/
            Layout/             ← buku saja
            Proofread/          ← buku saja
            Cover/              ← buku saja
        Berkas ISBN/
        Jurnal/
        Kelengkapan Dokumen/
        Arsip/
```

Folder dibuat **malas** — hanya saat ada berkas yang benar-benar masuk ke situ. Judul
yang tak pernah punya berkas ISBN tak meninggalkan folder kosong.

### 3.1 Peta slot → folder

| Slot / sumber | Folder |
|---|---|
| `masuk` | `Naskah/Masuk` |
| `hasil_editing` | `Naskah/Hasil Editing` |
| `revisi_minta`, `revisi_hasil` | `Naskah/Revisi` |
| `final` | `Naskah/Final` |
| `hasil_layout` | `Naskah/Layout` |
| `hasil_proofread` | `Naskah/Proofread` |
| `cover` | `Naskah/Cover` |
| `loa` | `Jurnal` |
| `ebook`, `barcode_isbn`, `sertifikat_hki` | `Berkas ISBN` |
| LoA & bukti bayar dari modul jurnal | `Jurnal` |
| Kelengkapan dokumen | `Kelengkapan Dokumen` |
| Artefak arsip | `Arsip` |

Slot yang tak terpetakan mendarat di folder judul langsung, bukan gagal. Berkas yang
salah tempat masih lebih baik daripada berkas yang tak terunggah.

---

## 4. Berkas

**Dibuat:**

| Berkas | Tanggung jawab |
|---|---|
| `database/migrations/2026_08_22_000004_add_drive_folder_id_to_titles.php` | Kolom penyimpan id folder judul |
| `app/Services/DriveJudulFolderService.php` | Menerjemahkan judul + slot jadi id folder Drive |
| `app/Console/Commands/DriveRapikan.php` | `simapa:drive-rapikan --dry-run｜--apply` |
| `tests/Feature/DriveFolderJudulTest.php` | |

**Diubah:**

| Berkas | Perubahan |
|---|---|
| `app/Services/GoogleDriveService.php` | `getOrCreateFolderByPath($path, ?string $rootId = null)`; tambah `moveFile()` |
| `app/Jobs/UnggahBerkasKeDrive.php` | Mengunggah ke folder slotnya |
| `app/Http/Controllers/Pages/JournalSubmissionController.php` | Ke `Jurnal` |
| `app/Services/DocChecklistService.php` | Ke `Kelengkapan Dokumen` |
| `app/Services/TitleArchivalService.php` | Ke `Arsip` |
| `app/Models/Title.php` | `drive_folder_id` di `$fillable` |

---

## 5. Aturan

### 5.1 Kegagalan folder tak boleh menggagalkan unggahan

Bila Drive gagal membuat atau menemukan foldernya, berkas tetap diunggah ke folder
aplikasi seperti hari ini. **Berkas yang salah tempat masih jauh lebih baik daripada
berkas yang hilang** — dan orang yang mengunggah naskah 20 MB tak boleh ditolak karena
persoalan tata folder.

Kegagalan folder dicatat di log, tidak dilempar.

### 5.2 Nama folder yang basi dibiarkan

Folder ditemukan lewat `drive_folder_id` yang tersimpan, bukan lewat namanya. Jadi
kode judul yang disunting kemudian **tidak** merusak apa pun — hanya namanya di Drive
yang jadi tak sesuai. Mengganti nama folder tiap kali kode berubah berarti panggilan API
tambahan pada jalur yang tak ada hubungannya dengan Drive; ditinggalkan dengan sadar.

### 5.3 Perintah pemindah

```
php artisan simapa:drive-rapikan --dry-run     # tampilkan rencananya, tak mengubah apa pun
php artisan simapa:drive-rapikan --apply       # jalankan
php artisan simapa:drive-rapikan --apply --judul=1,2,3
```

`--dry-run` adalah **bawaan**: menjalankan perintah tanpa argumen apa pun tidak boleh
memindahkan satu berkas pun. Perintah yang berbahaya secara bawaan adalah perintah yang
suatu saat dijalankan orang yang cuma ingin melihat.

Berkas dipindah dengan mengubah induknya (`addParents`/`removeParents`), bukan diunggah
ulang: id dan URL berkas tak berubah, sehingga seluruh tautan yang sudah tersimpan di
basis data tetap hidup.

Yang dilewati dicatat beserta sebabnya: berkas tanpa `drive_file_id` (masih antre atau
gagal), berkas yang judulnya sudah terhapus, dan berkas yang sudah berada di tempat yang
benar.

---

## 6. Uji

| # | Tes | Menjaga |
|---|---|---|
| U1 | Nama folder judul = `{KODE}-{id}` | K1 |
| U2 | Judul tanpa kode dapat `TANPA-KODE-{id}` | K1 |
| U3 | Tiap slot memetakan ke folder yang benar | §3.1 |
| U4 | Slot tak dikenal mendarat di folder judul, bukan melempar | §3.1 |
| U5 | Id folder judul disimpan dan **dipakai ulang**, tak dicari dua kali | K4 |
| U6 | Kegagalan Drive saat mencari folder **tidak** menggagalkan unggahan | **§5.1** |
| U7 | `getOrCreateFolderByPath()` tanpa `$rootId` tetap mulai dari root Drive | **K6** — delapan pemanggil lama bergantung padanya |
| U8 | `--dry-run` tak memindahkan apa pun; tanpa argumen berperilaku sebagai dry-run | **§5.3** |
| U9 | Berkas tanpa `drive_file_id` dilewati, bukan menggagalkan seluruh perintah | §5.3 |

Mock `GoogleDriveService` lewat container, tak pernah lewat konstruktor.

---

## 7. Yang sengaja ditinggalkan

- **Memindahkan `Application/...` dan `SiMAPA/Reports/...` dari root Drive.** Keduanya
  sudah terstruktur dan tak pernah dikeluhkan. Memindahkannya menyentuh berkas keuangan
  demi kerapian belaka.
- **Mengganti nama folder saat kode judul disunting** (§5.2).
- **Folder per bab** untuk buku kolaborasi. `tb_manuscript_files.title_chapter_id` ada,
  tapi tak ada yang meminta pemisahan sedalam itu.
