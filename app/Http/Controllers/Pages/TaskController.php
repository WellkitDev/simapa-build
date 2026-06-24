<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use App\Services\Notifier;
use App\Services\TaskService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TaskController extends Controller
{
    public function __construct(private TaskService $service) {}

    private function isManager(): bool
    {
        return Auth::user()->hasAnyRole(['manager', 'superadmin']);
    }

    private function authorizeTask(Task $task): void
    {
        if (! $this->isManager() && $task->user_id !== Auth::id()) {
            abort(403);
        }
    }

    /** User yang board/list-nya dilihat (manager boleh ?user_id=); selain itu diri sendiri. */
    private function targetUser(Request $request): User
    {
        if ($this->isManager() && $request->filled('user_id')) {
            return User::findOrFail((int) $request->input('user_id'));
        }
        return Auth::user();
    }

    private function assignableUsers()
    {
        return $this->isManager() ? User::orderBy('name')->get(['id', 'name']) : collect();
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

        if ($assignee !== Auth::id()) {
            $notifier->taskAssigned($task, Auth::user());
        }

        return back()->with('success', 'Tugas dibuat.');
    }

    public function update(Request $request, int $id, Notifier $notifier)
    {
        $task = Task::findOrFail($id);
        $this->authorizeTask($task);
        $data = $this->validateData($request);
        $assignee = $this->resolveAssignee($request, $task->user_id);
        $reassigned = $assignee !== $task->user_id;

        $task->update([
            'user_id'     => $assignee,
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'priority'    => $data['priority'],
            'due_date'    => $data['due_date'] ?? null,
        ]);

        // Pemilik lama sengaja TIDAK dinotifikasi saat tugas dialihkan; hanya penerima baru.
        if ($reassigned && $assignee !== Auth::id()) {
            $notifier->taskAssigned($task, Auth::user());
        }

        return back()->with('success', 'Tugas diperbarui.');
    }

    public function destroy(int $id)
    {
        $task = Task::findOrFail($id);
        $this->authorizeTask($task);
        $task->delete();
        return back()->with('success', 'Tugas dihapus.');
    }

    public function status(Request $request, int $id)
    {
        $task = Task::findOrFail($id);
        $this->authorizeTask($task);
        $data = $request->validate([
            'status'   => 'required|in:todo,in_progress,done',
            'position' => 'nullable|integer|min:0',
        ]);
        $this->service->move($task, $data['status'], (int) ($data['position'] ?? 0));
        return response()->json(['ok' => true]);
    }

    public function schedule(Request $request, int $id)
    {
        $task = Task::findOrFail($id);
        $this->authorizeTask($task);
        $data = $request->validate(['due_date' => 'required|date']);
        $task->update(['due_date' => $data['due_date']]);
        return response()->json(['ok' => true]);
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

    /** Hanya manager/superadmin boleh assign ke orang lain; selain itu paksa diri sendiri / pemilik lama. */
    private function resolveAssignee(Request $request, ?int $fallback = null): int
    {
        if ($this->isManager() && $request->filled('assignee')) {
            return (int) $request->input('assignee');
        }
        return $fallback ?? Auth::id();
    }
}
