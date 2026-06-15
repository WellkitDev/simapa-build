<?php
// app/Services/PerformanceService.php

namespace App\Services;

use App\Models\User;
use App\Models\TitleProgress;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PerformanceService
{
    /**
     * Metrik on-time per editor. Atribusi ke assigned_user_id.
     * "Selesai" = status final & started_at dalam periode. "Tepat waktu" = started_at <= target_date.
     */
    public function forEditor(User $editor, int $days = 30): array
    {
        $since = Carbon::now()->subDays($days)->startOfDay();

        $completed = TitleProgress::query()
            ->where('assigned_user_id', $editor->id)
            ->whereIn('status', TitleProgress::FINAL_STAGES)
            ->where('started_at', '>=', $since)
            ->get(['id', 'started_at', 'target_date']);

        $withTarget = $completed->filter(fn ($p) => $p->target_date !== null);
        $onTime = $withTarget->filter(fn ($p) =>
            Carbon::parse($p->started_at)->toDateString() <= Carbon::parse($p->target_date)->toDateString());

        $rate = $withTarget->count() > 0
            ? round($onTime->count() / $withTarget->count() * 100, 1)
            : null;

        $activeQueue = TitleProgress::query()
            ->where('assigned_user_id', $editor->id)
            ->whereNotIn('status', TitleProgress::FINAL_STAGES)
            ->count();

        return [
            'completed'    => $completed->count(),
            'with_target'  => $withTarget->count(),
            'on_time'      => $onTime->count(),
            'on_time_rate' => $rate,
            'active_queue' => $activeQueue,
        ];
    }

    /** Statistik untuk semua editor (production/manager) — untuk tabel/bar global. */
    public function allEditors(int $days = 30): Collection
    {
        return User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['production', 'manager']))
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id'    => $u->id,
                'name'  => $u->name,
                'stats' => $this->forEditor($u, $days),
            ]);
    }
}
