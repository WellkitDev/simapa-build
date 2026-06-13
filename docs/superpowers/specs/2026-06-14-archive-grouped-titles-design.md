# Spec: Archive Judul Ter-grup + Penataan Sidebar + Pemulihan DataTables

**Tanggal:** 2026-06-14
**Status:** Draft — menunggu review
**Area:** Order/Title Management (menu "Archive"), Sidebar Navigation, DataTables

---

## Ringkasan

Tiga perbaikan pada area Order/Naskah:

1. **Archive ter-grup** — Menu Archive saat ini menampilkan **satu baris per order-detail**, sehingga satu naskah kolaborasi yang di-order oleh 10 author tampil sebagai 10 baris. Diubah menjadi **satu baris per judul** (kolom: Judul · Buku · Total Author · Status Progres · Handler · Update Terakhir · Aksi), dengan halaman detail yang menampilkan daftar penulis/order penyusunnya plus aksi ke Progress dan ke Order.
2. **Penataan ulang sidebar** — Sidebar dirombak jadi lebih profesional/rapi: kategori per domain, nama Indonesia konsisten, ikon berbeda per item, submenu collapsible, guard role dirapikan, blok komentar mati dihapus.
3. **Pemulihan DataTables** — Mengembalikan init DataTables standar aplikasi (search + sort kolom + pagination + styling select2) di tabel Archive, menggantikan konfigurasi `searching:false, ordering:false` + form filter server yang dipakai sekarang.

---

## Keputusan Desain (hasil brainstorming)

| Topik | Keputusan |
|-------|-----------|
| Kunci penggabungan judul | **Normalisasi judul** (huruf kecil, spasi dirapikan, tanda baca diabaikan) + kelas pipeline (buku/artikel). Otomatis menyatukan varian ejaan (mis. "Malay-Indonesian" vs "Malay Indonesian"). Tanpa ubah skema. |
| Status agregat | **Bottleneck** — tahap PALING awal di antara semua order dalam grup. Jika status beragam, tampilkan hint `(beragam)`. |
| Handler | Role sesuai tahap bottleneck (`TitleProgress::STAGE_HANDLER`). |
| Update Terakhir | `updated_at` **paling baru** (max) di seluruh grup. |
| Judul kanonik (label grup) | Varian dari order **paling baru** (max `updated_at`). |
| Aksi di detail grup | **Dua tombol** per baris: `Progress` (timeline/update status per-order) + `Order` (`order.book.show`). |
| Sidebar | **Tata ulang penuh + rename** (bukan sekadar rename). |
| DataTables | Pulihkan **init standar penuh**; hapus form filter server. |
| Skema DB | **Tidak ada migrasi** — grouping di-compute saat runtime. |

---

## 1. Archive Ter-grup

### 1.1 Normalisasi & kunci grup

Fungsi normalisasi judul (helper privat di controller atau di model `OrderDetail`):

```php
// Contoh implementasi
public static function normalizeTitle(string $title): string
{
    return \Illuminate\Support\Str::of($title)
        ->lower()
        ->replaceMatches('/[^\p{L}\p{N}\s]+/u', ' ') // buang tanda baca
        ->replaceMatches('/\s+/u', ' ')              // rapikan spasi
        ->trim()
        ->value();
}
```

Kunci grup = `normalizeTitle(title)` **+** kelas pipeline:
- Kelas pipeline `buku` bila `type ∈ {bk_mandiri, bk_kolab}`, selain itu `artikel` (`at_*`).
- Tujuan: mencegah judul ternormalisasi yang kebetulan sama tapi beda pipeline tergabung.

### 1.2 Ranking tahap (untuk bottleneck)

Memakai konstanta yang sudah ada di `TitleProgress`:
- `BOOK_STAGES` = `[menunggu_proses, editing, layout, proofreading, isbn, cetak, terbit]`
- `ARTICLE_STAGES` = `[menunggu_proses, templating, editing, revisi, submit, loa, publish]`

Index tahap = `array_search($status, $stages)`. **Bottleneck = tahap dengan index terkecil** di antara semua order dalam grup. Order **tanpa** `TitleProgress` (data lama) dianggap `menunggu_proses` (index 0) → otomatis jadi bottleneck. Flag `is_mixed = (jumlah status unik dalam grup) > 1`.

