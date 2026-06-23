# Spec — Pengumuman ter-pin di Dashboard

- **Tanggal:** 2026-06-23
- **Branch:** `pengumuman`
- **Scope:** Pengumuman yang dibuat admin (superadmin/manager/admin) dan tampil sebagai slider kartu di dashboard SEMUA role, dengan badge "Baru" gabungan (per-user + recency) dan durasi auto-slide menurut panjang teks.
- **Di luar scope (sengaja):** jadwal tampil otomatis (mulai/berakhir), tipe/level (info/penting) warna badge, unggah lampiran/gambar, komentar/reaksi.

---

## 1. Latar Belakang

Roadmap mencantumkan "Pengumuman pinned di dashboard untuk semua role". Project sudah punya pola yang relevan: notifikasi in-app (pelacakan dibaca per-user), View Composer untuk navbar, DataTables untuk list, role middleware. Editor Summernote belum ada (yang terpasang TinyMCE, tak terpakai) — Summernote ditambah via CDN khusus halaman admin.

## 2. Tujuan & Kriteria Sukses

1. Superadmin/manager/admin bisa CRUD pengumuman (judul + isi rich-text + status + pin).
2. Pengumuman ber-status **Terbit** tampil sebagai slider kartu di dashboard untuk semua role.
3. Badge **"Baru"** muncul bila pengumuman masih baru (≤ 3 hari) **dan** belum dilihat user tsb; hilang setelah dilihat / lewat 3 hari.
4. Slider auto-geser dengan durasi per-kartu menurut panjang teks; jeda saat hover; ada panah & indikator.
5. Role non-admin tidak bisa membuka halaman admin pengumuman (403).
6. Semua perilaku tertutup test; suite tetap hijau.

---

## 3. Desain

### 3.1 Data model

**`tb_announcements`**
| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | bigint PK | |
| `title` | string | judul |
| `body` | longText/text | HTML dari Summernote |
| `status` | string(16), default `draft` | `draft` / `published` / `archived` |
| `is_pinned` | boolean, default false | yang di-pin tampil paling depan |
| `published_at` | datetime, nullable | di-set saat pertama jadi `published`; dasar recency "Baru" |
| `created_by` | FK users, nullable nullOnDelete | |
| timestamps | | |

**`tb_announcement_reads`**
| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | bigint PK | |
| `announcement_id` | FK → tb_announcements, cascadeOnDelete | |
| `user_id` | FK → users, cascadeOnDelete | |
| `read_at` | datetime | |
| timestamps | | |

**Unik** `(announcement_id, user_id)`.

Model `Announcement`: fillable (title, body, status, is_pinned, published_at, created_by); casts `is_pinned`→boolean, `published_at`→datetime; const `NEW_DAYS = 3`; relasi `creator()` belongsTo User, `reads()` hasMany AnnouncementRead. Model `AnnouncementRead`: fillable (announcement_id, user_id, read_at); cast read_at→datetime.

### 3.2 `AnnouncementService` (`app/Services/AnnouncementService.php`)

- **`forDashboard(User $user): \Illuminate\Support\Collection`** → pengumuman `status = published`, diurut `is_pinned` desc lalu `published_at` desc. Tiap item dipetakan jadi array siap-tampil: `id, title, body, published_at, creator_name, is_new`. `is_new` = `published_at >= now()->subDays(Announcement::NEW_DAYS)` **dan** tidak ada baris read untuk (announcement, user). Status read di-resolusi dengan SATU query (ambil id yang sudah dibaca user, lalu cek) — hindari N+1.
- **`markSeen(User $user, array $ids): void`** → untuk tiap id yang valid (pengumuman published), `firstOrCreate(['announcement_id'=>id,'user_id'=>user], ['read_at'=>now()])`. Idempotent.
- **`publish(Announcement $a)` / `archive(Announcement $a)`** → ubah status; `publish` meng-set `published_at = now()` bila belum pernah (sekali set, agar recency stabil).

### 3.3 Halaman admin "Pengumuman" (superadmin / manager / admin)

Route (grup `auth`, `role:superadmin|manager|admin`):

| Route | Nama | Aksi |
|---|---|---|
| `GET /announcements` | `announcement.index` | list (DataTables) |
| `GET /announcements/create` | `announcement.create` | form buat |
| `POST /announcements` | `announcement.store` | simpan |
| `GET /announcements/{id}/edit` | `announcement.edit` | form edit |
| `PUT /announcements/{id}` | `announcement.update` | perbarui |
| `DELETE /announcements/{id}` | `announcement.destroy` | hapus |
| `POST /announcements/{id}/status` | `announcement.status` | set status (param `status` in:`published`,`archived`) |

Controller `App\Http\Controllers\Pages\AnnouncementController`.

