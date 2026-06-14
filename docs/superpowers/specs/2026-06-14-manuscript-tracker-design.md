# Spec: Manuscript Tracker (Papan Kerja Production)

**Tanggal:** 2026-06-14
**Status:** Disetujui — siap masuk rencana implementasi
**Area:** Title Progress, Production Workflow, Role `production`, UI Kanban

---

## Ringkasan

Menambahkan menu **Manuscript Tracker** — papan Kanban interaktif sebagai ruang kerja
tim `production` untuk memajukan progres naskah. Fitur ini **bukan sistem baru**: ia
adalah view baru di atas data [`TitleProgress`](../../../app/Models/TitleProgress.php)
yang sudah ada, sekaligus mengaktifkan role `production` yang sudah di-seed tapi belum
di-wire ke alur kerja mana pun.

Referensi visual: file artifact `SiMAPA v2 - Superadmin Screens (standalone).html`
(layar "Manuscript Tracker" — papan Kanban). Karena project memakai **Blade +
Bootstrap 5 + Alpine.js + SortableJS** (bukan React), yang diadopsi adalah konsep,
layout, dan UX-nya — bukan kodenya.

### Keputusan desain yang sudah dikunci

1. **Ruang kerja interaktif** — production memajukan stage langsung dari papan (drag/klik).
2. **Kolom = stage asli**, dengan toggle **Buku / Artikel** (tiap tipe punya pipeline berbeda).
3. **Domain production = semua stage editorial** (yang sekarang dipegang `manager`).
4. **Tambah penugasan editor per-orang + prioritas** (kolom baru di `tb_title_progress`).

---

## 1. Arsitektur & Komponen

| Aksi | File |
|------|------|
| Create | `app/Http/Controllers/Pages/ManuscriptTrackerController.php` |
| Create | `app/Services/TitleProgressService.php` |
| Create | `database/migrations/2026_06_14_000001_add_assignment_to_tb_title_progress.php` |
| Create | `resources/views/manuscript/board.blade.php` |
| Create | `resources/views/manuscript/list.blade.php` |
| Create | `resources/views/manuscript/partials/card.blade.php` |
| Create | `tests/Feature/ManuscriptTrackerTest.php` |
| Create | `tests/Unit/TitleProgressServiceTest.php` |
| Modify | `app/Models/TitleProgress.php` |
| Modify | `app/Http/Controllers/Pages/TitleProgressController.php` |
| Modify | `resources/views/orders/detail-title.blade.php` |
| Modify | `resources/views/layouts/sidebar.blade.php` |
| Modify | `routes/web.php` |

### Kenapa `TitleProgressService`

Saat ini logika pindah-stage (validasi role, hitung koreksi, tulis log) hidup di dalam
`TitleProgressController@update`. Begitu papan butuh logika identik lewat AJAX/drag,
menyalinnya berarti dua sumber kebenaran yang bisa menyimpang. Maka logika dipindah ke
satu service:

```
TitleProgressService::changeStatus(TitleProgress $p, string $target, User $actor, ?string $note): Result
TitleProgressService::assignEditor(TitleProgress $p, ?int $userId, User $actor): void
TitleProgressService::setPriority(TitleProgress $p, string $priority, User $actor): void
```

`changeStatus` melempar `AuthorizationException` (→ 403) atau `ValidationException`
(→ pesan) sesuai aturan di Bagian 3. Dipakai oleh form detail **dan** endpoint papan.

---

## 2. Perubahan Data

### Migration: `add_assignment_to_tb_title_progress`

Tambah ke `tb_title_progress`:

| Kolom | Tipe | Keterangan |
|-------|------|-----------|
| `assigned_user_id` | `unsignedBigInteger nullable` | FK → `users.id`, `nullOnDelete` |
| `priority` | `varchar(10)` default `'normal'` | `low` \| `normal` \| `high` |

Index tambahan untuk query papan: index pada `status`, dan pada `assigned_user_id`.

> Catatan kompatibilitas: data lama tidak punya kolom ini → default `priority='normal'`,
> `assigned_user_id=null`. Tidak ada backfill yang dibutuhkan.

### Model `TitleProgress`

- `$fillable` += `assigned_user_id`, `priority`.
- Relasi `assignedUser(): belongsTo(User::class, 'assigned_user_id')`.
- Konstanta `const PRIORITIES = ['low', 'normal', 'high'];`
- Konstanta `const PRODUCTION_STAGES` (diturunkan dari `STAGE_HANDLER` yang handler-nya `production`) untuk dipakai validasi & query.

---

## 3. Kepemilikan Stage & Aturan Akses

### `STAGE_HANDLER` (diperbarui)

