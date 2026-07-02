# Spec — Title Directory Fase 2a: Order ↔ Judul

- **Tanggal:** 2026-07-02
- **Branch:** `title-order-link`
- **Scope (Fase 2a):** Tautkan entitas **Judul** (`tb_titles`, Fase 1) ke alur **order**. Field judul di form order (buku & jurnal) berubah dari teks bebas menjadi **pilih judul `disetujui`** (select2) yang eligible, dengan **auto-isi** field order dari judul, dan solusi **"judul belum ada"** = ketik nama baru → judul dibuat inline (`asal=order`, `disetujui`). `OrderDetail` mendapat `title_id`. Direktori Judul menampilkan **jml order** & **jml author** (turunan dari order).
- **Di luar scope (sengaja):** manuskrip (`TitleProgress`) pindah dari `group_key` ke `title_id` → **Fase 2b**; backfill order lama ke judul; direktori Jurnal/ISBN/HKI; author/status per-bab; perubahan alur invoice/pembayaran.

> Lanjutan [Fase 1](2026-07-02-title-directory-design.md). Skema Fase 1 menyiapkan `Title.asal` (distribusi/order) untuk ini. Judul lama tetap kompatibel: `OrderDetail.title` (string) **dipertahankan**, sehingga `group_key`, manuskrip, dan Arsip Judul tak berubah di 2a.

---

## 1. Latar Belakang

Saat ini order menyimpan judul sebagai **teks bebas** di `OrderDetail.title` (+ `type`, `scope`, `authors`, `cost`), lalu auto-membuat manuskrip (`TitleProgress`) via `TitleProgressService::createForDetail`. Pengelompokan judul memakai `group_key` **turunan** (`type`+`title`) — bukan FK ke judul. Fase 1 membuat `tb_titles` berdiri sendiri (disetujui → kolam judul untuk marketing) tapi belum tersambung ke order. Fase 2a menyambungkannya di sisi order: marketing memilih judul yang sudah disetujui (atau membuatnya saat itu juga), dan judul menampilkan berapa order & author yang menautnya.

## 2. Tujuan & Kriteria Sukses

1. Form order (buku & jurnal, create & edit) memilih judul dari daftar **eligible** (jenis cocok + distribusi ke marketing itu + `disetujui`); memilih judul **mengisi otomatis** tipe/scope/indeksasi (masih bisa diubah).
2. "Judul belum ada" tertangani: marketing mengetik nama baru → `Title` dibuat (`asal=order`, `disetujui`, dibuat oleh marketing) dari field order; order lanjut tanpa terblok.
3. `OrderDetail.title_id` terisi untuk order baru; `OrderDetail.title` (string) tetap terisi (kompatibilitas `group_key`/manuskrip/arsip).
4. Direktori Judul menampilkan **jml order** & **jml author** turunan, plus daftar ringkas order tertaut di detail.
5. Perilaku tertutup test; seluruh suite tetap hijau (302 + test baru).

---

## 3. Data Model

- **Migrasi** `2026_07_02_000005_add_title_id_to_tb_order_details.php`: tambah kolom `title_id` (`foreignId` nullable, `after('type')`, `constrained('tb_titles')->nullOnDelete()`) di `tb_order_details`. Baris lama null.
- **`OrderDetail`**: tambah `title_id` ke `$fillable`; relasi `titleRef()` `belongsTo(Title::class, 'title_id')` (nama `titleRef` agar tak bentrok dengan atribut string `title`). `booted()` `saving` hook (group_key dari type+title) **tetap** — `title` string masih diisi.
- **`Title`**: relasi `orderDetails()` `hasMany(OrderDetail::class, 'title_id')`. Hitungan turunan:
  - `orders_count` = jumlah `tb_order_details` dengan `title_id` = judul.
  - `authors_count` = jumlah baris `tb_author_orders` yang `order_detail_id`-nya termasuk order detail tertaut judul.
  - Di index dipakai via query efisien (subselect/`withCount`), bukan loop N+1 (detail di plan).

## 4. UX Form Order (buku & jurnal)

Berlaku untuk `orders/book/create`, `orders/journal/create`, `orders/edit` (buku), `orders/journal/edit`.

- Field judul (input teks) → **`<select name="title_id" class="select2" data-tags="true">`**. Opsi = judul eligible (lihat §5.1), tiap opsi membawa atribut data untuk auto-isi: `data-tipe-naskah` (mandiri/kolaborasi), `data-scope-id`, `data-scope-name`, `data-indeksasi`.
- **Tags aktif** → marketing bisa mengetik nama judul baru (nilai tag = string nama).
- **JS**: saat opsi judul lama dipilih → set `type` (buku: `bk_mandiri`/`bk_kolab`; jurnal: `at_mandiri`/`at_kolab` dari `tipe_naskah`), set select `scope_id` (atau tambahkan opsi bila belum ada), set `indeksasi`. Semua field ini **tetap enabled** (boleh diubah). Saat mengetik nama baru → field order dibiarkan apa adanya (marketing mengisi manual; nilainya mengalir ke judul baru saat submit).
- Sisa form (authors, cost, contact, tanggal, chapters) **tak berubah**.

## 5. Controller & Service

### 5.1 Eligibility (dropdown)
- `OrderBookController@create`/`@edit`: kirim `$titles` = `Title` `disetujui`, `jenis='buku'`, dan (`assigned_to` null **atau** = `Auth::id()`) bila aktor **marketing**; manager/superadmin → semua `disetujui` jenis=buku. Eager `scope`.
- `OrderJournalController@create`/`@edit`: sama, `jenis='artikel'`.

