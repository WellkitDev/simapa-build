# Spec — Konsistensi UI Order/Pembayaran/Detail + Edit Order Jurnal

- **Tanggal:** 2026-06-26
- **Branch:** `ui-consistency`
- **Scope:** Selaraskan tampilan halaman Order (buku/jurnal: create/edit/show + arsip/detail judul), Pembayaran (Disetujui/DP/Pelunasan/Ajukan), Tagihan (index/create/show), dan Invoice (index/show/edit) ke gaya template **NobleUI BS5** yang sudah dipakai halaman baru (Pengumuman/Tugas/Report/Target). Plus **implementasi Edit Order Jurnal** (controller masih stub) + rapikan gating role pada route `show`.
- **Di luar scope (sengaja):** template PDF (`*_pdf.blade.php`), perubahan logika/alur order-payment, fitur baru (CRM/export), hapus order.

> Gaya mengikuti `template-web` (gitignored — jangan commit). Lihat [[ui-conventions]].

---

## 1. Latar Belakang

Halaman lama (order/payment form & detail) memakai gaya Bootstrap "vanilla": pembungkus `container py-5`, judul `<h1>`, kartu dengan `card-header bg-primary text-white`. Halaman yang dibangun belakangan (Pengumuman, Tugas, Report, Target Marketing, dashboard) memakai pola NobleUI: `row > col-* grid-margin stretch-card > card > card-body`, judul `h6.card-title`, list `table.datatable` (DataTables). `orders/book/index` sudah pakai DataTables; yang belum konsisten terutama halaman **form & detail**. Selain itu, **Edit Order Jurnal belum ada**: `OrderJournalController::edit()` me-`return view('pages.order.journals.edit')` (view tidak ada) dan `update()`/`destroy()` kosong — sedangkan Order Buku sudah bisa diedit.

## 2. Tujuan & Kriteria Sukses

1. Semua halaman dalam scope memakai pola NobleUI yang konsisten (kartu, judul, tabel DataTables, tombol/badge seragam) — tanpa `container py-5`/`card-header bg-primary`.
2. Order Jurnal bisa **diedit** oleh marketing/manager/superadmin (meniru perilaku Edit Order Buku), dengan tombol Edit di Daftar Order.
3. Route `order.book.show` & `order.journal.show` ter-gate `role:marketing|manager|superadmin`.
4. **Tidak ada perubahan logika/data**: semua field, action, hidden input, upload Drive, validasi, dan teks/label yang di-assert test lama tetap utuh.
5. Seluruh suite tetap hijau (274) setelah tiap grup; tambah test untuk Edit Jurnal.

---

## 3. Standar Konsistensi (acuan "the rest")

Pola wajib (lihat `resources/views/announcements/index.blade.php`, `tasks/board.blade.php`, `marketing-target/index.blade.php` sebagai contoh):

- **Pembungkus:** hapus `<div class="container py-5">`; konten langsung di `@section('content')` (master sudah memberi padding `page-content`).
- **Header halaman:** `<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">` berisi judul `<h5 class="mb-0">` (+ subjudul `<small class="text-muted">`) dan tombol aksi (mis. "Kembali", "Simpan").
- **Kartu:** `<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body"> ... </div></div></div></div>`. Untuk seksi di dalam kartu, judul `<h6 class="card-title">`. **Hapus** `card-header bg-primary text-white`.
- **List/tabel:** `<table class="table table-hover datatable" style="width:100%">` + init DataTables (`pageLength`, `order: []`, `language: { emptyTable: '...' }`) dengan aset `assets/libs/datatables.net*` (pola yang sudah dipakai `orders/book/index`).
- **Form:** label `form-label`; input `form-control`/`form-select`; select2 untuk relasi (penerima/penulis/order) dengan aset `assets/plugins/select2/...`; tanggal pakai flatpickr bila relevan. **Pertahankan semua `name`, `value`, hidden input, `enctype`, dan tombol submit.**
- **Tombol:** `btn btn-sm btn-primary` (utama), `btn btn-sm btn-outline-secondary` (Kembali/Batal), `btn-xs btn-outline-*` (aksi baris). **Badge status:** palet konsisten (`bg-success`/`bg-warning text-dark`/`bg-danger`/`bg-secondary`).
- **Tabel detail key→value** (di halaman show): boleh tetap `<table class="table table-borderless">` tetapi dibungkus kartu NobleUI (bukan card-header berwarna).

Prinsip: **ubah pembungkus & kelas presentasi, jangan ubah konten data/field.** Bila ragu, samakan dengan halaman sibling yang sudah konsisten.

## 4. Halaman dalam Scope (HTML; PDF dikecualikan)