### 1.3 Controller `OrderBookController@indexJudul`

Mengganti query `groupBy` SQL yang ada dengan: ambil baris mentah lalu grup di Collection.

1. Query `OrderDetail` dengan eager-load `order.user`, `authors`, `titleProgress`; terapkan scope role marketing (`where tb_orders.user_id = Auth::id()` bila role `marketing`) — sama seperti sekarang.
2. `groupBy(fn($d) => $pipelineClass($d->type).'|'.normalizeTitle($d->title))`.
3. Untuk tiap grup hitung objek baris ringkas:
   - `detail_id_repr` = id detail dengan `updated_at` terbaru (untuk link Detail + judul kanonik).
   - `title` = judul detail perwakilan tsb.
   - `type_label` = Buku/Artikel.
   - `total_author` = jumlah author **unik** (distinct `author_id`) di seluruh detail grup.
   - `bottleneck_status` = status dengan index terkecil sesuai pipeline.
   - `handler` = `TitleProgress::getHandlerForStatus(bottleneck_status)`.
   - `last_update` = max `updated_at` titleProgress (fallback `order_detail.updated_at`).
   - `is_mixed` = bool.
4. Urutkan koleksi grup berdasarkan `last_update` desc (default; DataTables tetap bisa re-sort).
5. Kirim ke view `orders.index-title` sebagai `judulData`.

> Filter `tahun/tipe/status` server-side **dihapus** (digantikan search/sort DataTables). Variabel `tahunList` tidak lagi diperlukan.

### 1.4 View `orders/index-title.blade.php`

Tabel kolom: `No | Judul | Buku | Total Author | Status Progres | Handler | Update Terakhir | Aksi`.
- Status Progres = badge warna (peta warna yang sudah ada) + label tahap; bila `is_mixed`, tambah `<span class="badge bg-light text-muted">beragam</span>`.
- Update Terakhir = `diffForHumans()`.
- Aksi = tombol **Detail** → `route('order.indexJudul.detail', $row->detail_id_repr)`.
- Hapus form filter GET (`search/tipe/status/tahun`).
- Init DataTables: lihat Bagian 4.

---

## 2. Halaman Detail Grup

### 2.1 Routing

`routes/web.php`, dalam grup `prefix('management')->name('order.')`:

```php
// Detail GRUP (judul + daftar penulis/order) — repurpose detailJudul
Route::get('title/details/{id}', [OrderBookController::class, 'detailJudul'])
    ->name('indexJudul.detail');

// BARU: detail PROGRESS per-order (timeline + update status + log)
Route::get('title/order/{id}', [OrderBookController::class, 'progressDetail'])
    ->name('indexJudul.progress');
```

### 2.2 Controller

- `detailJudul($id)` **dialihfungsikan** menjadi tampilan grup:
  1. Muat detail `{id}`, terapkan scope role marketing (404 bila bukan miliknya).
  2. Hitung kunci grup (pipeline + normalizeTitle) dari detail tsb.
  3. Ambil **semua** `OrderDetail` saudara dengan kunci grup sama (eager-load `order.user`, `authors`, `titleProgress`), tetap menghormati scope role marketing.
  4. Hitung header agregat (judul kanonik, type_label, total_author unik, bottleneck_status, is_mixed, last_update) — logika sama dengan Bagian 1.
  5. Render view baru `orders.detail-title-group` dengan koleksi order + data agregat.
- `progressDetail($id)` = **isi `detailJudul` yang lama** (termasuk fallback auto-create `TitleProgress` untuk data lama) → render view `orders.detail-title` yang sekarang (timeline + form update status + daftar penulis order itu + log). **Tidak ada perubahan** pada `orders/detail-title.blade.php`.

### 2.3 View baru `orders/detail-title-group.blade.php`

- **Header**: judul kanonik, badge tipe (Buku/Artikel), Total Author, badge status agregat (bottleneck + hint `beragam`), Update Terakhir.
- **Tabel order/penulis**: `No | Penulis | Posisi | Kode Order | Marketing | Status order | Update | Aksi`.
  - Satu baris per order-detail dalam grup. Bila satu detail punya >1 author (co-author), tampilkan nama-nama author detail tsb (pivot `position`).
  - Status order = badge status `titleProgress` masing-masing.
  - Aksi = `Progress` → `route('order.indexJudul.progress', $detail->id)` · `Order` → `route('order.book.show', $detail->order->code_order)`.

