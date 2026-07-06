# Spec — Sapuan Title Halaman (Cycle 2)

- **Tanggal:** 2026-07-07
- **Branch:** `title-cleanup`
- **Scope:** Seragamkan `@section('title', ...)` semua view app → format **`'{Nama Halaman} - SiMAPA'`**, Bahasa Indonesia, cocok dgn nama menu sidebar (Cycle 1). Hanya ~21 view yang campur/Inggris/tanpa suffix; sisanya sudah rapi (tak disentuh). Auth (`auth/*`) di luar scope.

## Standar
- Format: `'<Nama> - SiMAPA'` (mayoritas sudah begini).
- Bahasa Indonesia; istilah menu sesuai sidebar (Report→Laporan, Todo→Daftar Tugas, Manuscript Tracker→Pelacak Naskah, Payment→Pembayaran, dst).
- Form Edit/Create tetap dinamis (`($x->exists ? 'Edit' : 'Buat') . ' ...'`) — sudah oke, tak diubah.

## Mapping (file → title lama → title baru)
| File | Lama | Baru |
|---|---|---|
| reports/submissions | Pemantauan Report - SiMAPA | **Pemantauan Laporan - SiMAPA** |
| reports/monthly | Report Bulanan - SiMAPA | **Laporan Bulanan - SiMAPA** |
| reports/daily | Report Harian - SiMAPA | **Laporan Harian - SiMAPA** |
| orders/journal/create | Create Order Journal - SiMAPA | **Buat Order Jurnal - SiMAPA** |
| orders/book/create | Create Order Book - SiMAPA | **Buat Order Buku - SiMAPA** |
| orders/book/show | Detail Order Book - SiMAPA | **Detail Order Buku - SiMAPA** |
| orders/book/index | List Order Book - SiMAPA | **Daftar Order - SiMAPA** |
| orders/detail-title | Detail Title - SiMAPA | **Detail Judul - SiMAPA** |
| payments/dp/index | Payment Order Dp - SiMAPA | **Pembayaran DP - SiMAPA** |
| payments/lunas/index | Payment Order Full - SiMAPA | **Pelunasan - SiMAPA** |
| payments/book/index | Payment Order Book - SiMAPA | **Pembayaran Order - SiMAPA** |
| payments/book/create | Payment Order - SiMAPA | **Buat Pembayaran - SiMAPA** |
| manuscript/board | Manuscript Tracker - SiMAPA | **Pelacak Naskah - SiMAPA** |
| manuscript/list | Manuscript Tracker - SiMAPA | **Pelacak Naskah - SiMAPA** |
| manuscript/log | Manuscript Tracker - Log Aktivitas | **Log Pelacak Naskah - SiMAPA** |
| tasks/index | Tugas - Todo - SiMAPA | **Daftar Tugas - SiMAPA** |
| tasks/board | Tugas - Board - SiMAPA | **Papan Tugas - SiMAPA** |
| tasks/calendar | Tugas - Kalender - SiMAPA | **Kalender Tugas - SiMAPA** |
| users/index | User Management | **Manajemen User - SiMAPA** |
| profile/index | Profile - SiMAPA | **Profil - SiMAPA** |
| profile/partials/delete-user-form | Profile - SiMAPA | **Profil - SiMAPA** |

## Tak diubah (sudah rapi)
titles/*, journals/*, isbn/*, archive/*, income/*, accounting/*, marketing-target, target/me, notifications, announcements/*, tagihan/*, invoices/*, orders/edit, orders/journal/edit, orders/index-title, orders/detail-title-group, refunds/refund_form. Auth/* di luar scope.

## Testing
- `php artisan view:cache` bersih (validasi Blade).
- `php artisan test` penuh tetap hijau (title tak dites langsung; memastikan tak ada view rusak).

## Komponen
- **Diubah:** 21 view (hanya baris `@section('title', ...)`).
