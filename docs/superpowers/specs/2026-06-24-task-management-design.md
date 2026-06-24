# Spec — Pengelolaan Aktivitas Karyawan (Task Management) — Phase 1

- **Tanggal:** 2026-06-24
- **Branch:** `task-management`
- **Scope (Phase 1):** Sistem tugas/todolist generik per karyawan dengan tiga tampilan — **Board (kanban, drag-and-drop)**, **Kalender (event)**, **Todo (list)** — penugasan dua arah (mandiri + oleh atasan), notifikasi saat di-assign, dan halaman **Pemantauan Tugas** untuk manager/superadmin.
- **Di luar scope (Phase 2 / sengaja):** **Report harian & bulanan** (spec terpisah berikutnya), subtask/checklist, recurring tasks, komentar/lampiran, drag-resize durasi di kalender, integrasi ke pipeline naskah.

> **Syarat utama: UI/UX profesional** mengikuti komponen & gaya `template-web` (NobleUI BS5). Lihat [[ui-conventions]] — list/tabel pakai DataTables; jangan commit/push folder `template-web`.

---

## 1. Latar Belakang

SiMAPA sudah punya **pipeline khusus produksi** (manuscript tracker: `TitleProgress` tahap templating→…→terbit/publish, `assigned_user_id`, prioritas, target_date, performa per-editor). Itu spesifik naskah. Fitur ini menambah **lapisan task management generik untuk SEMUA karyawan** (todo bebas, tak terkait naskah) agar aktivitas tiap karyawan terkelola & terpantau. Plugin yang dibutuhkan sudah ter-bundle di `public/assets/plugins/`: **fullcalendar** (`index.global.min.js`, v6 global), **sortablejs** (`Sortable.min.js`), **flatpickr**, **select2**, **sweetalert2**, **datatables-net-bs5** — tanpa dependency baru.

## 2. Tujuan & Kriteria Sukses

1. Tiap karyawan punya tugas pribadi (tidak tercampur) dengan tiga tampilan: Board, Kalender, Todo.
2. Karyawan membuat todo sendiri; manager/superadmin bisa menugaskan tugas ke karyawan tertentu.
3. Drag-and-drop: pindah kartu antar kolom board mengubah status; drag event kalender mengubah tenggat — tersimpan via AJAX.
4. Saat manager menugaskan ke orang lain, karyawan tujuan dapat notifikasi (lonceng yang sudah ada).
5. Manager/superadmin melihat semua tugas seluruh karyawan dalam satu dashboard **Pemantauan Tugas** (KPI + tabel + filter).
6. Karyawan tidak bisa mengubah tugas milik orang lain (403). Suite tetap hijau; perilaku tertutup test.

---

## 3. Desain

### 3.1 Data model — `tb_tasks`

| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK users, cascadeOnDelete | pemilik/penerima tugas (tugas bersifat pribadi) |
| `title` | string | judul tugas |
| `description` | text, nullable | detail |
| `status` | string(16), default `todo` | `todo` / `in_progress` / `done` |
| `priority` | string(16), default `normal` | `low` / `normal` / `high` |
| `due_date` | date, nullable | dasar tampilan kalender; overdue = `< hari ini` & belum `done` |
| `position` | unsignedInteger, default 0 | urutan kartu dalam satu kolom board |
| `completed_at` | timestamp, nullable | di-set saat status → `done`; di-null-kan bila keluar dari `done` |
| `created_by` | FK users, nullable nullOnDelete | yang membuat/menugaskan (diri sendiri atau atasan) |
| timestamps | | |

Index: `(user_id, status)`. Model `App\Models\Task` (`$table='tb_tasks'`): fillable semua kolom di atas; casts `due_date`→date, `completed_at`→datetime, `position`→integer; const `STATUSES = ['todo','in_progress','done']`, `PRIORITIES = ['low','normal','high']`; relasi `user()` belongsTo, `creator()` belongsTo(User,'created_by'); scope `forUser($q,$id)` = `where('user_id',$id)`.

### 3.2 `TaskService` (`app/Services/TaskService.php`)

