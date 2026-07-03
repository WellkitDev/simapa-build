# Spec — Author per Bab (Per-Chapter Authors)

- **Tanggal:** 2026-07-03
- **Branch:** `chapter-authors`
- **Scope:** Tiap **bab buku** (`tb_title_chapters`) dapat punya **author sendiri** (banyak, terurut) — untuk bunga rampai / edited volume. Dikelola via panel **"Bab & Author"** di halaman detail judul (`/titles/{id}`, hanya `jenis=buku`) oleh superadmin/manager/admin; papan manuskrip & daftar bab hanya **menampilkan** (read). Reuse model `Author`.
- **Di luar scope (sengaja):** CRUD Author penuh (author dibuat name-only di sini; edit email/afiliasi via jalur lain); peran/kontribusi author per bab; author untuk artikel (tetap level order via `tb_author_orders`).

> Lanjutan Manuskrip per Bab (`tb_title_chapters` + `ChapterProgress`). Author saat ini di level order (`tb_author_orders` per `OrderDetail`, `Author` = name/email/phone/affiliation). Fitur ini menambah relasi bab↔author independen (metadata editorial), tanpa mengubah author level order.

---

## 1. Tujuan & Kriteria Sukses

1. Superadmin/manager/admin dapat menetapkan **beberapa author** (terurut) per bab buku di panel detail judul, kapan pun (tak tergantung status judul).
2. Author dipilih dari `Author` yang ada (select2) atau **ketik nama baru** → `Author` baru (name-only).
3. Papan manuskrip (expand-panel bab) & daftar bab read-only menampilkan nama author tiap bab.
4. Non-pengelola tak bisa mengubah (403); alur author level order & manuskrip tak berubah.
5. Perilaku tertutup test; suite tetap hijau.

## 2. Data Model

- **Migrasi** `2026_07_03_000002_create_tb_title_chapter_authors_table.php`:
  ```php
  Schema::create('tb_title_chapter_authors', function (Blueprint $table) {
      $table->id();
      $table->foreignId('title_chapter_id')->constrained('tb_title_chapters')->cascadeOnDelete();
      $table->foreignId('author_id')->constrained('tb_authors')->cascadeOnDelete();
      $table->unsignedInteger('position')->default(1);
      $table->timestamps();
  });
  ```
- **`TitleChapter`**: relasi `authors()` `belongsToMany(Author::class, 'tb_title_chapter_authors', 'title_chapter_id', 'author_id')->withPivot('position')->orderByPivot('position')`.
- **`Author`**: relasi `chapters()` `belongsToMany(TitleChapter::class, 'tb_title_chapter_authors', 'author_id', 'title_chapter_id')`.

## 3. Simpan — `ChapterAuthorService` (atau method `TitleService`)

`syncChapterAuthors(Title $book, array $chapterAuthors): void` (`$chapterAuthors = [title_chapter_id => [authorRef, …]]`, authorRef = id existing atau string nama baru):
- Hanya `jenis=buku`.
- **Iterasi SEMUA bab milik `$book`** (panel menampilkan semua bab, jadi bersifat otoritatif): `authors = $chapterAuthors[$chapter->id] ?? []` (bab yang select-nya dikosongkan tak terkirim browser → `[]` → **author bab dikosongkan**). Untuk tiap authorRef: numerik → id existing; string non-kosong → `Author::firstOrCreate(['name'=>trim])` (name-only). Lalu `chapter->authors()->sync([...])` dengan `position` = urutan pilihan (1-based). Abaikan `title_chapter_id` di payload yang bukan bab `$book` (keamanan).

> Dedup author baru by `name` (`firstOrCreate`) agar tak menumpuk duplikat saat ketik nama sama; author existing dipilih by id.

## 4. Controller & Route — `TitleController`

