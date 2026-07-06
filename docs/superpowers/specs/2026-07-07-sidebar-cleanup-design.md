# Spec — Sidebar Profesional (Cycle 1: struktur + active + penamaan link)

- **Tanggal:** 2026-07-07
- **Branch:** `sidebar-cleanup`
- **Scope (Cycle 1):** Restrukturisasi grup menu sidebar, penamaan link Bahasa Indonesia konsisten, ikon unik, dan **active-state akurat** (item nyala hanya untuk halaman & sub-halamannya, via `routeIs()`). **Cycle 2 (menyusul):** sapuan ~60 title halaman agar seragam & cocok nama menu.
- **Keputusan user:** Order & Pembayaran **grup terpisah** · label **Bahasa Indonesia** · **bertahap**.

## Masalah lama (yang diperbaiki)
- Dua "Keuangan" (grup akuntansi vs collapse income) → income jadi **"Laporan Keuangan"**.
- "Pembayaran" dobel (header + item collapse) → header grup "PEMBAYARAN", item collapse tetap "Pembayaran".
- Direktori Judul/Jurnal/ISBN mengambang → grup **"DIREKTORI"** (+ Arsip Judul).
- "Laporan"(ID)+"Report"(EN) → satu grup **"LAPORAN"**; Report Harian/Bulanan → **Laporan Harian/Bulanan**.
- Active salah: Jurnal Kas `accounting/*` nyala di semua halaman akuntansi; detail order nyala "Buat Order". → pakai `routeIs()` presisi.
- Ikon dobel → dibuat lebih unik.

## Helper baru — `app/Helpers/RouteHelper.php`
```php
if (!function_exists('nav_active')) {
    function nav_active($names, $active = 'active') {
        return request()->routeIs(...(array) $names) ? $active : '';
    }
}
if (!function_exists('nav_show')) {
    function nav_show($names) {
        return request()->routeIs(...(array) $names) ? 'show' : '';
    }
}
if (!function_exists('nav_expanded')) {
    function nav_expanded($names) {
        return request()->routeIs(...(array) $names) ? 'true' : 'false';
    }
}
```
(Helper lama `active_class`/`is_active_route`/`show_class` dibiarkan — mungkin dipakai view lain.)

## Struktur baru (grup · role · item [ikon] · pola active `routeIs`)

**UTAMA** (semua)
- Dashboard [home] — `dashboard`

**ORDER** (superadmin/manager/marketing)
- Buat Order [plus-square] collapse — active/show: `order.book.create`, `order.journal.create`
  - Buku → `order.book.create` · Jurnal → `order.journal.create`
- Daftar Order [shopping-bag] — `order.book.index`, `order.book.show`, `order.book.edit`, `order.book.update`, `order.journal.show`, `order.journal.edit`, `order.journal.update`, `order.refund.*`

**PEMBAYARAN** (superadmin/manager/marketing)
- Tagihan [file-plus] — `tagihan.*`
- Invoice [file-text] — `invoice.*`
- Pembayaran [credit-card] collapse — `payment.*`
  - DP/Pembayaran → `payment.dp.index` · Pelunasan → `payment.fp.index` · Disetujui → `payment.index`

**DIREKTORI** (superadmin/manager/admin/production/marketing)
- Direktori Judul [book] — `title.*`
- Direktori Jurnal [book-open] — `journal.*`
- Direktori ISBN [hash] — `isbn.*`
- Arsip Judul [archive] — `archive.*`

**PRODUKSI** (superadmin/manager/production)
- Pelacak Naskah / **Meja Kerja Saya** (production murni) [layers] — `manuscript.*`

**KEUANGAN** (superadmin/accounting)
- Ringkasan [pie-chart] — `accounting.overview`
- Jurnal Kas [dollar-sign] — `accounting.journal`
- Dashboard Keuangan [bar-chart-2] — `accounting.dashboard`
- Distribusi Profit [share-2] — `accounting.distribution`
- Asumsi [sliders] — `accounting.assumption`
- Anggaran & Target [target] — `accounting.target`

**LAPORAN** (superadmin/manager/marketing)
- Laporan Keuangan [trending-up] collapse — `income.*`
  - Pemasukan → `income.pemasukan` · Piutang → `income.piutang` · Order Selesai → `income.lunas`
- Target Marketing [crosshair] (superadmin/manager) — `marketing-target.index`
- Target Saya [crosshair] (marketing) — `marketing-target.me`
- Laporan Harian [file] — `report.daily`
- Laporan Bulanan [bar-chart] — `report.monthly`
- Pemantauan Laporan [clipboard] (manager/superadmin) — `report.submissions`

**TUGAS** (semua)
- Papan Tugas [trello] — `task.board`
- Kalender [calendar] — `task.calendar`
- Daftar Tugas [check-square] — `task.index`
- Pemantauan Tugas [activity] (manager/superadmin) — `task.monitor`

**AKUN & SISTEM** (semua kecuali yg dibatasi)
- Manajemen User [users] (superadmin/manager) — `user.management`
- Pengumuman [volume-2] (superadmin/manager/admin) — `announcement.index`, `announcement.create`, `announcement.edit`
- Profil [user] — `profile`

> Collapse pakai `nav_show(...)`/`aria-expanded="{{ nav_expanded(...) }}"`; item pakai `nav_active(...)`.

## Testing
- **`SidebarTest`** (feature): untuk peran perwakilan (superadmin, marketing, accounting, production) GET `dashboard` → 200 + `assertSee` header grup yg sesuai peran (mis. superadmin lihat 'ORDER','PEMBAYARAN','KEUANGAN','DIREKTORI','PRODUKSI','LAPORAN','TUGAS'; accounting lihat 'KEUANGAN'; marketing tak lihat 'KEUANGAN'/'PRODUKSI').
- **Regresi:** seluruh suite (492) — tiap test me-render layout+sidebar, jadi **route() yang salah nama akan 500** → suite menangkap. `php artisan view:cache` bersih.

## Komponen
- **Diubah:** `app/Helpers/RouteHelper.php` (+3 helper); `resources/views/layouts/sidebar.blade.php` (rewrite struktur+label+ikon+active).
- **Baru:** `tests/Feature/SidebarTest.php`.
- **Tak diubah:** title halaman (Cycle 2).

## Risiko
- Nama route salah → 500 (ditangkap suite). Verifikasi via `php artisan test` penuh.
- Label bisa disesuaikan user; struktur & active adalah inti.
