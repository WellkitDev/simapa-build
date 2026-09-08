# Lampiran Tugas — Berkas untuk Dua Pihak, dan Kunci yang Dipersempit

**Tanggal:** 2026-09-08 · **Branch usulan:** `feat/lampiran-tugas`

**Tujuan:** Sebuah tugas bisa membawa berkasnya sendiri — acuan dari yang memberi,
hasil dari yang mengerjakan — tanpa harus menitipkannya ke Laporan Harian, dan tanpa
dibekukan oleh hari yang kebetulan sudah ditutup.

**Tidak termasuk:** snapshot beku Laporan Harian (§8.1, utang teknis), unggahan lewat
antrean job, lampiran pada Papan/Kalender, versi berkas.

---

## 1. Latar

### 1.1 Keadaan sekarang, diverifikasi 2026-09-08

Modul Tugas **tak punya unggahan berkas sama sekali**. Bukan tersembunyi di balik izin —
memang tak ada:

| Lapis | Bukti |
|---|---|
| Skema | `tb_tasks` dan `tb_task_updates` tak punya satu pun kolom berkas |
| Route | `routes/web.php:232-248` — 12 route tugas, tak ada yang menerima berkas |
| Controller | `TaskController` tak menyentuh `GoogleDriveService` maupun `UploadedFile` |
| View | `grep -iE "file|lampiran|unggah|dropzone" resources/views/tasks/` → nol hasil |

Satu-satunya tempat berkas mendarat hari ini adalah **Laporan Harian**
(`DailyReportController::storeFile()` → Drive `SiMAPA/Reports/{userId}/{Y-m}` →
`tb_daily_report_files`), dan di sana bukti bahkan **wajib minimal 1** sebelum report
bisa dikirim.

### 1.2 Kenapa ada kunci pada tugas, dan apa yang sebenarnya ia lindungi

`TaskService::isLocked()` membekukan tugas `done` yang tanggal `completed_at`-nya sudah
punya Laporan Harian ber-status `submitted`. Ia terlihat seperti aturan bisnis tentang
tugas. Ia bukan.

`DailyReportService::recapFor()` membaca `tb_tasks` **langsung, setiap kali halaman
dibuka** — tak ada snapshot. Spec aslinya menuliskan itu sebagai keputusan sadar:

> `2026-06-24-task-reports-design.md:7` — *"Di luar scope (sengaja): **snapshot beku saat
> submit**…"*

Tanpa snapshot, mengubah tugas hari ini akan diam-diam mengubah laporan yang dikirim
kemarin. Kunci itulah tambalannya. **Kunci tugas adalah harga yang dibayar untuk snapshot
yang tak pernah dibuat** — dan yang membayar adalah modul yang berbeda.

### 1.3 Empat temuan yang membentuk pekerjaan ini

| | Temuan | Akibat pada rancangan |
|---|---|---|
| **T1** | **Kunci jauh lebih lebar dari yang dilindunginya.** Yang benar-benar tampil di Laporan Harian hanyalah judul dan prioritas per tugas (`reports/daily.blade.php:45-46`) plus hitungannya. Utas laporan, `progress`, dan lampiran **tak pernah muncul di sana** | Membebaskan utas + lampiran dari kunci tidak melemahkan laporan harian sedikit pun. Yang dibebaskan memang tak pernah ia tampilkan |
| **T2** | **Kuncinya sudah setengah bocor hari ini.** `abortIfLocked()` hanya dipasang di `update`/`destroy`/`status`/`schedule` (baris 184, 223, 232, 255) — **`report()` tidak**. Server sudah menerima laporan ke tugas terkunci; yang menutupnya cuma `show.blade.php:112`. Sementara papan tetap menampilkan tombol "Lapor" yang menuju formulir yang menghilang | Bagian "bebaskan utas" adalah **perapian view**, bukan pelonggaran gerbang. Ukurannya jauh lebih kecil dari yang diduga |
| **T3** | **Kunci memakai Laporan Harian PELAKSANA** — `DailyReport::where('user_id', $task->user_id)`. Pemberi tugas yang tak punya hubungan apa pun dengan laporan harian itu ikut terkunci dari tugasnya sendiri | Untuk fitur dua-pihak ini jelas salah alamat. Diperbaiki lewat penyempitan cakupan kunci, bukan dengan menambah pengecualian per-peran |
| **T4** | **Terkunci berarti beku selamanya.** Tak ada route un-submit laporan harian (`report.submit` ada, kebalikannya tidak), dan mengembalikan status dari `done` juga diblokir kunci yang sama | Kalau batas unggah ditaruh di `done`, tugas yang terlanjur selesai + laporan harian terkirim **tak akan pernah bisa punya lampiran**. Inilah sebab K1 di §2 |