- Route `PUT titles/{id}/chapter-authors` name `title.chapters.authors`, middleware `role:superadmin|manager|admin`.
- `TitleController@updateChapterAuthors(Request, $id)`: abort_unless pengelola; `Title::findOrFail`; validasi `chapter_authors` nullable array, `chapter_authors.*` nullable array, `chapter_authors.*.*` nullable string (id atau nama); panggil `syncChapterAuthors`; redirect `title.show` + flash.
- `TitleController@show`: eager-load `chapters.authors` + (untuk board tak berubah); kirim daftar `Author` (`$authors = Author::orderBy('name')->get()`) untuk select2.

## 5. View

- **`titles/show.blade.php`** — seksi **"Bab & Author"** (bila `jenis=buku`, dalam kartu; tampil bila `canViewInfo` = superadmin/manager/admin/production):
  - **Read**: tiap bab `{{ $ch->urutan }}. {{ $ch->judul }}` → daftar nama author (`$ch->authors->pluck('name')->join(', ')` atau '—').
  - **Edit** (bila `canEditInfo` = superadmin/manager/admin): tombol "Edit Author Bab" → collapse form `PUT title.chapters.authors`; tiap bab satu `<select multiple class="select2-authors" name="chapter_authors[{{ $ch->id }}][]">` berisi Author existing (pre-selected bab) + `data-tags` (ketik nama baru). Init select2 (tags:true) — panel di collapse (bukan modal).
- **Manuscript board expand-panel** (`manuscript/partials/card.blade.php`, bagian bab): tampilkan nama author bab (read) di baris bab, mis. di bawah editor. Perlu `titleRef.chapters.authors` di eager-load `ManuscriptTrackerController::index` (tipe buku).

## 6. Rencana Test

- **Unit `ChapterAuthorServiceTest`**: sync author existing (id) → tertaut + position urut; nama baru → `Author` dibuat (name-only) + tertaut; re-sync mengganti set; buku dengan bab lain tak terpengaruh; non-buku diabaikan.
- **Feature `ChapterAuthorTest`**: manager `PUT title.chapters.authors` → pivot tersimpan; marketing → 403; `GET title.show` (buku) menampilkan nama author bab; (opsional) papan buku menampilkan author bab.
- **Regresi**: `TitlePublicationInfoTest`, `ChapterManuscriptServiceTest`, `ManuscriptTrackerTest` tetap hijau; `php artisan view:cache` bersih.

Suite via DB test (`.env.testing`), `GoogleDriveService` di-mock. **Dev/prod: `php artisan migrate`** untuk `tb_title_chapter_authors`. Lihat [[migrate-dev-db-after-new-migration]].

## 7. Komponen

- **Baru:** migrasi `create_tb_title_chapter_authors`; `app/Services/ChapterAuthorService.php`; test `ChapterAuthorServiceTest` + `ChapterAuthorTest`.
- **Diubah:** `app/Models/TitleChapter.php` (+authors), `app/Models/Author.php` (+chapters); `app/Http/Controllers/Pages/TitleController.php` (updateChapterAuthors + show eager-load/`$authors`); `routes/web.php` (route); `resources/views/titles/show.blade.php` (seksi Bab & Author + select2); `app/Http/Controllers/Pages/ManuscriptTrackerController.php` (eager-load authors, tipe buku) + `resources/views/manuscript/partials/card.blade.php` (tampil author bab).

## 8. Asumsi & Risiko

- Author per bab = pivot independen dari author level order; tak mengubah `tb_author_orders`.
- Author baru dibuat name-only (`firstOrCreate` by name) → dedup nama; email/afiliasi kosong (dilengkapi di luar scope).
- Seksi kelola di panel detail judul (bukan modal) → select2 tags init biasa; per bab satu multi-select (jumlah bab modest).
- Papan hanya menampilkan author bab (read); pengelolaan tunggal di detail judul → hindari UI ganda.
- Bab tak bernama/auto-generate (dari Manuskrip per Bab) tetap bisa diberi author.
