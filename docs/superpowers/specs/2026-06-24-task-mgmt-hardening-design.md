# Spec — Pengetatan Task Management (Lock + Deadline Alert + Bukti Wajib + SweetAlert2)

- **Tanggal:** 2026-06-24
- **Branch:** `task-reports` (lanjutan Phase 2; belum di-merge)
- **Scope:** 5 revisi yang saling terkait: (1) kunci tugas selesai yang sudah dilaporkan di board; (2) alert deadline H‑7 (notifikasi + dashboard + SweetAlert); (3) semua alert/confirm pakai SweetAlert2; (4) report wajib bukti sebelum submit + keterangan upload; (5) keterangan saat lampiran kosong + daftar kerjaan+bukti di view pengawas.
- **Di luar scope (sengaja):** cron terjadwal, reminder email, snooze alert, mengunci task non-`done`, lampiran pada task (lampiran tetap di report), notifikasi deadline harian (kita pakai sekali-per-window).

> **UI/UX profesional** mengikuti `template-web`. SweetAlert2, Dropzone, DataTables, SortableJS, FullCalendar semua sudah ter-bundle. Lihat [[ui-conventions]].

---

## 1. Latar Belakang

Phase 1 (Task Management: board/kalender/todo, `tb_tasks`) dan Phase 2 (Report harian/bulanan + lampiran Drive, `tb_daily_reports`/`tb_daily_report_files`) sudah ada di branch ini. Revisi ini mempererat keduanya: report yang **submitted** mengunci tugas selesai terkait di board; deadline tugas memicu peringatan; bukti jadi wajib; dan dialog dibuat konsisten dengan SweetAlert2 (`assets/plugins/sweetalert2/sweetalert2.min.{js,css}` — sudah ada).

## 2. Tujuan & Kriteria Sukses

1. Tugas `done` yang `completed_at`-nya pada tanggal report **submitted** menjadi **terkunci** di board: tak bisa drag, edit, atau hapus; kolom Selesai urut `completed_at` desc.
2. Tugas belum selesai yang `due_date`-nya ≤7 hari memicu: notifikasi (sekali), kartu dashboard persisten, dan popup SweetAlert — ke **pemilik + manager/superadmin/admin**.
3. Semua konfirmasi/peringatan memakai SweetAlert2.
4. Report tidak bisa di-submit tanpa minimal 1 bukti (lampiran); ada keterangan cara upload + daftar bukti terupload.
5. Lampiran kosong menampilkan keterangan; pengawas melihat daftar kerjaan + bukti per report; Pemantauan Report menampilkan jumlah bukti.
6. Logika server tertutup test; suite tetap hijau.

---

## 3. Desain

### 3.1 Board: kunci tugas selesai (revisi #1)

**Definisi terkunci:** Task `status='done'` yang ada baris `tb_daily_reports` dengan `user_id = task.user_id`, `status='submitted'`, `report_date = DATE(task.completed_at)`.

- **`TaskService::board(User $user)`**: kolom `done` di-`orderByDesc('completed_at')->orderByDesc('id')` (todo/in_progress tetap `position`,`id`). Tiap task `done` ditandai atribut transient `is_locked` (bool). Penentuan lock pakai SATU query: ambil `report_date` submitted milik user (`DailyReport::where(user)->where(status,submitted)->pluck('report_date')` → set string `Y-m-d`), lalu `is_locked = in_set(DATE(completed_at))`. Hindari N+1.
- **`TaskService::isLocked(Task $task): bool`** — helper dipakai controller untuk guard server: `$task->status==='done' && $task->completed_at && DailyReport::where('user_id',$task->user_id)->where('status','submitted')->whereDate('report_date',$task->completed_at)->exists()`.
- **`tasks/board.blade.php`**: kartu `done` dengan `$task->is_locked` → kelas `task-locked`, ikon gembok, **tanpa** tombol drag/edit/hapus (dropdown disembunyikan). SortableJS pada kolom done: `filter: '.task-locked'`, `onMove` menolak menjatuhkan **ke posisi** yang melewati item terkunci tidak perlu — cukup `filter` (item terkunci tak bisa diangkat) + server guard. Kolom done tidak mendukung reorder manual (urut `completed_at`); drag masuk ke done tetap men-set `completed_at=now` (muncul teratas).
- **Guard server** di `TaskController`: `status()`, `update()`, `destroy()` → bila `TaskService::isLocked($task)` true, kembalikan 422 (`response()->json(['message'=>'Tugas sudah dikunci report terkirim.'],422)` untuk endpoint JSON status; `abort(422, ...)` untuk update/destroy). `reorder()` tak perlu guard khusus: kolom done diurut server by `completed_at`, jadi reorder done tak berefek tetap; kartu terkunci pun tak bisa diangkat (SortableJS `filter`).

