# Spec — Journal Epic Fase C: Opsi Jurnal Panel Publikasi ↔ Direktori Jurnal

- **Tanggal:** 2026-07-03
- **Branch:** `journal-panel-link`
- **Scope (C):** Repeater **"Opsi Jurnal Lain"** di panel Informasi Publikasi judul (`/titles/{id}`) dapat **memilih jurnal dari direktori** (`tb_journals`, select2) selain teks bebas. Bila jurnal dipilih, `nama/link/apc` disnapshot dari `Journal` + `journal_id` disimpan; tampilan menautkan ke detail jurnal. Penutup epik jurnal (A direktori, B submission, **C integrasi panel**).
- **Di luar scope (sengaja):** migrasi otomatis opsi lama (teks) → journal_id; mengubah field `jurnal_target`/`jurnal_link`/`template_link` utama panel (tetap teks) — hanya "opsi jurnal lain" yang di-integrasikan.

> `TitleJournalOption` (tb_title_journal_options: title_id, nama_jurnal, link, apc, urutan) sudah ada dari panel publikasi (Fase kode+panel). `Journal` (tb_journals) dari Direktori Jurnal (A). Integrasi ini menautkan keduanya secara opsional (hybrid), backward-compatible dengan opsi teks lama.

---

## 1. Tujuan & Kriteria Sukses

1. Saat mengedit panel publikasi judul (pengelola: superadmin/manager/admin), tiap baris "Opsi Jurnal Lain" bisa **memilih jurnal dari `tb_journals`** (select2) atau tetap **ketik teks bebas** (jurnal belum di direktori).
2. Baris ber-`journal_id` menyimpan snapshot `nama/link/apc` dari `Journal` (nama, link, apc_reguler) + `journal_id`; tampilan panel menautkan nama ke detail jurnal.
3. Opsi teks lama tetap berfungsi (backward-compatible); tak ada migrasi data wajib.
4. Perilaku tertutup test; suite tetap hijau.

## 2. Data Model

- **Migrasi** `2026_07_03_000001_add_journal_id_to_tb_title_journal_options.php`: tambah `journal_id` (`foreignId` nullable, `constrained('tb_journals')->nullOnDelete()`, `after('title_id')`).
- **`App\Models\TitleJournalOption`**: tambah `'journal_id'` ke `$fillable`; relasi `journal()` `belongsTo(Journal::class)`.

## 3. Simpan — `TitleService::updateInfo`

Di loop sync opsi jurnal (yang kini `create(['nama_jurnal'=>…, 'link'=>…, 'apc'=>…, 'urutan'=>$i++])`), ubah tiap iterasi:
- `$journalId = ! empty($opt['journal_id']) ? (int) $opt['journal_id'] : null;`
- `$journal = $journalId ? Journal::find($journalId) : null;`
- `$nama = $journal ? $journal->nama : trim((string) ($opt['nama_jurnal'] ?? ''));`
- Bila `$nama === ''` → skip.
- `create([ 'journal_id'=>$journal?->id, 'nama_jurnal'=>$nama, 'link'=>$journal?->link ?? ($opt['link'] ?? null), 'apc'=>$journal ? $journal->apc_reguler : ($opt['apc'] ?? null), 'urutan'=>$i++ ])`.

Deteksi perubahan (`$before`/`$after` snapshot string) tetap; boleh sertakan `journal_id` dalam string snapshot agar log akurat (opsional).

## 4. Controller — `TitleController@show` + `@updateInfo`

- **`show`**: kirim `$journals = \App\Models\Journal::orderBy('nama')->get()` ke view (untuk opsi select).
- **`updateInfo`**: tambah aturan validasi `'journal_options.*.journal_id' => 'nullable|integer|exists:tb_journals,id'` (aturan `nama_jurnal`/`link`/`apc` yang ada tetap).

## 5. View — Panel `titles/show.blade.php` (repeater `#joList` di `#infoForm`)

- Tiap baris repeater tambah **select** `journal_options[i][journal_id]` (class `select2-journal`) berisi opsi dari `$journals` (`<option value="{{ $j->id }}">{{ $j->nama }}</option>`), plus field teks `nama_jurnal`/`link`/`apc` yang sudah ada (label "atau ketik manual").
- **select2**: init pada baris awal + baris yang ditambah via tombol "+ Opsi Jurnal" (init select2 pada select baru setelah append). Panel di dalam collapse (bukan modal) → init select2 biasa; bila collapse belum tampil, init tetap aman (atau init on collapse shown). Sederhana: init semua `.select2-journal` yang belum ter-init saat load + saat menambah baris.
- **Tampilan read-only** (daftar "Opsi Jurnal Lain" di panel detail): bila `$opt->journal_id` & `$opt->journal` → nama sebagai `<a href="{{ route('journal.show', $opt->journal_id) }}">{{ $opt->nama_jurnal }}</a>` + badge kecil "direktori"; else teks biasa (perilaku sekarang). Link/apc tetap tampil.

## 6. Rencana Test

- **Feature `JournalPanelLinkTest`**:
  - manager `PUT title.info.update` dengan `journal_options[0][journal_id]` = id `Journal` → `TitleJournalOption` tersimpan dgn `journal_id` + `nama_jurnal`/`link`/`apc` = snapshot dari `Journal` (nama/link/apc_reguler).
  - opsi teks bebas (tanpa journal_id) tetap tersimpan seperti sekarang.
  - `GET title.show` menampilkan opsi ber-`journal_id` sebagai link ke `journal.show` (assertSee route/nama).
- **Regresi**: `TitlePublicationInfoTest` (updateInfo) tetap hijau; `php artisan view:cache` bersih.

Suite via DB test (`.env.testing`), `GoogleDriveService` di-mock. **Dev/prod: `php artisan migrate`** (kolom `journal_id`). Lihat [[migrate-dev-db-after-new-migration]].

## 7. Komponen

- **Baru:** migrasi `add_journal_id_to_tb_title_journal_options`; test `JournalPanelLinkTest`.
- **Diubah:** `app/Models/TitleJournalOption.php` (fillable + relasi); `app/Services/TitleService.php` (updateInfo loop opsi jurnal); `app/Http/Controllers/Pages/TitleController.php` (`show` kirim `$journals`; `updateInfo` validasi journal_id); `resources/views/titles/show.blade.php` (select journal di repeater + init select2 + tampilan link).

## 8. Asumsi & Risiko

- Hybrid: `journal_id` opsional; snapshot `nama/link/apc` dijaga agar tampilan tak hilang bila jurnal dihapus (`nullOnDelete` → journal_id null, snapshot tetap).
- select2 pada baris repeater dinamis: init ulang saat menambah baris (pola serupa sudah dipakai; panel bukan modal → tanpa `dropdownParent`).
- Opsi lama (teks) dibiarkan; pengelola dapat memilih jurnal direktori saat berikutnya mengedit.
- Tak menyentuh Fase A/B; hanya menautkan panel judul ke `tb_journals`.
