# Spec 2: Payment + Invoice Lifecycle

**Tanggal:** 2026-06-13
**Status:** Draft — menunggu review
**Area:** Payment Journal, Invoice CRUD, Invoice Lifecycle Management

---

## Ringkasan

Memperluas sistem payment dan invoice SIMAPA dengan dua fokus utama:
1. **Payment Jurnal** — menerapkan alur payment yang sama dengan buku (DP → Pelunasan → Approve/Reject → Invoice) untuk order jurnal
2. **Invoice Lifecycle** — invoice memiliki lifecycle lengkap (Draft → Diterbitkan → Jatuh Tempo → Lunas → Refund/Dibatalkan), bisa dibuat manual (proforma) sebelum payment, dengan CRUD penuh dan audit log

---

## 1. Database Schema

### Modifikasi `tb_invoices`

Tambah kolom berikut — tidak ada kolom yang dihapus, migrasi non-destructive:

| Kolom | Tipe | Keterangan |
|-------|------|-----------|
| `payment_id` | bigint FK **nullable** | Diubah dari NOT NULL ke nullable — invoice proforma belum punya payment |
| `type` | enum | `proforma` / `regular` |
| `status` | enum (diperluas) | `draft` / `diterbitkan` / `jatuh_tempo` / `lunas` / `dibatalkan` / `refund` |
| `note` | text nullable | Catatan invoice |
| `cancelled_by` | bigint FK nullable | → users.id — siapa yang cancel |
| `cancelled_at` | timestamp nullable | Kapan di-cancel |
| `refunded_by` | bigint FK nullable | → users.id — siapa yang refund |
| `refunded_at` | timestamp nullable | Kapan di-refund |

**Invoice existing** yang sudah ada (dibuat otomatis oleh sistem saat payment approve) akan di-set `type = regular` dan `status = lunas` via seeder/migration data.

### Tabel Baru: `tb_invoice_logs`

Audit trail setiap perubahan status invoice.

| Kolom | Tipe | Keterangan |
|-------|------|-----------|
| id | bigint PK | |
| invoice_id | bigint FK | → tb_invoices.id |
| from_status | varchar(50) | Status sebelumnya |
| to_status | varchar(50) | Status sesudahnya |
| changed_by | bigint FK | → users.id |
| note | text nullable | Alasan perubahan |
| created_at | timestamp | |

---

## 2. Invoice Lifecycle

```
Draft → Diterbitkan → Jatuh Tempo → Lunas
                    ↘ Dibatalkan
        Lunas → Refund
```

| Status | Keterangan | Siapa yang set |
|--------|-----------|----------------|
| `draft` | Dibuat manual, belum aktif | Otomatis saat create |
| `diterbitkan` | Aktif, menunggu pembayaran | Manager, Superadmin |
| `jatuh_tempo` | Melewati due_date, belum lunas | Otomatis (scheduled job) atau manual Manager/Superadmin |
| `lunas` | Payment sudah diapprove | Otomatis saat payment approve |
| `dibatalkan` | Invoice dibatalkan | Superadmin (paksa cancel) |
| `refund` | Dana dikembalikan ke client | Superadmin |

---

## 3. Role Permission Invoice

| Aksi | Marketing | Manager | Superadmin |
|------|:---------:|:-------:|:----------:|
| Buat invoice proforma | ✓ | ✓ | ✓ |
| Buat invoice regular | ✓ | ✓ | ✓ |
| Edit nominal, due_date, catatan | ✗ | ✓ | ✓ |
| Ubah status (draft → diterbitkan → jatuh_tempo → lunas) | ✗ | ✓ | ✓ |
| Paksa cancel | ✗ | ✗ | ✓ |
| Refund | ✗ | ✗ | ✓ |
| Lihat invoice & log history | ✓ | ✓ | ✓ |

---

## 4. Payment Jurnal

Alur payment jurnal **identik** dengan payment buku:

```
Order Jurnal dibuat
    → DP (Down Payment) dibayar → Upload bukti → Approval
    → Pelunasan dibayar → Upload bukti → Approval
    → Invoice diterbitkan otomatis
```

**Perubahan yang diperlukan:**
- `PaymentBookController` di-extend agar tidak hardcode `type = buku` — gunakan tipe dari `tb_order_details.type`
- View `payments/book/create.blade.php` dan `index.blade.php` sudah generik, cukup pastikan route menerima `code_order` dari order jurnal
- Route payment yang ada tetap digunakan, tidak perlu route terpisah untuk jurnal

---

## 5. Komponen Baru

### Model

**`InvoiceLog.php`** di `app/Models/`

Relasi:
- `belongsTo(Invoice::class)`
- `belongsTo(User::class, 'changed_by')`

**Update `Invoice.php`:**
- Tambah `hasMany(InvoiceLog::class)`
- Update `$fillable` untuk kolom baru
- Tambah `$casts` untuk enum type & status
- Tambah accessor `isOverdue()` — cek due_date vs hari ini

### Controller

**`InvoiceController.php`** di `app/Http/Controllers/Pages/`