### 3.2 Alert deadline H‑7 (revisi #2)

- **Migrasi** `add_deadline_notified_at_to_tb_tasks`: kolom `deadline_notified_at` timestamp nullable. Model `Task` fillable + cast datetime.
- **`TaskService::dueSoonFor(User $user): Collection`** — `forUser`, `status != done`, `whereNotNull('due_date')`, `due_date` ∈ `[today, today+7]`, `orderBy('due_date')`. (Untuk kartu/popup user.)
- **`TaskService::dueSoonAll(): Collection`** — sama tapi lintas user (eager-load `user`), untuk pengawas. (manager/superadmin/admin melihat semua.)
- **`TaskService::notifyDueSoon(Notifier $notifier): void`** — untuk tiap task `status != done`, `due_date` ∈ `[today, today+7]`, `deadline_notified_at` IS NULL: panggil `$notifier->deadlineReminder($task)` lalu set `deadline_notified_at = now()`. Idempoten (panggilan kedua tak menambah notifikasi). Edit `due_date` di `TaskController::update` me-`null`-kan `deadline_notified_at` bila due_date berubah (agar re-notify untuk deadline baru).
- **`Notifier::deadlineReminder(Task $task): void`** — kirim ke `$task->user` (pemilik) + `roleUsers(['manager','superadmin','admin'], $task->user)` (pengawas, kecuali bila pemilik salah satunya). Payload kategori `deadline`, judul "Tugas mendekati deadline", message `$task->title . ' • ' . due_date format`, url `route('task.board')`, icon `clock`. Reuse helper `send`.
- **Trigger (tanpa cron):** View Composer untuk `dashboard.partials.deadlines` (di `AppServiceProvider::boot`) — try/catch:
  - jalankan `notifyDueSoon()` sekali (idempoten);
  - sediakan `deadlines` = (`isOverseer` ? `dueSoonAll()` : `dueSoonFor(user)`), `isOverseer` = user hasAnyRole manager/superadmin/admin.
- **Partial** `dashboard/partials/deadlines.blade.php` di-`@include` di atas konten dashboard (setelah pengumuman): kartu alert **"Tugas Mendekati Deadline"** — daftar (judul, pemilik bila overseer, due_date, sisa hari, badge merah bila ≤2h/overdue). Bila kosong → tak render.
- **Popup SweetAlert**: di partial, bila `deadlines` tak kosong & belum tampil di sesi ini (`sessionStorage`), `Swal.fire` daftar ringkas (judul + sisa hari) sekali per sesi browser.

### 3.3 SweetAlert2 untuk semua alert/confirm (revisi #3)

- **Master layout** (`layouts/master.blade.php`): muat `sweetalert2.min.css` (head) + `sweetalert2.min.js` (sebelum custom-scripts), lalu helper global: setiap `form[data-confirm]` → cegah submit default → `Swal.fire({title, icon:'warning', showCancelButton, confirmButtonText:'Ya'})` → bila dikonfirmasi, submit form. Juga sediakan `window.swalError(msg)` / `window.swalSuccess(msg)`.
- Ganti `onsubmit="return confirm('...')"` menjadi `data-confirm="..."` di: `tasks/board.blade.php` (hapus tugas), `tasks/index.blade.php` (hapus tugas), `reports/daily.blade.php` (kirim report — konfirmasi). Hapus file lampiran (saat ini fetch) tetap pakai `Swal.fire` konfirmasi sebelum DELETE.
- Flash `session('success')`/`session('error')` ditampilkan sebagai toast SweetAlert (helper di master membaca data dari `@if(session(...))`).

### 3.4 Report wajib bukti + keterangan upload (revisi #4)

- **`DailyReportController::submit`**: setelah `getOrCreateReport`, sebelum men-submit → bila `$report->files()->count() === 0` → `back()->with('error', 'Wajib lampirkan minimal 1 bukti sebelum mengirim report.')` (tidak men-submit). Bila ada bukti → submit seperti biasa.
- **`reports/daily.blade.php`**: di kartu Lampiran (saat owner & draft) tambah keterangan: *"Lampirkan bukti pekerjaan (screenshot/file). Wajib minimal 1 sebelum Kirim Report."* Header daftar menampilkan **counter**: "{n} bukti terlampir". Tombol **Kirim** memakai `data-confirm`; bila `files` kosong, render tombol sebagai disabled + teks bantu, atau biarkan server menolak (server tetap sumber kebenaran).

