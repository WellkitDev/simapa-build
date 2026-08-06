# Spec 1: Order Management + Title Progress Tracking

**Tanggal:** 2026-06-13
**Status:** Draft — menunggu review
**Area:** Order Management, Title Tracking, Search & Filter

---

## Ringkasan

Menambahkan sistem tracking progres per judul (title) pada order buku dan artikel di SIMAPA. Setiap judul memiliki alur tahapan yang berbeda (buku vs artikel), dengan kontrol akses berbasis role per tahap. Superadmin dapat melakukan koreksi status, manager hanya bisa maju ke tahap berikutnya, dan marketing hanya bisa membuat order.

---

## 1. Database Schema

### Tabel Baru: `tb_title_progress`

Menyimpan status aktif setiap title saat ini. Relasi one-to-one dengan `tb_order_details`.

| Kolom | Tipe | Keterangan |
|-------|------|-----------|
| id | bigint PK | |
| order_detail_id | bigint FK | → tb_order_details.id (unique) |
| status | varchar(50) | Enum per tipe: lihat daftar tahapan |
| assigned_role | varchar(50) | Role yang handle tahap ini |
| note | text nullable | Catatan tahap aktif |
| updated_by | bigint FK | → users.id |
| started_at | timestamp | Kapan tahap ini dimulai |
| created_at | timestamp | |
| updated_at | timestamp | |

### Tabel Baru: `tb_title_progress_logs`

Menyimpan seluruh history perubahan status.

| Kolom | Tipe | Keterangan |
|-------|------|-----------|
| id | bigint PK | |
| title_progress_id | bigint FK | → tb_title_progress.id |
| from_status | varchar(50) | Status sebelumnya |
| to_status | varchar(50) | Status sesudahnya |
| changed_by | bigint FK | → users.id |
| note | text nullable | Alasan perubahan |
| is_correction | boolean | true jika ini koreksi superadmin |
| created_at | timestamp | |

---

## 2. Tahapan Status per Tipe

### Artikel (`at_mandiri`, `at_kolab`)

```
menunggu_proses → templating → editing → revisi → submit → loa → publish
```

### Buku (`bk_mandiri`, `bk_kolab`)

```
menunggu_proses → editing → layout → proofreading → isbn → cetak → terbit
```

Status `menunggu_proses` dibuat otomatis saat order baru di-store.

---

## 3. Role per Tahap

| Aksi | Marketing | Manager | Superadmin |
|------|:---------:|:-------:|:----------:|
| Buat order (set menunggu_proses) | ✓ | ✓ | ✓ |
| Advance status (maju ke tahap berikutnya) | ✗ | ✓ | ✓ |
| Koreksi status (mundur / lompat tahap) | ✗ | ✗ | ✓ |
| Edit data order (judul, author, scope) | ✗ | ✗ | ✓ |

**Catatan koreksi superadmin:** field `note` wajib diisi. Entry log ditandai `is_correction = true` dan tampil dengan warna berbeda di timeline.

---

## 4. Komponen Baru

### Models

| File | Lokasi |
|------|--------|
| `TitleProgress.php` | `app/Models/` |
| `TitleProgressLog.php` | `app/Models/` |

**TitleProgress** — relasi:
- `belongsTo(OrderDetail::class)`
- `hasMany(TitleProgressLog::class)`
- `belongsTo(User::class, 'updated_by')`

**TitleProgressLog** — relasi:
- `belongsTo(TitleProgress::class)`
- `belongsTo(User::class, 'changed_by')`

### Controller

**`TitleProgressController.php`** di `app/Http/Controllers/Pages/`

| Method | Aksi |
|--------|------|
| `update(Request $request, $id)` | Advance atau koreksi status. Validasi role vs tahap. Simpan log. |
| `logs($id)` | Kembalikan JSON history log untuk ditampilkan di detail title. |

### Migration

`database/migrations/2026_06_13_create_title_progress_tables.php` — buat `tb_title_progress` dan `tb_title_progress_logs`.

---

## 5. Modifikasi File Existing