---

## 3. Penataan Ulang Sidebar

File: `resources/views/layouts/sidebar.blade.php`. Tetap memakai kelas/komponen NobleUI yang ada (`nav-item`, `nav-category`, `collapse`, `data-feather`, helper `active_class`/`is_active_route`/`show_class`). Hapus seluruh blok komentar mati.

Struktur final:

```
MENU UTAMA
  • Dashboard                                    (semua role)

ORDER & NASKAH                                   (superadmin, manager, marketing)
  • Buat Order        ▸ Buku · Jurnal            (collapsible)
  • Daftar Order      → order.book.index
  • Arsip Judul       → order.book.indexJudul

PEMBAYARAN
  • Pembayaran        ▸ DP/Tagihan · Pelunasan · Disetujui   (collapsible)
  • Invoice           → invoice.index

LAPORAN
  • Pendapatan        ▸ Order · Payment · Pending · Lunas     (collapsible)

AKUN
  • Manajemen User    → user.management          (superadmin, manager)
  • Profil            → profile                  (semua role)
```

Catatan:
- Ikon feather berbeda per item (mis. `box`, `shopping-cart`, `list`, `archive`, `credit-card`, `file-text`, `bar-chart-2`, `users`, `user`).
- Guard role memakai `@role`/`@hasanyrole` seperti pola yang sudah ada.
- Nama route TIDAK berubah; hanya label, urutan, pengelompokan, dan ikon.
- Blok `<nav class="massege">` (notifikasi flash) di bawah dibiarkan apa adanya.

---

## 4. Pemulihan DataTables (tabel Archive)

Di `orders/index-title.blade.php`, kembalikan init standar (samakan dengan `orders/book/index.blade.php`):

```js
$(function () {
    $(".datatable").DataTable({
        pageLength: 10,
        order: [[1, "asc"]],
    });
    $(".dataTables_length select, .dataTables_filter input").addClass("form-control mb-2");
    $('.custom-select').select2();
});
```

- Hapus opsi `searching:false, ordering:false` dan `pageLength: 25`.
- Pastikan aset plugin DataTables (css/js, responsive) tetap di-`@push` (sudah ada).
- Search + sort + pagination berjalan client-side di atas baris grup yang dirender server.

---

## Non-Goal

- Tidak ada migrasi/perubahan skema DB.
- Tidak mengubah alur pembuatan order maupun mekanisme update status per-order.
- Tidak menerapkan DataTables server-side (AJAX).
- Tidak menambah entitas "Title Group" eksplisit (ditolak saat brainstorming).
- Tidak menyentuh fungsi controller lain di luar `indexJudul`/`detailJudul`/`progressDetail`.

---

## Edge Case

- **Detail tanpa `TitleProgress`** (data lama) → dianggap `menunggu_proses`; di halaman progress, fallback auto-create tetap berjalan (`progressDetail`).
- **Author duplikat lintas detail** dalam satu grup → Total Author dihitung distinct.
- **Tipe campuran** dengan judul ternormalisasi sama (buku & artikel) → tetap terpisah karena kunci menyertakan kelas pipeline.
- **Role marketing** → baik daftar maupun detail grup hanya mencakup order miliknya; Total Author/agregat dihitung dari subset miliknya.
- **Judul kanonik** → diambil dari order paling baru; bila seri `updated_at`, ambil `id` terbesar.

---

## Pengujian

**Feature test (`tests/Feature`):**
- `indexJudul`: dua order-detail dengan judul ternormalisasi sama (varian ejaan) → **1 baris**, `total_author` benar, `bottleneck_status` = tahap paling awal, `is_mixed` benar.
- `indexJudul`: dua tipe berbeda (buku vs artikel) dengan judul sama → **2 baris**.
- `detailJudul`: menampilkan semua order/penulis dalam grup; link Progress & Order benar.
- `progressDetail`: merender timeline per-order + auto-create progress untuk data lama.
- Scope role `marketing`: hanya order sendiri yang tampil di daftar & detail.

**Cek manual:**
- DataTables: search, sort tiap kolom, pagination, select2 berjalan.
- Sidebar tampil benar per role (superadmin/manager/marketing); link aktif ter-highlight.