### 5.2 Resolusi (`TitleService::resolveForOrder`)
`resolveForOrder(int|string $value, array $ctx, User $actor): Title` di mana `$ctx = ['jenis'=>'buku'|'artikel', 'order_type'=>'bk_mandiri'|…, 'scope_id'=>?int, 'indeksasi'=>?string]`:
- Bila `$value` numerik & ada `Title` eligible ber-id itu → kembalikan judul tsb (tak buat baru).
- Selain itu (nama baru) → `Title::create` dengan `title`=$value, `jenis`=$ctx.jenis, `tipe_naskah`= (`*_kolab` → `kolaborasi`, else `mandiri`), `scope_id`=$ctx.scope_id, `indeksasi`=$ctx.indeksasi, `status='disetujui'`, `asal='order'`, `created_by`/`approved_by`=$actor->id, `approved_at`=now().
- Kembalikan `Title`.

### 5.3 Store/Update order (buku & jurnal)
- Validasi: ganti `'title' => 'required|string…'` → `'title_id' => 'required|string|max:255'` (menampung id lama atau nama baru).
- Setelah resolusi scope (`scope_id`, logika firstOrCreate yang sudah ada) → panggil `resolveForOrder(...)` dengan `scope_id` hasil resolusi + `indeksasi` (bila form order punya; buku tak punya indeksasi field → kirim null; jurnal bila ada). Set `OrderDetail.title_id = $title->id`, `OrderDetail.title = $title->title`. Cek duplikat (judul+email) pakai `$title->title`.
- Update (edit) order: resolusi ulang → set `title_id` + `title`.

> Catatan: order buku form saat ini tak punya field `indeksasi` terpisah (indeksasi ada di jurnal). `indeksasi` untuk judul dari order buku = null (bisa dilengkapi di direktori). Konsisten dengan data yang tersedia.

## 6. Direktori Judul (tampilan turunan)

- `TitleController@index`: sertakan `orders_count` & `authors_count` (query efisien) untuk baris. Tambah kolom **Jml Order** & **Jml Author** di `titles/index.blade.php` (setelah Tipe).
- `TitleController@show`: tampilkan `orders_count`/`authors_count` + daftar ringkas **order tertaut** (kode order, nama marketing/pembuat order, tanggal) dari `orderDetails.order.user`.

## 7. Komponen yang Disentuh

- **Baru:** migrasi `add_title_id_to_tb_order_details`; `TitleService::resolveForOrder()`; test `TitleOrderLinkTest`.
- **Diubah:** `app/Models/OrderDetail.php` (+title_id/relasi), `app/Models/Title.php` (+orderDetails/hitungan), `OrderBookController` + `OrderJournalController` (create/edit kirim `$titles`; store/update resolusi title_id), `resources/views/orders/book/{create,edit}.blade.php` + `orders/journal/{create,edit}.blade.php` (select2 judul + JS auto-isi), `resources/views/orders/edit.blade.php` bila dipakai buku, `app/Http/Controllers/Pages/TitleController.php` (index/show counts), `resources/views/titles/{index,show}.blade.php` (kolom & daftar order). Sesuaikan test endpoint order yang mem-post `title`.

## 8. Rencana Test

- **`TitleOrderLinkTest` (baru)**:
  - Store order buku dgn `title_id` = judul buku disetujui → `OrderDetail.title_id` terisi, `title` string tersalin, **tak** ada `Title` baru.
  - Store order buku dgn nama judul baru → `Title` baru (`asal=order`, `disetujui`, `jenis=buku`, `tipe_naskah` sesuai tipe order, scope tertaut) + `OrderDetail.title_id` terisi.
  - Store order jurnal dgn nama baru → `Title` `jenis=artikel`.
  - Eligibility: `GET order.book.create` (marketing) hanya memuat judul buku disetujui yang tak di-assign / di-assign ke dia (assert see/dontSee); judul artikel tak muncul di form buku.
  - Direktori: setelah 2 order menaut 1 judul, `orders_count`/`authors_count` benar di index/show.
- **Regresi**: `DetailOrderPaymentInvoiceTest`, `OrderJournalEditTest`, `TagihanTypeTest`, `ManuscriptTrackerTest`, `ArchiveGroupedTitlesTest`, `TitleProgress*`, dll tetap hijau; sesuaikan test yang mem-post `title` ke `title_id`. `php artisan view:cache` bersih.

Suite via DB test (`.env.testing`), `GoogleDriveService` di-mock. **Dev/prod: `php artisan migrate`** untuk kolom `title_id` (lihat [[migrate-dev-db-after-new-migration]]).

## 9. Asumsi & Risiko

- `OrderDetail.title` string dipertahankan → `group_key`/manuskrip/arsip tak berubah (mitigasi risiko regresi terbesar). Manuskrip pakai `title_id` menyusul di **Fase 2b**.
- Judul inline dari order langsung `disetujui` (order = otorisasi); tercatat `asal=order` untuk audit. Bisa memunculkan judul mirip/duplikat bila marketing mengetik ulang — penggabungan judul di luar scope 2a.
- Order buku tak punya indeksasi → judul dari order buku ber-indeksasi null (dilengkapi di direktori).
- Perubahan kontrak form order (`title` → `title_id`) menyentuh sedikit test endpoint order; `OrderDetail::create(['title'=>…])` di test lain tetap valid (kolom tetap ada).
- Eligibility marketing memakai aturan distribusi Fase 1; manager/superadmin melihat semua judul disetujui pada form order.