### 1.4 Risiko data

Hitungan di DB **dev**, 2026-09-08:

```
tugas=0  done=0  terkunci=0  laporan_submitted=0
```

Nol tugas — wajar, DB dev dibangun ulang lewat `simapa:import-v1` yang tak membawa
`tb_tasks`. Artinya dev **tidak bisa dipakai** untuk memperkirakan dampak.

**Wajib dihitung ulang di produksi sebelum dikerjakan:**

```sql
SELECT COUNT(*) FROM tb_tasks t
JOIN tb_daily_reports r ON r.user_id = t.user_id AND DATE(t.completed_at) = r.report_date
WHERE t.status = 'done' AND r.status = 'submitted';
```

Angka itu = jumlah tugas yang hari ini beku permanen, dan yang akan langsung hidup
kembali (untuk utas & lampiran, bukan untuk syaratnya) begitu §3 dikerjakan.

---

## 2. Keputusan yang mengikat

Diputuskan dalam percakapan 2026-09-08. Ditulis di sini supaya tak perlu diputuskan dua
kali, dan supaya yang membacanya nanti tahu ini pilihan, bukan kebetulan.

- **K1 — Batas unggah ada di KUNCI LAPORAN HARIAN, bukan di status `done`.**
  Lampiran boleh masuk kapan saja sepanjang umur tugas, termasuk sesudah tugas selesai,
  sampai hari penyelesaiannya benar-benar ditutup laporan harian.
  *Sebab:* menaruh batas di `done` membuat pintu satu arah — orang menggeser kartu ke
  Selesai lebih dulu, baru ingat berkasnya (T4). Ongkos yang diterima sadar: `done` tak
  berarti "berkas final".

- **K2 — Dua pihak sama-sama boleh mengunggah dan menghapus.**
  Gerbangnya `Task::bolehDibaca()` yang sudah ada (pelaksana, pemberi tugas,
  manager/superadmin) — bukan `bolehDikelola()`. Melampirkan berkas adalah *mengerjakan*
  tugas, bukan *mengubah syaratnya*.

- **K3 — Selalu opsional.** Tak ada tugas yang gagal disimpan atau gagal diselesaikan
  karena tak punya lampiran. Banyak tugas memang tak berbentuk berkas.

- **K4 — Lampiran tugas dihitung sebagai bukti Laporan Harian**, pada tanggal
  `completed_at` tugas itu. Berkas yang dilampirkan 1 Sep pada tugas yang selesai 5 Sep
  menghitung untuk **5 Sep**.
  *Sebab:* prinsip pendiri modulnya sendiri —
  `2026-06-24-task-reports-design.md:5`: *"Nol input ganda."* Memaksa berkas yang sama
  diunggah dua kali melanggarnya.

- **K5 — Snapshot Laporan Harian ditunda**, dicatat sebagai utang teknis (§8.1).

---

## 3. Langkah 1 — Persempit kunci

`isLocked()` **tidak diubah**; yang diubah adalah siapa yang mematuhinya.

| Perbuatan | Sekarang | Sesudah |
|---|---|---|
| `update` / `destroy` / `schedule` / `status` | terkunci | **tetap terkunci** — inilah yang tampil di laporan harian |
| `report` (tulis laporan) | server: bebas · view: tertutup | **bebas, dan view mengikuti** |
| unggah / hapus lampiran | — | **bebas sampai tugas terkunci** (K1) |

Perubahannya:

- `tasks/show.blade.php` — `$terkunci` berhenti menyembunyikan formulir laporan. Sebagai
  gantinya kartu Keadaan menampilkan keterangan: *"Syarat tugas ini sudah dikunci laporan
  harian yang terkirim — judul, prioritas, tenggat, dan statusnya tak bisa diubah lagi.
  Kamu masih bisa melapor dan melampirkan berkas."* Kalimat buntu yang sekarang
  (`show.blade.php:139`) dibuang.
