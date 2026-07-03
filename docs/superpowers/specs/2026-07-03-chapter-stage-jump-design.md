# Spec — UI Lompat/Mundur Tahap Bab (Chapter Stage Jump)

- **Tanggal:** 2026-07-03
- **Branch:** `chapter-stage-jump`
- **Scope:** Beri UI di panel bab papan manuskrip (`manuscript/partials/card.blade.php`) untuk memindahkan tahap sebuah **bab buku** ke tahap tujuan mana pun (maju-lompat / mundur), dengan catatan. Backend (`ChapterManuscriptService::changeStatus` + endpoint `chapter.advance`) **sudah** mendukung ini; fitur murni penambahan UI.
- **Di luar scope (sengaja):** perubahan service/route/controller; artikel utuh (sudah bisa pindah arah via drag kanban + `manuscript.move`); pembatasan tahap per-role di klien (server tetap penjaga akhir).

> Lanjutan Manuskrip per Bab 3b. Papan bab kini hanya punya tombol satu-klik **"Maju → next"** (kirim `status=next`, tanpa catatan). Tak ada cara memilih tahap tujuan (mundur/lompat) atau memberi catatan dari UI, padahal `changeStatus` sudah menerima keduanya.

---

## 1. Tujuan & Kriteria Sukses

1. Pengelola papan (superadmin/manager/production) dapat memindahkan bab buku ke tahap tujuan mana pun (maju-lompat atau mundur) dari panel bab, disertai catatan.
2. Jalur cepat **"Maju → next"** yang ada tetap berfungsi tak berubah.
3. Catatan **wajib** saat tahap tujuan ≠ next (mundur/lompat) — divalidasi di UI (cermin aturan server) dan ditegakkan server.
4. Otorisasi lama tetap berlaku: production hanya boleh memindahkan bab yang handler tahap **saat ini**-nya = production; superadmin/manager bebas. Penolakan server tampil sebagai toast merah.
5. Lompatan oleh non-superadmin tetap menandai `needs_review` + badge "⚑ tinjau" (perilaku `changeStatus` yang sudah ada).
6. Perilaku tertutup test; suite tetap hijau.

## 2. Konteks Backend (tak diubah — verifikasi saja)

- **`ChapterManuscriptService::changeStatus(ChapterProgress $cp, string $target, User $actor, ?string $note = null)`**: validasi `$target ∈ BOOK_STAGES`; hitung `next`; `$isCorrection = ($target !== $next)`; `authorize()` (superadmin/manager bebas; production hanya bila `getHandlerForStatus($current) === 'production'`); bila koreksi & catatan kosong → `ValidationException` "Catatan wajib untuk koreksi/lompat."; set `needs_review = $isCorrection && ! superadmin`; log ke `TitleProgressLog`; `syncBookStatus` (roll-up bottleneck).
- **Endpoint** `POST management/manuscript/chapter/{id}/advance` (name `chapter.advance`, middleware role `superadmin|manager|production`) → `ChapterProgressController@advance` membaca `status` + `note`, memanggil `changeStatus`, mengembalikan JSON `{ok, id, status, message}` atau redirect (pola `runOrFlash`; melempar `ValidationException|AuthorizationException` saat `expectsJson`).
- **Tahap buku** `TitleProgress::BOOK_STAGES = [menunggu_proses, editing, layout, proofreading, isbn, cetak, terbit]`. Label via `Title::stageLabel($status)`. `FINAL_STAGES = [terbit, publish]`.

## 3. UI — `manuscript/partials/card.blade.php` (panel bab)

Di tiap baris bab (dalam blok `@if($cp)`), selain tombol **"Maju → {{ next }}"** yang ada, tambah tombol:

```blade
<button type="button" class="btn btn-xs btn-outline-secondary py-0"
        data-chapter-edit data-cp="{{ $cp->id }}"
        data-current="{{ $cstatus }}" data-next="{{ $cnext ?? '' }}"
        data-judul="{{ $ch->urutan }}. {{ $ch->judul }}"
        style="font-size:10px">Ubah…</button>
```

Tombol "Ubah…" muncul selama `$cp` ada (termasuk saat bab sudah di tahap akhir, agar bisa mundur).

