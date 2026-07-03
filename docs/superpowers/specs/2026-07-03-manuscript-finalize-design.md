# Spec — Finalisasi & Retensi Papan Manuskrip (B+C+D)

- **Tanggal:** 2026-07-03
- **Branch:** `manuscript-finalize`
- **Scope:** Dua aturan papan manuskrip: **(C) kunci naskah final** (`terbit`/`publish` tak bisa diubah kecuali superadmin) dan **(D) retensi 30 hari** (naskah final lepas dari papan setelah 30 hari, tetap di arsip). **(B)** (mundur dari `isbn` tetap boleh + tanda "perlu ditinjau") **sudah tersedia** via `needs_review` — didokumentasikan, tanpa kode baru.
- **Di luar scope (sengaja):** alur permintaan/approval mundur (ditolak — cukup `needs_review` existing); perubahan skema; menyembunyikan naskah final dari Arsip/Direktori (retensi hanya untuk papan).

> Lanjutan epik manuskrip. Otorisasi kini: `TitleProgressService::authorizeChange` (artikel) & `ChapterManuscriptService::authorize` (buku/bab) — superadmin & manager bebas; production hanya bila handler tahap kini = production. Papan dimuat `ManuscriptTrackerController::index`.

---

## 1. Tujuan & Kriteria Sukses

1. **(C)** Naskah pada tahap final (`terbit`/`publish`) tak dapat diubah (maju/mundur/lompat) oleh manager/production/admin dari papan; **superadmin tetap bisa** (escape hatch). Ditegakkan server; UI mencegah drag/aksi.
2. **(D)** Naskah final yang sudah **>30 hari** di tahap final tidak lagi tampil di papan (board/list/log), tetapi tetap ada di Arsip Judul & Direktori.
3. **(B)** Terdokumentasi sebagai perilaku existing: non-superadmin yang mundur/lompat → `needs_review=true` + badge "⚑ tinjau" (tanpa perubahan).
4. Perilaku baru tertutup test; suite tetap hijau. Tanpa migrasi.

## 2. (C) Kunci Final — Server

Kunci berlaku ketika **status kini** ∈ `TitleProgress::FINAL_STAGES` (`['terbit','publish']`). superadmin dikecualikan.

- **`app/Services/TitleProgressService.php` — `authorizeChange(User $actor, string $current)`** (baris ~101): sisipkan cek final **setelah** bypass superadmin, **sebelum** bypass manager:
  ```php
  if ($actor->hasRole('superadmin')) {
      return; // bebas
  }
  if (TitleProgress::isFinal($current)) {
      throw new AuthorizationException('Naskah sudah final dan terkunci.');
  }
  if ($actor->hasRole('manager')) { return; }
  // … production check … throw
  ```
  Ini mengunci artikel final untuk manager/production (via `changeStatus` & `changeGroupStatus`, keduanya memanggil `authorizeChange`).
- **`app/Services/ChapterManuscriptService.php` — `authorize(User $actor, string $current)`** (baris ~131): saat ini `if superadmin|manager return; if production&&handler=production return; throw`. **Restrukturisasi** agar final mengunci manager juga tetapi superadmin lolos:
  ```php
  if ($actor->hasRole('superadmin')) { return; }
  if (TitleProgress::isFinal($current)) {
      throw new AuthorizationException('Bab sudah final dan terkunci.');
  }
  if ($actor->hasRole('manager')) { return; }
  if ($actor->hasRole('production') && TitleProgress::getHandlerForStatus($current) === 'production') { return; }
  throw new AuthorizationException('Anda tidak berhak memindahkan bab pada tahap ini.');
  ```

> Kunci berlaku pada mutasi status (maju/mundur/lompat). Karena `cetak`/`terbit` (buku) & `loa`/`publish` (artikel) handler-nya superadmin, praktis production sudah tak bisa di tahap itu; aturan ini menutup celah manager pada tahap final + menegaskan kunci.

## 3. (C) Kunci Final — UI