| Grup | File |
|---|---|
| **Order** | `orders/book/create`, `orders/journal/create`, `orders/edit` (buku), **`orders/journal/edit` (baru)**, `orders/book/show`, `orders/journal/show`*, `orders/book/index` (normalisasi ringan), `orders/index-title`, `orders/detail-title`, `orders/detail-title-group` |
| **Pembayaran** | `payments/book/index` (Disetujui), `payments/book/create` (Ajukan), `payments/dp/index`, `payments/lunas/index` |
| **Tagihan** | `payments/tagihan/index`, `payments/tagihan/create`, `payments/tagihan/show` |
| **Invoice** | `payments/invoices/index`, `payments/invoices/show`, `payments/invoices/edit` |

\* `orders/journal/show`: jika route `order.journal.show` memakai view tersendiri, restyle; bila reuse `orders/book/show`, cukup pastikan show buku konsisten. (Implementer verifikasi controller `OrderJournalController::show`.)

**Dikecualikan:** `payments/invoices/book_invoice_pdf.blade.php`, `payments/tagihan/tagihan_pdf.blade.php` (template cetak).

## 5. Fungsional — Edit Order Jurnal

`OrderJournalController` saat ini: `edit()` me-return view yang tidak ada; `update()`/`destroy()` kosong.

- **`edit(string $code_order)`**: muat order jurnal beserta relasi (mirror `OrderBookController::edit`), render `resources/views/orders/journal/edit.blade.php` (form ber-isi nilai sekarang). Param mengikuti pola Buku (`{code_order}`).
- **`update(Request, string $code_order)`**: validasi + update order + detail (mirror `OrderBookController::update`, termasuk handling field jurnal + file/relasi bila ada). Redirect ke daftar/detail dengan flash sukses.
- **Routes** (di grup `order`, sejajar Buku):
  - `GET order/jurnal/update/{code_order}` → `OrderJournalController@edit` name `order.journal.edit` `role:marketing|manager|superadmin`.
  - `PUT order/jurnal/update/{code_order}` → `OrderJournalController@update` name `order.journal.update` `role:marketing|manager|superadmin`.
- **Daftar Order**: tambah tombol **Edit** untuk baris order jurnal → `order.journal.edit`.
- `destroy()` dibiarkan tak ber-route (samakan dengan Buku — tak ada hapus order). Boleh diberi guard `abort(404)`/tidak diubah; tidak ditambah ke route.
- **Gating show:** tambahkan `->middleware('role:marketing|manager|superadmin')` ke `order.book.show` (route `buku/show/{code_order}`) dan `order.journal.show` (route `jurnal/show/{code_order}`).

## 6. Komponen yang Disentuh

- **Diubah (view restyle):** seluruh file di tabel §4.
- **Baru:** `resources/views/orders/journal/edit.blade.php`.
- **Diubah (logika minimal):** `app/Http/Controllers/Pages/OrderJournalController.php` (`edit`/`update`), `routes/web.php` (2 route jurnal edit + 2 gating show), `resources/views/orders/book/index.blade.php` (tombol Edit jurnal — bila daftar order menampilkan jurnal di tabel yang sama).

## 7. Rencana Test

- **Feature `OrderJournalEditTest` (baru)**: marketing `GET order.journal.edit` → 200 & melihat nilai order; `PUT order.journal.update` dengan data valid → redirect + perubahan tersimpan (assert DB). Non-authorized role → 403. (Mock `GoogleDriveService`.)
- **Regression (kritis):** seluruh suite (`php artisan test`) tetap **hijau** setelah tiap grup restyle — khususnya `DetailOrderPaymentInvoiceTest`, `TagihanLifecycleTest`, `SidebarTagihanTest`, `ProductionWorkspaceTest`, `MarketingDashboardTest`, dan test order/payment lain. Restyle TIDAK boleh mengubah teks/data yang di-assert.
- **Smoke (opsional, bila murah):** halaman yang di-restyle merender 200 untuk role yang berhak.

Suite via DB test (`.env.testing`), `GoogleDriveService` di-mock. Tanpa migrasi baru.

## 8. Eksekusi Bertahap

Karena ~18 view, kerjakan per grup dengan suite penuh sebagai gerbang tiap grup:
1. **Edit Jurnal** (fitur + route + gating show) — paling berisiko (logika) → kerjakan & uji dulu.
2. **Grup Order** (restyle form/detail/arsip).
3. **Grup Pembayaran** (DP/lunas/list/create).
4. **Grup Tagihan/Invoice**.
5. **Verifikasi penuh**.

## 9. Asumsi & Risiko

- Restyle murni presentasi; risiko utama = memutus assertion test lama → mitigasi: pertahankan teks/data, jalankan suite tiap grup.
- Edit Jurnal meniru Edit Buku; bila form jurnal punya field unik (mis. relasi/biaya jurnal), `update()` menyesuaikan field jurnal (bukan menyalin buta field buku).
- `template-web` (gitignored) hanya acuan gaya; tak di-commit.
- Tidak ada migrasi / perubahan skema.
