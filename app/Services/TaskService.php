<?php

namespace App\Services;

use App\Models\DailyReport;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TaskService
{
    /** Tugas user per kolom. Kolom done diurut completed_at desc + flag is_locked. */
    public function board(User $user): array
    {
        $tasks = Task::forUser($user->id)->orderBy('position')->orderBy('id')->get();

        // Tanggal report submitted milik user → menandai tugas selesai yang terkunci.
        $lockedDates = DailyReport::where('user_id', $user->id)->where('status', 'submitted')
            ->get(['report_date'])->map(fn ($r) => $r->report_date->toDateString())->flip();

        $grouped = $tasks->groupBy('status');
        $done = $grouped->get('done', collect())->sortByDesc('completed_at')->values();
        $done->each(function (Task $t) use ($lockedDates) {
            $t->is_locked = $t->completed_at && $lockedDates->has($t->completed_at->toDateString());
        });

        return [
            'todo'        => $grouped->get('todo', collect())->values(),
            'in_progress' => $grouped->get('in_progress', collect())->values(),
            'done'        => $done,
        ];
    }

    /** Event FullCalendar untuk tugas user yang punya due_date (opsional rentang). */
    public function calendarEvents(User $user, ?string $start = null, ?string $end = null): array
    {
        $query = Task::forUser($user->id)->whereNotNull('due_date');
        if ($start) {
            $query->where('due_date', '>=', $start);
        }
        if ($end) {
            $query->where('due_date', '<=', $end);
        }

        return $query->get()->map(fn (Task $t) => [
            'id'            => (string) $t->id,
            'title'         => $t->title,
            'start'         => $t->due_date?->toDateString(),
            'allDay'        => true,
            'color'         => $t->status === 'done' ? '#94A3B8' : self::priorityColor($t->priority),
            'extendedProps' => [
                'priority'    => $t->priority,
                'description' => $t->description,
                'assignee'    => $t->user_id,
                'status'      => $t->status,
            ],
        ])->all();
    }

    /** Pindah status + posisi (drag board). Set/null completed_at sesuai status. */
    public function move(Task $task, string $status, int $position): void
    {
        if (! in_array($status, Task::STATUSES, true)) {
            throw new \InvalidArgumentException("Status tugas tidak valid: {$status}");
        }

        $task->update([
            'status'       => $status,
            'position'     => $position,
            'completed_at' => $status === 'done' ? ($task->completed_at ?? now()) : null,
        ]);
    }

    /** Tulis ulang position 0..n untuk satu kolom milik user. */
    public function reorder(User $user, string $status, array $orderedIds): void
    {
        DB::transaction(function () use ($user, $status, $orderedIds) {
            foreach (array_values($orderedIds) as $i => $id) {
                Task::forUser($user->id)->where('status', $status)->where('id', (int) $id)->update(['position' => $i]);
            }
        });
    }

    /** Pemantauan: KPI + baris tugas (opsional filter user/status). */
    public function monitor(?int $userId = null, ?string $status = null): array
    {
        $rows = Task::query()
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->with('user')
            ->orderByRaw("FIELD(priority,'high','normal','low')")
            ->orderByRaw('due_date IS NULL, due_date')
            ->get();

        // KPI di-scope ke user saja (sengaja mengabaikan filter status agar ringkasan tetap utuh).
        $scope  = Task::query()->when($userId, fn ($q) => $q->where('user_id', $userId));
        $counts = (clone $scope)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $overdue = (clone $scope)->whereNotNull('due_date')->whereDate('due_date', '<', today())->where('status', '!=', 'done')->count();

        return [
            'kpi' => [
                'total'       => (int) $counts->sum(),
                'todo'        => (int) ($counts['todo'] ?? 0),
                'in_progress' => (int) ($counts['in_progress'] ?? 0),
                'done'        => (int) ($counts['done'] ?? 0),
                'overdue'     => $overdue,
            ],
            'rows' => $rows,
        ];
    }

    /** True bila task done & tanggal completed_at-nya punya report submitted. */
    public function isLocked(Task $task): bool
    {
        if ($task->status !== 'done' || ! $task->completed_at) {
            return false;
        }
        return DailyReport::where('user_id', $task->user_id)->where('status', 'submitted')
            ->whereDate('report_date', $task->completed_at)->exists();
    }

    /** Tugas user belum selesai dengan due_date dalam 7 hari ke depan. */
    public function dueSoonFor(User $user): \Illuminate\Support\Collection
    {
        return Task::forUser($user->id)->where('status', '!=', 'done')->whereNotNull('due_date')
            ->whereBetween('due_date', [today()->toDateString(), today()->addDays(7)->toDateString()])
            ->orderBy('due_date')->get();
    }

    /** Lintas user (untuk pengawas). */
    public function dueSoonAll(): \Illuminate\Support\Collection
    {
        return Task::query()->where('status', '!=', 'done')->whereNotNull('due_date')
            ->whereBetween('due_date', [today()->toDateString(), today()->addDays(7)->toDateString()])
            ->with('user')->orderBy('due_date')->get();
    }

    /** Kirim notifikasi deadline sekali per tugas (anti-spam via deadline_notified_at). */
    public function notifyDueSoon(Notifier $notifier): void
    {
        $tasks = Task::query()->where('status', '!=', 'done')->whereNotNull('due_date')
            ->whereBetween('due_date', [today()->toDateString(), today()->addDays(7)->toDateString()])
            ->whereNull('deadline_notified_at')->get();

        foreach ($tasks as $task) {
            $notifier->deadlineReminder($task);
            $task->update(['deadline_notified_at' => now()]);
        }
    }

    private static function priorityColor(string $priority): string
    {
        return ['high' => '#EF4444', 'normal' => '#4C5FD5', 'low' => '#22C55E'][$priority] ?? '#4C5FD5';
    }
}