| Stage | Handler |
|-------|---------|
| `menunggu_proses` | `marketing` *(gerbang verifikasi — tetap)* |
| `templating` `editing` `revisi` `submit` *(artikel)* | **`production`** |
| `layout` `proofreading` `isbn` *(buku, + `editing`)* | **`production`** |
| `loa` `publish` *(artikel)* | `superadmin` *(finalisasi — tetap)* |
| `cetak` `terbit` *(buku)* | `superadmin` *(finalisasi — tetap)* |

Pipeline (tak berubah):
- **Artikel:** `menunggu_proses → templating → editing → revisi → submit → loa → publish`
- **Buku:** `menunggu_proses → editing → layout → proofreading → isbn → cetak → terbit`

### Matriks aturan pindah stage

Berlaku seragam di papan (drag/klik) maupun form halaman detail.

| Role | Maju 1 langkah | Koreksi (mundur/lompat) | Catatan |
|------|:---:|:---:|---|
| `production` | ✓ — hanya jika **stage saat ini** milik production | ✗ | Tak bisa menggerakkan kartu di `menunggu_proses`. Boleh dorong stage produksi terakhir → finalisasi (serah-terima ke superadmin). |
| `manager` | ✓ — stage apa pun (oversight) | ✗ | — |
| `superadmin` | ✓ — stage apa pun | ✓ | Koreksi **wajib** `note`; log `is_correction=true`. |
| `marketing` | ✗ | ✗ | Hanya membuat order. |

Aturan formal `changeStatus`:

```
1. target harus stage valid untuk tipe naskah          → else ValidationException
2. next = getNextStatus(); jika status sudah final      → ValidationException ("tahap akhir")
3. isCorrection = (target !== next)
4. izin:
   - superadmin: selalu boleh
   - manager:    boleh jika !isCorrection
   - production: boleh jika !isCorrection DAN getHandlerForStatus(status_sekarang) === 'production'
   - lainnya:    AuthorizationException (403)
5. isCorrection && note kosong                          → ValidationException ("catatan wajib")
6. update status, assigned_role = handler(target), updated_by, started_at = now()
7. tulis TitleProgressLog (from, to, changed_by, note, is_correction)
   — langkah 6–7 dalam DB::transaction
```

### Aturan assign & prioritas

- **Assign editor:** `assigned_user_id` harus user dengan role `production` **atau**
  `manager` (validasi), atau `null` (lepas tugas). Superadmin tidak masuk daftar assignee
  (perannya finalisasi/oversight). Yang boleh meng-assign: `production` (termasuk
  self-assign), `manager`, `superadmin`.
- **Prioritas:** nilai ∈ `PRIORITIES`. Yang boleh: `production`, `manager`, `superadmin`.

---

## 4. Routes

Di grup `management` (sudah ada middleware `auth`, `verified`):

```php
GET  management/manuscript               → ManuscriptTrackerController@index    name: manuscript.board
       (?view=board|list, default board; ?tipe=buku|artikel; ?editor=; ?priority=)
POST management/manuscript/{id}/move     → @move      name: manuscript.move      middleware role:production|manager|superadmin
POST management/manuscript/{id}/assign   → @assign    name: manuscript.assign    middleware role:production|manager|superadmin
POST management/manuscript/{id}/priority → @priority  name: manuscript.priority  middleware role:production|manager|superadmin
```

Perubahan route existing:
- `title.progress.update` middleware diperluas: `role:manager|superadmin` → `role:production|manager|superadmin` (otorisasi sebenarnya tetap dijaga di service per Bagian 3).

Endpoint `move`/`assign`/`priority` mengembalikan **JSON**:

```json
{ "ok": true,  "id": 12, "status": "layout", "assigned_role": "production",
  "priority": "high", "assigned_user": {"id": 3, "name": "Pradipta"},
  "message": "Naskah maju ke Layout." }
{ "ok": false, "code": 403, "message": "Anda tidak berhak memindahkan naskah pada tahap ini." }
```

Setiap controller method **memvalidasi ulang** lewat service (tidak bergantung pada
middleware route saja).

---

## 5. UI — Papan Kanban (`manuscript/board.blade.php`)

Mengikuti layout mockup, diterjemahkan ke Bootstrap 5 + Alpine + SortableJS.

### Header
- Judul **"Manuscript Tracker"**, subjudul: `"{n} naskah aktif · geser kartu untuk memajukan tahap"`.
- Aksi: **toggle Buku / Artikel** (mengubah query `?tipe=buku|artikel`, default `buku`),
  filter **Editor** (dropdown user production/manager + "Semua" + "Tugas saya"),
  filter **Prioritas** (Semua/High/Normal/Low),
  toggle **Papan / Daftar** (mengubah `?view=board|list` — view daftar dirender di dalam
  tracker ini, berbagi controller/dataset/filter yang sama; **bukan** link keluar ke
  "Arsip Judul").