- **`board(User $user): array`** → tugas user dikelompokkan per status (`todo`/`in_progress`/`done`), tiap kolom diurut `position` asc lalu `id`. Bentuk: `['todo'=>Collection, 'in_progress'=>..., 'done'=>...]`.
- **`calendarEvents(User $user, ?string $start, ?string $end): array`** → tugas user yang punya `due_date` dalam rentang (bila diberikan), dipetakan ke format FullCalendar: `['id','title','start'=>due_date,'color'=>warna prioritas/status,'allDay'=>true]`.
- **`move(Task $task, string $status, int $position): void`** → set status + position; saat `done` set `completed_at = now()`, saat keluar dari `done` set `completed_at = null`. Validasi status ∈ STATUSES.
- **`reorder(User $user, string $status, array $orderedIds): void`** → set `position` 0..n sesuai urutan id (hanya tugas milik user & status itu).
- **`monitor(?int $userId, ?string $status): array`** → untuk Pemantauan: KPI global (`total`, per status, `overdue`) + Collection baris tugas seluruh user (join nama, filter opsional per `user_id`/`status`), diurut prioritas lalu due_date. Realisasi efisien (eager-load `user`), hindari N+1.

### 3.3 Tampilan & route

Semua di grup `auth`. Kepemilikan ditegakkan di controller (lihat §3.6).

| Route | Nama | Aksi |
|---|---|---|
| `GET /tasks` | `task.index` | Todo (list, DataTables) — tugas saya |
| `GET /tasks/board` | `task.board` | Board kanban (SortableJS) — tugas saya |
| `GET /tasks/calendar` | `task.calendar` | Kalender (FullCalendar) — tugas saya |
| `GET /tasks/events` | `task.events` | JSON feed FullCalendar (param `start`,`end`) — tugas saya |
| `POST /tasks` | `task.store` | buat tugas |
| `PUT /tasks/{id}` | `task.update` | edit (title/desc/priority/due_date/status/assignee) |
| `DELETE /tasks/{id}` | `task.destroy` | hapus |
| `PATCH /tasks/{id}/status` | `task.status` | drag board: `{status, position}` (JSON, set completed_at) |
| `PATCH /tasks/{id}/schedule` | `task.schedule` | drag kalender: `{due_date}` (JSON) |
| `POST /tasks/reorder` | `task.reorder` | urutan kolom: `{status, ids:[...]}` (JSON) |
| `GET /tasks/monitor` | `task.monitor` | Pemantauan (manager/superadmin) — semua tugas |

Controller `App\Http\Controllers\Pages\TaskController`.

Catatan ordering route: definisikan rute statis (`tasks/board`, `tasks/calendar`, `tasks/events`, `tasks/reorder`, `tasks/monitor`) **sebelum** rute ber-`{id}`, agar tidak ter-shadow.

### 3.4 Board (Kanban) — `resources/views/tasks/board.blade.php`

- 3 kolom: **Menunggu** (`todo`) · **Dikerjakan** (`in_progress`) · **Selesai** (`done`), tiap kolom punya hitungan + tombol **+ Tambah**.
- Kartu: judul, badge prioritas (Tinggi merah / Normal abu / Rendah biru), tenggat (badge merah bila overdue), menu kecil (Edit / Hapus). Pada Pemantauan kartu juga menampilkan nama pemilik.
- **SortableJS** `group: 'tasks'` di tiap `.task-column .cards`. `onEnd`: kirim `PATCH task.status` `{status: kolom tujuan, position: index baru}`; bila pindah dalam kolom yang sama kirim `POST task.reorder` `{status, ids}`. CSRF via header `X-CSRF-TOKEN`.
- Modal buat/edit (Bootstrap modal): judul, deskripsi, prioritas, tenggat (**flatpickr**), dan untuk manager/superadmin field **Tugaskan ke** (**select2** daftar karyawan). Submit AJAX → re-render kolom (atau reload).
- Hapus → konfirmasi **sweetalert2** → `DELETE`.

### 3.5 Kalender — `resources/views/tasks/calendar.blade.php`

- **FullCalendar v6** (`FullCalendar.Calendar`), `initialView:'dayGridMonth'`, locale id, `events: route('task.events')`.
- Warna event per prioritas (atau status `done` → abu). Klik tanggal kosong → modal buat (tenggat terisi). Klik event → modal edit. **`editable:true`** + `eventDrop` → `PATCH task.schedule` `{due_date: event.startStr}`.

### 3.6 Todo (list) — `resources/views/tasks/index.blade.php`

- DataTables-bs5: **Judul · Status (badge) · Prioritas · Tenggat · Aksi** (Edit, Hapus, dan tombol/centang **Tandai selesai** → `PATCH task.status` done). Filter status via tombol/segmented (Semua/Menunggu/Dikerjakan/Selesai). Pakai `@foreach` + `language.emptyTable` (pola anti-error DataTables kosong).

### 3.7 Penugasan + notifikasi

