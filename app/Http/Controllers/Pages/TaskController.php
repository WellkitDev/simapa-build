<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use App\Services\Notifier;
use App\Services\TaskThreadService;
use App\Services\TaskService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class TaskController extends Controller
{
    public function __construct(private TaskService $service) {}

    /**
     * Manager/superadmin: boleh MENYUNTING dan MENGHAPUS tugas milik siapa pun.
     *
     * Menugaskan tak lagi lewat sini — sejak 2026-08-23 setiap pengguna boleh memberi
     * tugas ke siapa pun. Dulu gerbangnya `manager|superadmin`, dan di produksi TAK ADA
     * satu pun akun manager (admin 6 · marketing 2 · production 4 · superadmin 1),
     * sehingga praktis hanya satu orang di seluruh kantor yang bisa membagi pekerjaan.
     */
    private function isManager(): bool
    {
        return Auth::user()->hasAnyRole(['manager', 'superadmin']);
    }

    /**
     * Gerbang MEMBACA & MENGERJAKAN: pelaksana, pemberi tugas, atau manager.
     *
     * Dipakai untuk detail, laporan, dan perpindahan status — semuanya bagian dari
     * mengerjakan tugas, bukan mengubah syaratnya.
     */
    private function authorizeTask(Task $task): void
    {
        if (! $task->bolehDibaca(Auth::user())) {
            abort(403);
        }
    }

    /**
     * Gerbang MENGUBAH SYARAT: sunting, geser tenggat, hapus.
     *
     * Pelaksana sengaja tak lolos di sini. Aturannya tinggal di Task::bolehDikelola()
     * supaya layar dan server membaca satu sumber yang sama — gerbang yang hanya ada di
     * server menghasilkan tombol yang memberi harapan lalu memunculkan 403.
     */
    private function authorizeKelola(Task $task): void
    {
        if (! $task->bolehDikelola(Auth::user())) {
            abort(403);
        }
    }

    private function abortIfLocked(Task $task): void
    {
        if ($this->service->isLocked($task)) {
            abort(422, 'Tugas sudah dikunci report terkirim.');
        }
    }

    /** User yang board/list-nya dilihat (manager boleh ?user_id=); selain itu diri sendiri. */
    private function targetUser(Request $request): User
    {
        // Terbuka untuk semua: memberi tugas tanpa bisa melihat hasilnya adalah setengah
        // fitur. Papan tugas di kantor 13 orang bukan rahasia.
        if ($request->filled('user_id')) {
            return User::findOrFail((int) $request->input('user_id'));
        }
        return Auth::user();
    }

    /** Setiap pengguna boleh memberi tugas ke siapa pun. */
    private function assignableUsers()
    {
        return User::orderBy('name')->get(['id', 'name']);
    }

    public function index(Request $request)
    {
        $user = $this->targetUser($request);
        $tasks = Task::forUser($user->id)->orderBy('position')->orderBy('id')->get();
        return view('tasks.index', [
            'tasks' => $tasks, 'owner' => $user,
            'isManager' => $this->isManager(), 'assignees' => $this->assignableUsers(),
        ]);
    }

    public function board(Request $request)
    {
        $user = $this->targetUser($request);
        return view('tasks.board', [
            'board' => $this->service->board($user), 'owner' => $user,
            'isManager' => $this->isManager(), 'assignees' => $this->assignableUsers(),
        ]);
    }

    public function calendar(Request $request)
    {
        $user = $this->targetUser($request);
        return view('tasks.calendar', [
            'owner' => $user, 'isManager' => $this->isManager(), 'assignees' => $this->assignableUsers(),
        ]);
    }

    public function events(Request $request)
    {
        $user = $this->targetUser($request);
        return response()->json($this->service->calendarEvents($user, $request->query('start'), $request->query('end')));
    }

    public function store(Request $request, Notifier $notifier)
    {
        $data = $this->validateData($request);
        $assignee = $this->resolveAssignee($request);

        $task = Task::create([
            'user_id'     => $assignee,
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'priority'    => $data['priority'],
            'due_date'    => $data['due_date'] ?? null,
            'status'      => in_array($request->input('status'), Task::STATUSES, true) ? $request->input('status') : 'todo',
            'created_by'  => Auth::id(),
        ]);

        $utas = app(TaskThreadService::class);
        $utas->catat($task, 'Tugas dibuat.', Auth::user());

        if ($assignee !== Auth::id()) {
            $notifier->taskAssigned($task, Auth::user());
            $utas->catat($task, 'Ditugaskan ke ' . $task->user?->name . '.', Auth::user());
        }

        return back()->with('success', 'Tugas dibuat.');
    }

    public function update(Request $request, int $id, Notifier $notifier)
    {
        $task = Task::findOrFail($id);
        $this->authorizeKelola($task);
        $this->abortIfLocked($task);
        $data = $this->validateData($request);
        $assignee = $this->resolveAssignee($request, $task->user_id);
        $reassigned = $assignee !== $task->user_id;
        $newDue = $data['due_date'] ?? null;
        $dueChanged = optional($task->due_date)->toDateString() !== $newDue;

        $task->update([
            'user_id'     => $assignee,
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'priority'    => $data['priority'],
            'due_date'    => $newDue,
            'deadline_notified_at' => $dueChanged ? null : $task->deadline_notified_at,
        ]);

        // Pemilik lama sengaja TIDAK dinotifikasi saat tugas dialihkan; hanya penerima baru.
        if ($reassigned && $assignee !== Auth::id()) {
            $notifier->taskAssigned($task, Auth::user());
        }

        // Perpindahan yang tak tercatat membuat utasnya berbohong soal apa yang terjadi.
        $utas = app(TaskThreadService::class);
        if ($reassigned) {
            $utas->catat($task, 'Dialihkan ke ' . $task->fresh()->user?->name . '.', Auth::user());
        }
        if ($dueChanged) {
            $utas->catat($task, $newDue
                ? 'Tenggat disetel ke ' . Carbon::parse($newDue)->translatedFormat('j M Y') . '.'
                : 'Tenggat dilepas.', Auth::user());
        }

        return back()->with('success', 'Tugas diperbarui.');
    }

    public function destroy(int $id)
    {
        $task = Task::findOrFail($id);
        $this->authorizeKelola($task);
        $this->abortIfLocked($task);
        $task->delete();
        return back()->with('success', 'Tugas dihapus.');
    }

    public function status(Request $request, int $id)
    {
        $task = Task::findOrFail($id);
        $this->authorizeTask($task);
        $this->abortIfLocked($task);
        $data = $request->validate([
            'status'   => 'required|in:todo,in_progress,done',
            'position' => 'nullable|integer|min:0',
        ]);
        $sebelum = $task->status;
        $this->service->move($task, $data['status'], (int) ($data['position'] ?? 0));

        if ($sebelum !== $data['status']) {
            app(TaskThreadService::class)->catat(
                $task,
                'Status: ' . Task::labelStatus($sebelum) . ' → ' . Task::labelStatus($data['status']) . '.',
                Auth::user()
            );
        }

        return response()->json(['ok' => true]);
    }

    public function schedule(Request $request, int $id)
    {
        $task = Task::findOrFail($id);
        $this->authorizeKelola($task);
        $this->abortIfLocked($task);
        $data = $request->validate(['due_date' => 'required|date']);
        $task->update(['due_date' => $data['due_date']]);
        return response()->json(['ok' => true]);
    }

    /**
     * Halaman detail sebuah tugas: keadaannya di atas, utas aktivitasnya di bawah.
     *
     * Utas butuh ruang yang tak dimiliki modal. Modal tetap ada untuk sunting kilat dari
     * papan; halaman ini untuk membaca dan menulis laporan.
     */
    public function show(int $id, TaskThreadService $utas)
    {
        $task = Task::with(['user', 'creator', 'updates.author'])->findOrFail($id);
        $this->authorizeTask($task);

        return view('tasks.show', [
            'task'      => $task,
            'ringkasan' => $utas->ringkasan($task),
            'terkunci'  => $this->service->isLocked($task),
        ]);
    }

    /**
     * Laporan dari orang: pelaksana mengabari kemajuannya, pemberi tugas menambah arahan.
     *
     * Gerbangnya authorizeTask() yang sama dengan sunting - satu aturan, bukan dua.
     */
    public function report(Request $request, int $id, TaskThreadService $utas)
    {
        $task = Task::findOrFail($id);
        $this->authorizeTask($task);

        // Di-trim lebih dulu supaya spasi saja tak lolos sebagai laporan kosong.
        $request->merge(['body' => trim((string) $request->input('body'))]);

        $data = $request->validate([
            'body'     => 'required|string|max:4000',
            'progress' => 'nullable|integer|min:0|max:100',
        ]);

        $utas->laporkan($task, Auth::user(), $data['body'],
            $request->filled('progress') ? (int) $data['progress'] : null);

        return back()->with('success', 'Laporan tercatat.');
    }

    public function reorder(Request $request)
    {
        $data = $request->validate([
            'status' => 'required|in:todo,in_progress,done',
            'ids'    => 'array',
            'ids.*'  => 'integer',
        ]);
        $this->service->reorder($this->targetUser($request), $data['status'], $data['ids'] ?? []);
        return response()->json(['ok' => true]);
    }

    public function monitor(Request $request)
    {
        $request->validate([
            'user_id' => 'nullable|integer|exists:users,id',
            'status'  => 'nullable|in:todo,in_progress,done',
        ]);

        $data = $this->service->monitor(
            $request->filled('user_id') ? (int) $request->input('user_id') : null,
            $request->filled('status') ? $request->input('status') : null,
        );
        return view('tasks.monitor', [
            'kpi' => $data['kpi'], 'rows' => $data['rows'],
            'employees' => $this->assignableUsers(),
            'fUser' => $request->input('user_id'), 'fStatus' => $request->input('status'),
        ]);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority'    => 'required|in:low,normal,high',
            'due_date'    => 'nullable|date',
            // 'assignee' divalidasi di sini tapi dipakai lewat resolveAssignee() (bukan langsung dari hasil validasi).
            'assignee'    => 'nullable|integer|exists:users,id',
        ]);
    }

    /**
     * Penerima tugas: yang dipilih di formulir, atau pemilik lama saat menyunting,
     * atau diri sendiri.
     *
     * TAK ADA gerbang peran di sini sejak 2026-08-23 - setiap pengguna boleh memberi
     * tugas ke siapa pun. Yang menjaga penyalahgunaan bukan siapa boleh menugaskan,
     * melainkan authorizeTask(): tugas hanya bisa disunting pemilik, pembuatnya, atau
     * manager.
     */
    private function resolveAssignee(Request $request, ?int $fallback = null): int
    {
        if ($request->filled('assignee')) {
            return (int) $request->input('assignee');
        }
        return $fallback ?? Auth::id();
    }
}