### Kolom
- Kolom = stage pipeline tipe terpilih (`BOOK_STAGES` / `ARTICLE_STAGES`).
- Header kolom: titik warna stage + nama stage (Title Case) + badge jumlah.
- Warna stage mengikuti peta badge yang sudah ada di spec title-tracking
  (abu → kuning → oranye → biru → hijau).
- Kolom kosong menampilkan placeholder ("Tidak ada naskah").

### Kartu (`partials/card.blade.php`)
Konten (sesuai mockup):
- Kode order (mono, warna primer) + badge **prioritas** (High = merah) bila ada.
- Judul naskah (clamp 2 baris).
- Avatar inisial + nama penulis utama + afiliasi.
- Badge layanan (indexation / scope) + umur di stage (`started_at` → diffForHumans, ikon jam).
- Baris editor: "Editor: {nama}" atau "Belum ditugaskan".
- `data-id`, `data-status`, `data-handler` untuk SortableJS.

### Interaksi
- **Drag** antar kolom via SortableJS → AJAX `POST manuscript.move`.
  - **Optimistic UI**: kartu pindah dulu; jika server tolak → kartu **dikembalikan** ke
    kolom asal + toast error (gunakan callback `onEnd` + revert).
  - Sukses → toast sukses, badge jumlah kolom diperbarui, umur kartu reset.
- **Fallback tanpa JS / aksesibilitas**: tiap kartu punya tombol **"Majukan ›"**
  (form POST biasa) sehingga papan tetap fungsional tanpa drag.
- **Quick action** (dropdown kecil di kartu): **Assign editor**, **Set prioritas**
  (AJAX `assign`/`priority`, perbarui kartu in-place).
- **Detail penuh** (timeline lengkap, riwayat log, koreksi superadmin) tetap di halaman
  `detail-title` yang sudah ada — klik judul kartu nge-link ke sana.

### View Daftar (`manuscript/list.blade.php`)

Toggle "Daftar" merender tabel **di dalam** tracker, memakai controller, dataset, dan
filter yang **sama** dengan papan (`?view=list`). Kolom: judul, tipe, stage (badge),
**editor**, **prioritas**, umur di stage, aksi (Detail / Majukan). Ini melengkapi papan —
bukan menggantikan "Arsip Judul" lama (yang tetap utuh untuk marketing/arsip umum).
Pada layar mobile, `index` default ke `view=list` (papan butuh scroll horizontal).

### Query (hindari N+1) — dipakai papan & daftar

`OrderDetail` di-eager-load: `order`, `authors`, `scopes`, `titleProgress.assignedUser`,
difilter `type` (set buku/artikel), opsional `assigned_user_id` & `priority`. Untuk papan
dikelompokkan per `status`; untuk daftar ditampilkan datar. Scope role:
production/manager/superadmin melihat seluruh pipeline tipe terpilih.

---

## 6. Halaman Detail (`detail-title.blade.php` — modifikasi)

Tambahan minimal pada halaman yang sudah ada:
- Kontrol **Assign editor** (dropdown user production/manager) → `manuscript.assign`.
- Kontrol **Prioritas** (low/normal/high) → `manuscript.priority`.
- Form update status existing tetap, kini juga berlaku untuk `production` (lewat service).

Timeline, daftar penulis, dan riwayat log yang sudah ada tidak diubah strukturnya.

---

## 7. Menu & Akses (`sidebar.blade.php`)

Tambah kategori **"Produksi"** dengan item **"Manuscript Tracker"**
(`route('manuscript.board')`, ikon `trello`/`columns`), tampil untuk
`@role(['superadmin','manager','production'])`. Ini menu operasional pertama untuk role
production. "Arsip Judul" yang ada tetap untuk superadmin/manager/marketing.

---

## 8. Error Handling

| Kondisi | Penanganan |
|---------|-----------|
| Production gerakkan kartu di luar domain / `menunggu_proses` | 403 JSON, kartu di-revert, toast |
| Manager/production koreksi (mundur/lompat) | 403 JSON |
| Superadmin koreksi tanpa note | 422 JSON, toast "catatan wajib" |
| Target stage tak valid untuk tipe | 422 JSON |
| Naskah sudah di stage akhir | 422 JSON ("tahap akhir") |
| Assign user bukan role production/manager | 422 JSON |
| Naskah belum punya `TitleProgress` (data lama) | Auto-create `menunggu_proses` saat diakses (fallback yang sudah ada di `detailJudul`) |
| Request AJAX gagal / jaringan | Kartu di-revert, toast "gagal, coba lagi" |
| CSRF / sesi habis | 419 → toast minta refresh |

---

## 9. Kualitas (QA / QC)

### 9.1 Test otomatis