- **List**: Judul · Status (badge: Draf abu / Terbit hijau / Arsip sekunder) · Pin (ikon) · Dibuat oleh · Tanggal · Aksi (Edit, Hapus, tombol "Terbitkan" bila belum published / "Arsipkan" bila published — keduanya memanggil `announcement.status` dengan target yang sesuai).
- **Form buat/edit**: `title` (input required), `body` (textarea + **Summernote**), `status` (select Draf/Terbit), checkbox `is_pinned`. Validasi: title required, body required, status in:draft,published.
- **Simpan**: bersihkan `<script>`/`<style>` dari body sebagai pengaman ringan (penulis tepercaya). Saat status `published` & `published_at` masih null → set `published_at = now()`.
- **Summernote**: dimuat via CDN (jsDelivr) di `@push('plugin-styles')`/`@push('plugin-scripts')` halaman form saja. Init pada `textarea[name=body]`.
- Sidebar: menu "Pengumuman" (grup Akun, gated `@role(['superadmin','manager','admin'])`).

### 3.4 Slider di dashboard (semua role)

- Partial `resources/views/dashboard/partials/announcements.blade.php` di-`@include` di awal `@section('content')` `resources/views/dashboard.blade.php` (sebelum cabang produksi/marketing/financial), jadi tampil untuk semua role.
- Data via **View Composer** (di `AppServiceProvider::boot`) bound ke partial: `$announcements = $user ? app(AnnouncementService::class)->forDashboard($user) : collect()`.
- **Carousel Bootstrap 5** (`data-bs-ride="carousel"`, `data-bs-pause="hover"`): tiap `.carousel-item` = kartu (Judul + badge "Baru" bila `is_new` + Isi HTML `{!! !!}` + tanggal + penulis). Durasi per-item: `data-bs-interval = clamp(jumlah_kata ÷ 3.5 × 1000, 7000, 20000)` ms (jumlah_kata dari `str_word_count(strip_tags($body))`, dihitung di Blade). Panah prev/next + indikator titik. Bila koleksi kosong → partial tidak merender apa pun.
- **Tandai dibaca**: script kecil di partial mengirim `POST announcements.seen` (CSRF) berisi id yang tampil, ~2,5 detik setelah load → `markSeen`. Badge "Baru" jadi sekali-tampil per user.

Route tandai-dibaca (grup `auth`, semua role): `POST /announcements/seen` (`announcement.seen`) → `AnnouncementController@seen` → `markSeen(auth user, request ids)` → 204/no-content.

### 3.5 Hak akses

- CRUD + status: `role:superadmin|manager|admin` (produksi/marketing → 403).
- `GET dashboard` & `POST announcements/seen`: semua user terautentikasi. Konten pengumuman sama untuk semua; hanya badge "Baru" yang per-user.

---

## 4. Komponen yang Dibuat / Disentuh

- **Baru:** migrasi `tb_announcements` + `tb_announcement_reads`; `app/Models/Announcement.php`, `app/Models/AnnouncementRead.php`; `app/Services/AnnouncementService.php`; `app/Http/Controllers/Pages/AnnouncementController.php`; `resources/views/announcements/{index,form}.blade.php`; `resources/views/dashboard/partials/announcements.blade.php`.
- **Diubah:** `routes/web.php` (route admin + seen), `resources/views/layouts/sidebar.blade.php` (menu), `app/Providers/AppServiceProvider.php` (View Composer), `resources/views/dashboard.blade.php` (include partial).

## 5. Rencana Test

- **Unit `AnnouncementServiceTest`**:
  - `forDashboard` hanya memuat `published` (draft/archived dikecualikan); urut pinned dulu lalu terbaru.
  - `is_new`: published_at hari ini & belum dibaca → true; sudah dibaca user → false; published_at 5 hari lalu → false.
  - `markSeen` membuat baris read (idempotent: panggil 2× tetap 1 baris).
- **Feature `AnnouncementAdminTest`**:
  - superadmin/manager/admin bisa GET index + POST store (tersimpan) + ubah status (published set published_at) + delete.
  - produksi & marketing → 403 di index.
- **Feature `AnnouncementDashboardTest`**:
  - pengumuman published tampil di dashboard (cek judul) untuk role marketing & produksi & manager; draft tidak tampil.
  - POST `announcements/seen` menandai dibaca (baris read bertambah); setelah itu `is_new` false.

Suite memakai DB test via `.env.testing`; migrasi ikut `RefreshDatabase`. **Dev/prod: jalankan `php artisan migrate`** untuk dua tabel ini.

## 6. Asumsi & Risiko

- `body` di-render sebagai HTML (`{!! !!}`); pembuat hanya role tepercaya (superadmin/manager/admin); pengaman ringan strip `<script>`/`<style>` saat simpan. (Sanitizer penuh/HTMLPurifier di luar scope.)
- Summernote via CDN → butuh internet saat membuka halaman form admin; bila perlu offline, aset bisa di-host lokal belakangan.
- `published_at` di-set sekali saat pertama Terbit agar recency "Baru" stabil (arsip→terbit ulang tidak mereset, kecuali diinginkan — di luar scope).
- Tandai-dibaca lewat POST terpisah (bukan side-effect GET) agar badge "Baru" tampil sekali lalu hilang, mirip notifikasi.
- Durasi carousel pakai `data-bs-interval` per item (didukung Bootstrap 5) — tak perlu JS khusus untuk auto-slide.