- `tasks/board.blade.php` — tombol "Lapor" pada kartu terkunci tetap ada (sudah benar,
  T2), tapi ikon gembok diberi `title` yang menjelaskan apa yang terkunci.
- `TaskController` — **tak ada perubahan gerbang.** `abortIfLocked()` tetap persis di
  empat tempatnya.

Ini sekaligus menutup T3: pemberi tugas tak lagi kehilangan utas karena laporan harian
orang lain.

---

## 4. Langkah 2 — Lampiran tugas

### 4.1 `tb_task_files`

Meniru `tb_daily_report_files` yang sudah terbukti, ditambah satu kolom.

| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | bigint PK | |
| `task_id` | FK → `tb_tasks`, cascadeOnDelete | |
| `drive_file_id` | string | |
| `name` | string | nama asli |
| `url` | string(1024) | tautan Drive |
| `mime` | string, nullable | |
| `size` | unsignedBigInteger, nullable | byte |
| `uploaded_by` | FK users, nullable nullOnDelete | **siapa dari dua pihak** yang mengunggah |
| timestamps | | |

Indeks `(task_id, id)`.

Kolom `uploaded_by` yang membedakan "acuan dari pemberi" dan "hasil dari pelaksana" —
tanpa kolom peran terpisah, karena perannya sudah terbaca dari `task.user_id` vs
`task.created_by`. Menyimpannya dua kali berarti dua sumber yang bisa berselisih.

### 4.2 Route (wajib didaftarkan di `config/permissions.php`)

```
POST   tasks/{id}/berkas        task.files.store
DELETE tasks/berkas/{fileId}    task.files.destroy
```

Keduanya masuk daftar **`public`** di `config/permissions.php`, sebaris dengan
`task.show` dan `task.report` — yang menjaganya `bolehDibaca()`, bukan izin peran.
**Tanpa pendaftaran ini `EnforcePermission` menolak fail-closed** dan test langsung
merah (lihat [[access-control-permission-map]]).

### 4.3 Perilaku

- **Unggah** — `authorizeTask()` (K2) → tolak bila `isLocked()` (K1) → validasi
  `file` dengan `mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx` dan
  `max:` **`BatasUnggah::kb(10240)`** — jangan angka tetap, atau pesan gagalnya
  menyesatkan ([[batas-unggah]]) → `getOrCreateFolderByPath('SiMAPA/Tugas/' . $task->id)`
  → `uploadFile()` → simpan baris → **catat entri sistem di utas**
  (`TaskThreadService::catat()`: *"Melampirkan `nama-berkas.pdf`."*).
  Sinkron, bukan lewat job — berkas sebesar ini sudah ditangani begitu di Laporan Harian.
- **Hapus** — pengunggahnya sendiri, atau manager/superadmin. Ditolak bila terkunci.
  Baris dihapus, berkas Drive dihapus (kegagalan Drive hanya di-`Log::warning`, tak
  menggagalkan permintaan — sama seperti `destroyFile()`), dan **entri sistem dicatat**.
  Utas tetap append-only: yang hilang berkasnya, bukan jejaknya.
- **Notifikasi** — tak ada notifikasi baru. Unggahan yang menyertai laporan sudah ikut
  `taskDilaporkan()`; unggahan telanjang tak cukup penting untuk membangunkan orang.

### 4.4 UI

`tasks/show.blade.php`, kartu **Berkas** di kolom kiri, di bawah kartu Laporan:

- Dropzone (`assets/plugins/dropzone/`, sudah ada) saat tugas belum terkunci; daftar
  tautan read-only saat terkunci.
- Tiap baris: nama berkas, pengunggah, waktu, tombol hapus (hanya untuk yang berhak).
- Kosong → *"Belum ada berkas dilampirkan. Opsional — tak semua tugas berbentuk berkas."*
  Kalimat itu yang mencegah orang mengira dirinya lupa sesuatu.
- Papan (`board.blade.php`) & daftar (`index.blade.php`): lencana **📎 n** di sebelah
  lencana laporan, hanya bila `n > 0`. Tanpa ini lampiran tak terlihat tanpa membuka
  detail. Pakai `withCount`, jangan `with` — kartu cuma butuh angkanya.

---

## 5. Langkah 3 — Jembatan ke bukti Laporan Harian (K4)

- `DailyReportService` — satu kueri grouped yang menghitung `tb_task_files` milik tugas
  yang `completed_at`-nya jatuh pada tanggal itu, untuk user itu. Hindari N+1.