**Unit — `TitleProgressServiceTest`:**
- production maju 1 langkah pada stage produksi → status berubah + log `is_correction=false`.
- production dorong stage produksi terakhir → finalisasi (handoff) sukses.
- production coba gerakkan kartu setelah handoff (stage milik superadmin) → `AuthorizationException`.
- production koreksi mundur → `AuthorizationException`.
- production gerakkan `menunggu_proses` → `AuthorizationException`.
- manager maju stage apa pun → sukses; manager koreksi → ditolak.
- superadmin koreksi tanpa note → `ValidationException`; dengan note → log `is_correction=true`.
- assignEditor dengan user di luar role production/manager → `ValidationException`; dengan production/manager → tersimpan; `null` → lepas tugas.
- setPriority nilai invalid → ditolak; valid → tersimpan.

**Feature — `ManuscriptTrackerTest`:**
- Papan render kolom sesuai `?tipe=buku` vs `?tipe=artikel`.
- `marketing` & guest akses route papan/move → 403/redirect.
- Endpoint `move` happy-path mengembalikan JSON `ok:true` + DB ter-update.
- Endpoint `move` ditolak mengembalikan JSON `ok:false` + DB **tidak** berubah.
- `assign` & `priority` memperbarui kolom & balikan JSON benar.
- Filter editor & prioritas mempersempit hasil.
- Tidak ada query N+1 pada papan (assert jumlah query wajar / `withoutN+1`).

Target: seluruh suite tetap **hijau** (sekarang 41 passed) — lihat memory testing-setup;
jalankan terhadap `avidpedi_simapa_test` via `.env.testing`, **bukan** DB asli.

### 9.2 Checklist QA manual (browser, per role)

- [ ] **production**: login → menu "Manuscript Tracker" muncul; buka papan; drag kartu
  editorial → pindah & persist setelah refresh; drag ke kolom terlarang → kartu balik +
  toast; assign diri sendiri; set prioritas High → badge muncul.
- [ ] **manager**: bisa majukan stage apa pun; tidak bisa koreksi mundur.
- [ ] **superadmin**: koreksi mundur dengan catatan → log "Koreksi" merah di detail.
- [ ] **marketing**: tidak melihat menu; akses URL papan langsung → ditolak.
- [ ] Toggle Buku/Artikel mengubah kolom dengan benar.
- [ ] Toggle Papan/Daftar bolak-balik tanpa kehilangan filter tipe.
- [ ] Fallback: matikan JS → tombol "Majukan ›" tetap memindahkan stage.

### 9.3 Kriteria UX

- Drag halus, kursor `grab`/`grabbing`; kolom target ter-highlight saat hover.
- Setiap aksi memberi umpan balik < 1 dtk (optimistic + toast).
- **Responsif**: papan `overflow-x:auto` (scroll horizontal) di layar sempit; `index`
  default ke `?view=list` pada mobile.
- **Aksesibilitas**: tombol "Majukan", dropdown quick-action, dan link kartu dapat
  diakses keyboard; kartu punya `aria-label`; warna stage tidak jadi satu-satunya penanda
  (selalu ada teks).
- Empty state tiap kolom jelas; angka jumlah selalu sinkron dengan isi.

### 9.4 Keamanan / Integritas

- Semua endpoint cek role (middleware) **dan** otorisasi service.
- AJAX menyertakan CSRF token (`meta csrf-token`).
- Mass-assignment dibatasi `$fillable`; `move`/`assign`/`priority` hanya menerima field
  yang relevan (status, user_id, priority) — divalidasi.
- Perubahan status & assign selalu tercatat di log (jejak audit).

### 9.5 Definition of Done

1. Semua test unit + feature baru hijau; suite lama tetap hijau.
2. Checklist QA manual (9.2) lolos untuk 4 role.
3. Kriteria UX (9.3) & keamanan (9.4) terpenuhi.
4. Tidak ada regresi pada halaman "Arsip Judul" & "Detail Title" yang sudah ada.
5. Tidak ada error di `php artisan` log saat alur dijalankan.

---

## 10. Di Luar Cakupan (YAGNI)

- Layar **Dashboard / Orders / Order-Detail** dari mockup (fitur terpisah).
- Notifikasi email/in-app saat pindah stage (sudah ada spec notifikasi tersendiri).
- Sinkronisasi status jurnal eksternal ("Under Review", Scopus/Sinta) — itu fiksi mockup,
  tidak ada di data kita.
- Realtime multi-user (websocket) — papan refresh per-load.
- Urutan/reorder kartu dalam satu kolom (prioritas dipakai sebagai ganti posisi).
- Aksi massal (bulk move) — bisa jadi iterasi lanjutan.

---

## Dependensi

- **SortableJS** sudah ter-vendor: `public/assets/plugins/sortablejs/Sortable.min.js`.
- **Alpine.js 2.8.2** sudah dimuat di `layouts/master.blade.php`.
- **Spatie Permission** sudah terpasang (role `production` sudah di-seed).
- Tidak ada package baru.
</content>
</invoke>