- `store`/`update`: untuk **manager/superadmin** field `assignee` (user_id) valid; role lain → `assignee` dipaksa = `Auth::id()`. `created_by` = `Auth::id()`.
- Saat tugas dibuat/di-assign ke **user lain** (assignee ≠ pembuat), panggil `Notifier::taskAssigned(Task $task, User $actor)` — method baru di `app/Services/Notifier.php` yang reuse helper privat `send/toOwner/rp` (kategori `task`), isi "Tugas baru dari {actor}: {judul}" + link ke `task.board`. Null-safe (mengikuti pola `targetAssigned`).

### 3.8 Pemantauan Tugas — `resources/views/tasks/monitor.blade.php` (manager/superadmin)

- **KPI cards**: Total tugas · Menunggu · Dikerjakan · Selesai · Lewat tenggat.
- **Tabel (DataTables)**: Karyawan · Judul · Status · Prioritas · Tenggat · Lewat tempo. Filter: pilih karyawan (select2) & status (segmented) → reload `task.monitor?user_id=&status=`.
- Tombol "Lihat board" per karyawan → `task.board?user_id=` (manager boleh melihat board orang lain — read; assign/ubah lewat aksi normal).
- Gated `role:manager|superadmin`.

### 3.9 Hak akses (ringkas)

- `task.*` (index/board/calendar/events/store/update/destroy/status/schedule/reorder): **semua role terautentikasi**, tetapi:
  - Non-manager hanya boleh **melihat & mengubah tugas miliknya** (`user_id = Auth::id()`); mengakses tugas milik orang lain → **403** (cek kepemilikan di controller; manager/superadmin dilewati).
  - Non-manager yang membuat tugas: `assignee` dipaksa diri sendiri (tak bisa assign ke orang lain).
  - `?user_id=` (lihat board/list orang lain) hanya untuk manager/superadmin.
- `task.monitor`: **manager/superadmin** saja.

Helper privat `authorizeTask(Task $t)` di controller: lolos bila `Auth::user()->hasAnyRole(['manager','superadmin'])` atau `$t->user_id === Auth::id()`; selain itu `abort(403)`.

### 3.10 Sidebar

Grup baru **"Tugas"** (ikon `check-square`): item **Board**, **Kalender**, **Todo** (semua role). Item **Pemantauan Tugas** (`@role(['manager','superadmin'])`).

---

## 4. Komponen yang Dibuat / Disentuh

- **Baru:** migrasi `tb_tasks`; `app/Models/Task.php`; `app/Services/TaskService.php`; `app/Http/Controllers/Pages/TaskController.php`; `resources/views/tasks/{index,board,calendar,monitor}.blade.php` + partial modal form `resources/views/tasks/partials/form-modal.blade.php`.
- **Diubah:** `routes/web.php` (grup route tasks), `resources/views/layouts/sidebar.blade.php` (grup Tugas), `app/Services/Notifier.php` (method `taskAssigned`).

## 5. Rencana Test

- **Unit `TaskServiceTest`**: `board` mengelompokkan & mengurut per status/position; `move` ke `done` set `completed_at`, keluar dari `done` me-null-kan; `reorder` menulis position sesuai urutan; `calendarEvents` hanya tugas ber-due_date dalam rentang & ter-scope user; `monitor` KPI benar (total/per-status/overdue) + filter user/status.
- **Feature `TaskControllerTest`**: karyawan buat→muncul di board-nya; tak bisa GET/PATCH tugas milik orang lain (**403**); manager assign ke karyawan → tersimpan `user_id` target + **notifikasi** dibuat untuk target; `PATCH status` (drag) mengubah status + completed_at; `task.events` JSON ter-scope user; non-manager → **403** di `task.monitor`; manager → 200 & melihat tugas semua orang.

Suite pakai DB test via `.env.testing` (`RefreshDatabase`). **Dev/prod: jalankan `php artisan migrate`** untuk `tb_tasks` (lihat [[migrate-dev-db-after-new-migration]]).

## 6. Asumsi & Risiko

- Drag-and-drop & kalender memakai vanilla JS dari plugin ter-bundle (FullCalendar v6 & SortableJS) — tanpa jQuery khusus; tetap konsisten dengan stack template.
- Kepemilikan ditegakkan di controller (bukan Policy terpisah) agar ringkas; manager/superadmin bypass. Bila kelak butuh granular, bisa diangkat ke Policy.
- Notifikasi assign reuse sistem notifikasi yang ada (kategori `task`) — tidak membuat kanal baru.
- `position` cukup integer per kolom; reorder menulis ulang 0..n untuk kolom terdampak (jumlah tugas per orang kecil — murah).
- Tugas generik ini **terpisah** dari `TitleProgress` (pipeline naskah) — sengaja, agar tidak mencampur dua domain.
- UI mengikuti `template-web` (gitignored) — styling direplikasi via kelas BS5/komponen yang sudah dipakai halaman lain; folder template tidak di-commit.
