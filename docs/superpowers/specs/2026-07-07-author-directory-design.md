# Spec — Direktori Author (read-only)

- **Tanggal:** 2026-07-07
- **Branch:** `author-directory`
- **Scope:** Halaman **list author** (Nama · Affiliasi · Email · Telp · Jml Order · Aksi) + **halaman detail** author dgn **riwayat order**. Read-only. superadmin/manager/admin/production/marketing (grup Direktori).
- **Di luar scope:** edit/CRUD author (data author dari input saat order); statistik/chapter authors.

> `Author` (tb_authors: name/email/phone/affiliation) ↔ `OrderDetail` via `tb_author_orders` (pivot position). Tiap OrderDetail belongsTo satu Order. Jml Order = jumlah orderDetails author. Riwayat = orderDetails→order.

## 1. Kontroler & Rute — `AuthorController` (role `superadmin|manager|admin|production|marketing`)
- `index()`: `Author::withCount('orderDetails')->orderBy('name')->get()` → `authors.index`.
- `show($id)`: `Author::withCount('orderDetails')->with(['orderDetails.order'])->findOrFail($id)` → `authors.show`.
- Rute: `GET authors` `author.index`; `GET authors/{id}` `author.show` (whereNumber id). Dalam grup middleware role tsb.

## 2. Views
**`authors/index.blade.php`** (DataTable spt view index lain): kolom Nama · Affiliasi (`?? '-'`) · Email (mailto bila ada) · Telp · **Jml Order** (`order_details_count`) · **Aksi** = Detail (`author.show`) · WA (bila phone → `https://wa.me/{normalisasi}`) · Email (bila email → mailto). Normalisasi WA: buang non-digit; `0…`→`62…`.

**`authors/show.blade.php`**: kartu info (Nama, Affiliasi, Email, Telp, Jml Order) + tombol WA/Email. Tabel **Riwayat Order**: Kode Order (`order->code_order`) · Judul (`detail->title`) · Jenis (`bk_*`→Buku, `at_*`→Artikel) · Tanggal (`order->ordered_at`) · Status (`order->status`) · Posisi (`pivot->position`). Loop `$author->orderDetails`.

## 3. Sidebar
Grup **Direktori** (setelah Direktori ISBN): item **"Direktori Author"** [ikon `users`] → `author.index`, active `nav_active('author.*')`. Role sama grup Direktori.

## 4. Test — `AuthorDirectoryTest`
- `index_lists_authors_with_order_count`: buat Author + Order + OrderDetail, attach via pivot; GET `author.index` (superadmin) → 200 + assertSee nama + affiliasi; `order_details_count` = 1.
- `show_displays_order_history`: GET `author.show` → 200 + assertSee kode order + judul.
- `disallowed_role_forbidden`: accounting → `author.index` 403.
- Regresi: suite hijau; `view:cache` bersih.

## 5. Komponen
- **Baru:** `AuthorController`; views `authors/index.blade.php`, `authors/show.blade.php`; test `AuthorDirectoryTest`.
- **Diubah:** `routes/web.php` (+2 rute + import); `sidebar.blade.php` (+menu Direktori Author).
- **Tak diubah:** model Author/OrderDetail (dipakai apa adanya).