### 3.5 Lampiran kosong & view pengawas (revisi #5)

- **Empty-state**: di daftar lampiran, bila `$files->isEmpty()` → tampil baris keterangan *"Belum ada bukti dilampirkan."* (bukan kosong) — berlaku untuk owner & pengawas.
- **View pengawas** (`reports/daily.blade.php` saat `!$isOwner`): tampilkan dengan jelas **daftar kerjaan** (rekap tugas selesai — sudah ada di kartu rekap) + **daftar bukti** (list file dengan link, read-only). Beri sub-judul "Bukti (lampiran)".
- **Pemantauan Report** (`reports/submissions.blade.php`): tambah kolom **"Bukti"** = jumlah file lampiran report karyawan pada tanggal itu. `DailyReportService::submissionsForDate` menambah `bukti` (jumlah `tb_daily_report_files` untuk report user+tanggal) — dihitung via satu query grouped (hindari N+1).

---

## 4. Komponen yang Dibuat / Disentuh

- **Baru:** migrasi `2026_06_24_000006_add_deadline_notified_at_to_tb_tasks.php`; `Notifier::deadlineReminder()`; `resources/views/dashboard/partials/deadlines.blade.php`; composer di `AppServiceProvider`.
- **Diubah:** `app/Models/Task.php` (fillable+cast deadline_notified_at); `app/Services/TaskService.php` (board lock + isLocked + dueSoonFor/All + notifyDueSoon); `app/Http/Controllers/Pages/TaskController.php` (lock guard di status/reorder/update/destroy; reset deadline_notified_at saat due_date berubah); `app/Http/Controllers/Pages/DailyReportController.php` (submit wajib bukti); `app/Services/DailyReportService.php` (submissions tambah `bukti`); views `tasks/board.blade.php`, `tasks/index.blade.php`, `reports/daily.blade.php`, `reports/submissions.blade.php`, `layouts/master.blade.php`, `dashboard.blade.php` (include partial deadline).

## 5. Rencana Test

- **Unit `TaskServiceTest` (tambahan)**: `board` kolom done urut `completed_at` desc + `is_locked` benar (report submitted → locked; draft/no-report → tidak); `isLocked` benar; `dueSoonFor` scope (≤7h, belum done, milik user); `notifyDueSoon` idempoten + set penanda.
- **Unit `NotifierTest` (tambahan)**: `deadlineReminder` mengirim ke pemilik + manager/superadmin/admin (jumlah benar, kategori `deadline`).
- **Feature `TaskControllerTest` (tambahan)**: `status`/`destroy` pada tugas terkunci → 422; tugas tidak terkunci tetap bisa; edit due_date me-reset `deadline_notified_at`.
- **Feature `DailyReportControllerTest` (tambahan)**: submit tanpa bukti → redirect + error, report tetap draft; submit dengan ≥1 bukti → submitted.
- **Feature `DailyReportPagesTest`/`DeadlinePartialTest` (tambahan)**: submissions menampilkan kolom bukti; partial deadline tampil di dashboard saat ada tugas ≤7h (dan kosong → tak render).

Suite via DB test (`.env.testing`, `RefreshDatabase`); `GoogleDriveService` di-mock. **Dev/prod: `php artisan migrate`** untuk kolom baru (lihat [[migrate-dev-db-after-new-migration]]).

## 6. Asumsi & Risiko

- Lock dihitung live dari `tb_daily_reports` (tanpa kolom baru di tasks) — konsisten; menghapus report submitted akan membuka kunci (diterima).
- Notifikasi deadline dipicu saat **dashboard dibuka** (tanpa cron); idempoten via `deadline_notified_at`. Bila tak ada yang membuka dashboard, notifikasi tertunda (keterbatasan tanpa scheduler) — kartu dashboard tetap muncul saat dibuka.
- `notifyDueSoon` memindai tugas ≤7h belum ter-notify tiap dashboard load; dataset kecil (aman). Composer di-guard try/catch agar tak menjatuhkan dashboard.
- SweetAlert2 dimuat global di master → konsisten; bobot kecil. `data-confirm` menggantikan `confirm()` native.
- Bukti wajib ditegakkan di server (`submit`), bukan hanya UI.
- Pengawas = manager/superadmin/admin (role pengawas yang sama dengan konteks app).