Di `resources/views/manuscript/partials/card.blade.php`:
- **Kartu (artikel & buku):** hitung `$finalLocked = \App\Models\TitleProgress::isFinal($p->status) && ! auth()->user()->hasRole('superadmin')`. Bila `$finalLocked`, tambahkan atribut `data-no-drag` pada `.mt-card` (artikel jadi tak bisa di-drag; buku memang sudah `data-no-drag`). Papan (`board.blade.php`) sudah pakai SortableJS `filter: '[data-no-drag]'`.
- **Aksi status:** sembunyikan dropdown "Majukan" (artikel) & badge lock. Untuk bab (buku), pada bab yang `\App\Models\TitleProgress::isFinal($cstatus) && ! superadmin`, sembunyikan tombol **"Maju → next"** dan **"Ubah…"** (biarkan hanya tampilan status). Tambah badge kecil "🔒 Final" agar jelas.
- superadmin tetap melihat kontrol (bisa koreksi).

## 4. (D) Retensi 30 Hari — Query Papan

- Tambah konstanta `TitleProgress::BOARD_RETENTION_DAYS = 30`.
- Di `app/Http/Controllers/Pages/ManuscriptTrackerController.php` — query utama `$details` (baris ~61) + query list/log/review yang memuat kartu: tambahkan kondisi **kecualikan final-lama**:
  ```php
  ->whereHas('titleProgress', function ($t) {
      $t->where(function ($q) {
          $q->whereNotIn('status', TitleProgress::FINAL_STAGES)
            ->orWhere('started_at', '>=', now()->subDays(TitleProgress::BOARD_RETENTION_DAYS));
      });
  })
  ```
  Artinya: pertahankan naskah non-final, ATAU final yang mencapai final **dalam 30 hari terakhir**. Naskah final >30 hari (via `started_at` saat mencapai final) tersaring keluar. `started_at` di-set `now()` tiap ganti status (`applyStatus`), jadi = waktu mencapai tahap final. `started_at` null → tak tersaring (aman).
- Dihitung saat query; **tanpa cron**. **Arsip Judul & Direktori Judul tak diubah** (retensi hanya papan).

## 5. Rencana Test

- **Feature `ManuscriptFinalizeTest`** (`GoogleDriveService` di-mock):
  - **(C) artikel**: manager `POST manuscript.move` pada grup berstatus `publish` → 403; superadmin → sukses. (fixture: artikel + TitleProgress `publish`.)
  - **(C) buku/bab**: production & manager `POST chapter.advance` pada bab `terbit` → 403; superadmin → sukses. (fixture: buku + bab + ChapterProgress `terbit`.)
  - **(C) UI**: `GET manuscript.board` (artikel, manager) dengan kartu `publish` → render `data-no-drag` pada kartu itu; superadmin → tidak `data-no-drag` untuk kartu tsb. (assert `data-no-drag`, escape=false.)
  - **(D)**: buat grup artikel final dgn `started_at = now()->subDays(31)` → tak muncul di papan (`assertDontSee` judul); `subDays(29)` → muncul; naskah non-final `started_at` lama → tetap muncul.
  - **(D) arsip**: naskah final-lama tetap tampil di Arsip Judul (bila ada test/route arsip yang relevan; jika tidak, cukup assert query papan saja).
- **Regresi**: `ChapterProgressControllerTest`, `ChapterStageJumpTest`, `ManuscriptTrackerTest`, `TitleProgressTest` tetap hijau; `php artisan view:cache` bersih.

Suite via `.env.testing`. **Tanpa migrasi.**

## 6. Komponen

- **Baru:** test `tests/Feature/ManuscriptFinalizeTest.php`; konstanta `BOARD_RETENTION_DAYS` di `TitleProgress`.
- **Diubah:** `app/Services/TitleProgressService.php` (`authorizeChange` +final lock); `app/Services/ChapterManuscriptService.php` (`authorize` restruktur +final lock); `app/Http/Controllers/Pages/ManuscriptTrackerController.php` (filter retensi di query papan); `resources/views/manuscript/partials/card.blade.php` (data-no-drag + sembunyi aksi + badge Final).
- **Tak diubah:** skema DB; Arsip/Direktori; alur `needs_review` (B).

## 7. Asumsi & Risiko

- (B) tak diubah — mundur oleh non-superadmin tetap boleh + `needs_review`. Kunci final (C) berlapis di atasnya (final tak bisa diubah non-superadmin).
- Retensi berbasis `started_at` tahap final (di-set saat mencapai final). Bila status final diubah superadmin lalu dikembalikan ke final, jam retensi ter-reset (wajar).
- Filter retensi diterapkan di semua query yang membangun kartu papan (utama + review/list/log) agar konsisten.
- Superadmin escape hatch: satu-satunya jalan koreksi naskah final dari papan.
- UI lock hanya cerminan; server tetap penjaga akhir (test menembak endpoint langsung).
