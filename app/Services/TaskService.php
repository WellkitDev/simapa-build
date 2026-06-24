<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;

class TaskService
{
    /** Tugas user dikelompokkan per status (kolom board), diurut position lalu id. */
    public function board(User $user): array
    {
        $grouped = Task::forUser($user->id)->orderBy('position')->orderBy('id')->get()->groupBy('status');

        return [
            'todo'        => $grouped->get('todo', collect())->values(),
            'in_progress' => $grouped->get('in_progress', collect())->values(),
            'done'        => $grouped->get('done', collect())->values(),
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
        foreach (array_values($orderedIds) as $i => $id) {
            Task::forUser($user->id)->where('status', $status)->where('id', (int) $id)->update(['position' => $i]);
        }
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

    private static function priorityColor(string $priority): string
    {
        return ['high' => '#EF4444', 'normal' => '#4C5FD5', 'low' => '#22C55E'][$priority] ?? '#4C5FD5';
    }
}