| File | Perubahan |
|------|-----------|
| `OrderBookController.php` | `store()`: auto-create `TitleProgress` dengan status `menunggu_proses` setelah order tersimpan |
| `OrderBookController.php` | `indexJudul()`: tambah query search (judul, author) dan filter (tipe, status, tahun) |
| `routes/web.php` | Tambah 2 route baru (lihat section 6) |

---

## 6. Routes Baru

```php
// Title Progress
POST /management/title/{id}/update-status   → TitleProgressController@update
GET  /management/title/{id}/logs            → TitleProgressController@logs
```

Kedua route di bawah middleware `auth` dan `verified`. Route `update-status` tambah middleware `role:manager|superadmin`.

---

## 7. UI — Halaman Dashboard Title (`/management/title`)

**Tabel kolom:**
- Judul
- Tipe (Buku / Artikel) — badge
- Author Utama
- Status — badge berwarna per tahap
- Handler (role yang bertanggung jawab sekarang)
- Tanggal Update
- Aksi (Detail)

**Search & Filter:**
- Input teks: cari berdasarkan judul atau nama author
- Dropdown Tipe: Semua / Buku / Artikel
- Dropdown Status: Semua / per tahap
- Dropdown Tahun: berdasarkan `ordered_at` di `tb_orders`

**Badge warna status:**
| Status | Warna |
|--------|-------|
| menunggu_proses | Abu-abu |
| templating, editing, layout | Kuning |
| revisi, proofreading, isbn | Oranye |
| submit, cetak, loa | Biru |
| publish, terbit | Hijau |

---

## 8. UI — Halaman Detail Title (`/management/title/details/{id}`)

**Bagian atas:** info order (judul, authors, tipe, scope, kode order)

**Timeline horizontal:** semua tahapan ditampilkan berurutan dengan tiga kondisi visual:
- Sudah lewat: ikon centang, warna solid
- Sedang berjalan: ikon aktif, warna highlight
- Belum: ikon kosong, warna abu-abu

**Form update status** (hanya tampil untuk manager & superadmin):
- Dropdown "Status Berikutnya" (untuk manager: hanya tahap selanjutnya; untuk superadmin: semua tahap tersedia)
- Textarea "Catatan" (required jika superadmin melakukan koreksi mundur/lompat)
- Tombol "Update Status"

**Tombol "Edit Order"** — hanya tampil untuk superadmin, mengarah ke form edit order existing.

**Log History** (tabel di bawah timeline):
| Kolom | |
|-------|-|
| Dari Status | |
| Ke Status | |
| Diubah Oleh | |
| Tanggal | |
| Catatan | |
| Label "Koreksi" | tampil jika is_correction = true, warna merah |

---

## 9. Alur Data (Data Flow)

```
Marketing buat order
    → OrderBookController@store()
    → TitleProgress::create(['status' => 'menunggu_proses', ...])
    → Log awal dibuat otomatis

Manager/Superadmin advance status
    → POST /management/title/{id}/update-status
    → TitleProgressController@update()
    → Validasi: apakah user role boleh di tahap ini?
    → Update tb_title_progress.status
    → Insert tb_title_progress_logs (is_correction = false)

Superadmin koreksi status
    → POST /management/title/{id}/update-status (dengan flag correction)
    → Validasi: hanya superadmin
    → Validasi: note wajib diisi
    → Update tb_title_progress.status ke target apapun
    → Insert tb_title_progress_logs (is_correction = true)
```

---

## 10. Error Handling

| Kondisi | Handling |
|---------|----------|
| Manager coba koreksi status | Return 403 Forbidden |
| Marketing coba advance status | Return 403 Forbidden |
| Superadmin koreksi tanpa note | Validasi error, return ke form |
| Status tidak valid untuk tipe order | Validasi di controller, return error |
| Order tidak punya TitleProgress | Auto-create saat pertama diakses (fallback) |

---

## 11. Hal yang Tidak Dicakup Spec Ini

- Notifikasi email/in-app saat status berubah (masuk Spec Notifikasi terpisah)
- Integrasi dengan Invoice/Payment (masuk Spec 2)
- Export laporan title tracking ke Excel/PDF

---

## Dependensi

- Spatie Permission sudah terpasang — gunakan `->middleware('role:manager|superadmin')` atau `@role` di blade
- Tidak ada package baru yang dibutuhkan