## 4. UI — `manuscript/board.blade.php` (modal + JS)

- **Suntik label tahap sekali** (di `@push('scripts')`, sebelum handler):
  ```blade
  const CHAPTER_STAGES = @json(collect(\App\Models\TitleProgress::BOOK_STAGES)
      ->map(fn ($s) => ['value' => $s, 'label' => \App\Models\Title::stageLabel($s)])->values());
  ```
- **Handler klik** `[data-chapter-edit]` → `Swal.fire`:
  - `title`: `'Ubah tahap: ' + judul`.
  - `html`: `<select id="swal-stage">` berisi semua `CHAPTER_STAGES` **kecuali** `current` (label = `label`, value = `value`), + `<textarea id="swal-note">`.
  - `showCancelButton: true`, `confirmButtonText: 'Simpan'`, `cancelButtonText: 'Batal'`.
  - `preConfirm`: baca `target = #swal-stage.value`, `note = #swal-note.value.trim()`; bila `target !== next && note === ''` → `Swal.showValidationMessage('Catatan wajib untuk lompat/mundur.')` dan return `false`; else return `{target, note}`.
  - `.then` bila `result.isConfirmed`: `fetch(base + '/chapter/' + cp + '/advance', {POST, headers JSON+CSRF+X-Requested-With, body: JSON {status: target, note}})`; sukses → `toast(msg, true)` + `setTimeout(location.reload, 500)`; gagal → `toast(err.message, false)` (pola identik handler `data-chapter-advance` yang sudah ada).
- **`base`, `token`, `toast`** sudah tersedia di script papan (dipakai handler advance/assign existing). SweetAlert2 (`Swal`) sudah global di template.

## 5. Rencana Test

- **Feature `ChapterStageJumpTest`** (endpoint `chapter.advance`, JSON):
  - `manager_moves_chapter_backward_with_note`: bab di `layout` → POST `{status:'editing', note:'perbaikan'}` sebagai manager → 200 `ok`; `ChapterProgress` jadi `editing`, `note` tersimpan, `needs_review = true` (manager non-superadmin → correction).
  - `jump_without_note_is_rejected`: manager POST `{status:'terbit'}` (tanpa note, target≠next) → 422 (JSON) dengan pesan catatan wajib; status tak berubah.
  - `production_cannot_move_superadmin_stage_chapter`: bab di `cetak` (handler superadmin), production POST `{status:'isbn', note:'x'}` → 403; status tak berubah.
  - `board_renders_ubah_control_for_book_chapter`: GET papan buku (manager) → `assertSee('data-chapter-edit')`.
- **Regresi**: `ChapterManuscriptServiceTest` (jump/mundur/note/otorisasi) tetap hijau; `php artisan view:cache` bersih.

Suite via DB test (`.env.testing`), `GoogleDriveService` di-mock. **Tanpa migrasi** (tak ada perubahan skema). Lihat [[testing-setup]].

## 6. Komponen

- **Baru:** test `tests/Feature/ChapterStageJumpTest.php`; spec ini.
- **Diubah:** `resources/views/manuscript/partials/card.blade.php` (tombol "Ubah…" per bab); `resources/views/manuscript/board.blade.php` (`CHAPTER_STAGES` + handler modal `[data-chapter-edit]`).
- **Tak diubah (dipakai apa adanya):** `ChapterManuscriptService`, `ChapterProgressController`, route `chapter.advance`.

## 7. Asumsi & Risiko

- Modal memuat semua tahap kecuali tahap kini; tak ada penyaringan per-role di klien → user production yang memilih tahap terlarang mendapat toast merah dari server (konsisten dengan tombol "Maju → next" yang juga tak menyaring role di klien).
- Catatan wajib di UI hanya saat target ≠ next; bila target = next (sama seperti quick-advance), catatan opsional — server menerima tanpa catatan (bukan correction).
- Reload halaman pasca-sukses (v1, sama seperti aksi bab lain) → tak ada patch DOM parsial.
- SweetAlert2 tersedia global (dipakai `form[data-confirm]` + fitur lain); tak menambah aset.