- `DailyReportController::submit()` — syarat "minimal 1 bukti" terpenuhi bila
  `files()->count() + lampiranTugasHariItu > 0`.
- `reports/daily.blade.php` — kartu rekap "Selesai hari ini" menampilkan lencana
  **📎 n** yang **menaut ke `task.show`**. Berkasnya **tidak disalin** ke laporan harian;
  ia ditunjuk. Menyalin akan melahirkan dua kebenaran atas satu berkas.
- Keterangan "Wajib minimal 1" (`daily.blade.php:90`) berubah jadi: *"Wajib minimal 1
  bukti — lampiran pada tugas yang selesai hari ini ikut dihitung."*

---

## 6. Test

TDD, satu per perilaku. `TaskControllerTest` sudah mem-`mock(GoogleDriveService::class)`
lewat container di `setUp()` — **pertahankan jalur itu**, jangan menyuntik lewat
konstruktor, atau test benar-benar mengunggah ke Drive ([[unggahan-antre]]).

| # | Perilaku |
|---|---|
| 1 | Tugas terkunci: `task.report` **200** dan entri tercatat (menutup T2) |
| 2 | Halaman detail tugas terkunci tetap menampilkan formulir laporan |
| 3 | Pelaksana mengunggah → baris `tb_task_files` + entri sistem di utas |
| 4 | Pemberi tugas mengunggah pada tugas yang sama → juga boleh (K2) |
| 5 | Orang luar mengunggah → **403** |
| 6 | Tugas `done` tapi belum terkunci → unggah **boleh** (K1, inti keputusannya) |
| 7 | Tugas terkunci → unggah **422** |
| 8 | Hapus oleh pengunggah → baris hilang, entri sistem tercatat; oleh orang lain → 403 |
| 9 | `submit` laporan harian tanpa bukti tapi ada lampiran tugas selesai hari itu → **submitted** (K4) |
| 10 | Lampiran pada tugas yang selesai **hari lain** tidak menghitung |
| 11 | Route baru terdaftar di `config/permissions.php` (test peta izin yang sudah ada akan menangkapnya bila lupa) |

Jalankan dengan `--filter`, bukan full-suite ([[testing-setup]]).

---

## 7. Berkas yang disentuh

**Baru:** migrasi `tb_task_files` · `app/Models/TaskFile.php` ·
`app/Services/TaskFileService.php` (unggah/hapus + pencatatan utas) ·
test di `TaskControllerTest`.

**Diubah:** `routes/web.php` · `config/permissions.php` ·
`app/Http/Controllers/Pages/TaskController.php` (2 aksi baru) · `app/Models/Task.php`
(relasi `files()`) · `app/Services/TaskService.php` (`withCount('files')` di `board()`) ·
`app/Services/DailyReportService.php` + `DailyReportController::submit()` (K4) ·
`resources/views/tasks/{show,board,index}.blade.php` ·
`resources/views/reports/daily.blade.php`.

**Tidak disentuh:** `TaskService::isLocked()`, `TaskThreadService`, empat pemanggilan
`abortIfLocked()` yang ada.

---

## 8. Sengaja tidak dikerjakan

### 8.1 Snapshot beku Laporan Harian — utang teknis

Akar sebenarnya (§1.2) tetap ada: laporan harian yang sudah dikirim masih membaca
`tb_tasks` secara live. Pekerjaan ini hanya memastikan tugas tak lagi membayar ongkosnya
lebih dari yang perlu.

Perbaikan sesungguhnya: kolom snapshot (JSON) yang diisi saat `submit`, dibaca oleh
`recapFor()` bila `submitted` — lalu kunci tugas **bisa dibuang seluruhnya**. Kerjakan
bila laporan harian nanti perlu dipertanggungjawabkan sebagai dokumen resmi.

### 8.2 Lain-lain

- Antrean job untuk unggahan tugas — berkasnya kecil; ikuti pola Laporan Harian.
- Versi berkas (v1, v2…) — itu urusan naskah, bukan tugas.
- Berkas Drive yatim saat tugas dihapus — penyakit yang sama sudah dimiliki
  `tb_daily_report_files`. Diulangi dengan sadar, bukan diam-diam; pembersihannya satu
  perintah artisan tersendiri kalau nanti terasa.