| Method | HTTP | Route | Role |
|--------|------|-------|------|
| `index()` | GET | `/invoices` | semua |
| `create()` | GET | `/invoices/create` | semua |
| `store()` | POST | `/invoices` | semua |
| `show($id)` | GET | `/invoices/{id}` | semua |
| `edit($id)` | GET | `/invoices/{id}/edit` | manager, superadmin |
| `update($id)` | PUT | `/invoices/{id}` | manager, superadmin |
| `updateStatus($id)` | POST | `/invoices/{id}/status` | manager, superadmin |
| `cancel($id)` | POST | `/invoices/{id}/cancel` | superadmin |
| `refund($id)` | POST | `/invoices/{id}/refund` | superadmin |
| `logs($id)` | GET | `/invoices/{id}/logs` | semua |

### Migration

1. `2026_06_13_update_invoices_table.php` — tambah kolom ke `tb_invoices`, ubah `payment_id` nullable
2. `2026_06_13_create_invoice_logs_table.php` — buat `tb_invoice_logs`

---

## 6. File yang Dimodifikasi

| File | Perubahan |
|------|-----------|
| `Invoice.php` | Relasi InvoiceLog, fillable baru, casts, accessor isOverdue() |
| `PaymentBookController.php` | Hilangkan hardcode tipe buku, support order jurnal |
| `routes/web.php` | Tambah resource routes invoice + route cancel & refund |
| `sidebar.blade.php` | Tambah menu "Invoice" di section PAYMENT |

---

## 7. Views Baru

| View | Path | Keterangan |
|------|------|-----------|
| List Invoice | `resources/views/payments/invoices/index.blade.php` | Tabel semua invoice + filter |
| Buat Invoice | `resources/views/payments/invoices/create.blade.php` | Form create proforma/regular |
| Edit Invoice | `resources/views/payments/invoices/edit.blade.php` | Form edit (manager+) |
| Detail Invoice | `resources/views/payments/invoices/show.blade.php` | Info lengkap + log history |

---

## 8. UI Detail

### List Invoice (`/invoices`)

Kolom tabel:
- No Invoice
- Order (kode + judul)
- Tipe — badge: Proforma (abu), Regular (biru)
- Status — badge berwarna:

| Status | Warna Badge |
|--------|------------|
| Draft | Abu-abu |
| Diterbitkan | Biru |
| Jatuh Tempo | Oranye |
| Lunas | Hijau |
| Dibatalkan | Merah |
| Refund | Ungu |

- Nominal
- Due Date
- Dibuat Oleh
- Aksi (Detail, Edit — kondisional per role)

Filter: Status, Tipe, Bulan/Tahun

### Detail Invoice (`/invoices/{id}`)

- Info invoice lengkap + data order terkait
- Tombol aksi kondisional per role:
  - Manager: Edit, Update Status
  - Superadmin: Edit, Update Status, Cancel, Refund
- Log history (pola sama dengan TitleProgressLog): from → to, oleh siapa, kapan, catatan

### Form Buat Invoice (`/invoices/create`)

Field:
- Pilih Order — dropdown searchable (kode + judul)
- Tipe — radio: Proforma / Regular
- Jika Regular: dropdown pilih Payment terkait (opsional)
- Nominal
- Tanggal Terbit (`issued_at`)
- Tanggal Jatuh Tempo (`due_at`)
- Catatan (opsional)

---

## 9. Alur Data (Data Flow)

```
Marketing buat invoice proforma
    → POST /invoices
    → InvoiceController@store()
    → type = proforma, payment_id = null, status = draft
    → Log awal dibuat otomatis

Manager ubah status invoice
    → POST /invoices/{id}/status
    → Validasi role
    → Update status, simpan log

Payment diapprove (existing flow)
    → PaymentBookController@approve()
    → Invoice existing diupdate status → lunas
    → Log dibuat otomatis

Superadmin cancel invoice
    → POST /invoices/{id}/cancel
    → Set status = dibatalkan, cancelled_by, cancelled_at
    → Simpan log dengan note (required)

Superadmin refund
    → POST /invoices/{id}/refund
    → Validasi status harus lunas terlebih dahulu
    → Set status = refund, refunded_by, refunded_at
    → Simpan log dengan note (required)
```

---

## 10. Error Handling

| Kondisi | Handling |
|---------|----------|
| Marketing coba edit invoice | 403 Forbidden |
| Manager coba cancel/refund | 403 Forbidden |
| Refund invoice yang belum lunas | Validasi error — "Invoice harus berstatus lunas untuk di-refund" |
| Cancel invoice yang sudah lunas | Superadmin tetap bisa — dicatat di log |
| Invoice proforma tanpa payment saat ubah ke regular | Validasi — payment harus dipilih |

---

## 11. Hal yang Tidak Dicakup Spec Ini

- Scheduled job otomatis untuk set status `jatuh_tempo` berdasarkan due_date (masuk Spec Notifikasi)
- Email notifikasi ke client saat invoice diterbitkan atau jatuh tempo (masuk Spec Notifikasi)
- Generate PDF untuk invoice proforma (bisa ditambahkan sebagai enhancement)

---

## Dependensi

- Spec 1 tidak ada ketergantungan dengan Spec 2 — keduanya independen, bisa dikerjakan paralel
- Spatie Permission sudah terpasang
- DomPDF sudah terpasang (untuk PDF proforma jika diperlukan nanti)
- Tidak ada package baru yang dibutuhkan
