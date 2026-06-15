<?php
// app/Services/ProductionDashboardService.php

namespace App\Services;

use App\Models\User;
use App\Models\TitleProgress;
use App\Models\TitleProgressLog;
use Illuminate\Support\Carbon;

class ProductionDashboardService
{
    /** KPI + data chart untuk satu production user (kerja saya). */
    public function forUser(User $user): array
    {
        $prodStages = TitleProgress::productionStages();
        $today      = Carbon::today();

        $mineActive = TitleProgress::query()
            ->where('assigned_user_id', $user->id)
            ->whereNotIn('status', TitleProgress::FINAL_STAGES);

        $perStage = (clone $mineActive)->get(['status'])
            ->groupBy('status')->map->count();

        return [
            'antrian_saya'      => (clone $mineActive)->count(),
            'belum_diambil'     => TitleProgress::whereNull('assigned_user_id')->whereIn('status', $prodStages)->count(),
            'lewat_target'      => (clone $mineActive)->whereNotNull('target_date')->whereDate('target_date', '<', $today)->count(),
            'jatuh_tempo_7'     => (clone $mineActive)->whereNotNull('target_date')
                                    ->whereDate('target_date', '>=', $today)
                                    ->whereDate('target_date', '<=', $today->copy()->addDays(7))->count(),
            'selesai_bulan_ini' => TitleProgress::where('assigned_user_id', $user->id)
                                    ->whereIn('status', TitleProgress::FINAL_STAGES)
                                    ->whereYear('started_at', $today->year)->whereMonth('started_at', $today->month)->count(),
            'per_stage'         => $this->stageChart($perStage),
            'activity_30d'      => $this->activitySeries($user->id),
        ];
    }

    /** KPI + data chart global (manager/superadmin). */
    public function global(): array
    {
        $prodStages = TitleProgress::productionStages();
        $today      = Carbon::today();

        $inProduction = TitleProgress::query()->whereIn('status', $prodStages);

        $perStage = (clone $inProduction)->get(['status'])->groupBy('status')->map->count();

        return [
            'total_in_production' => (clone $inProduction)->count(),
            'lewat_target'        => TitleProgress::whereNotIn('status', TitleProgress::FINAL_STAGES)
                                        ->whereNotNull('target_date')->whereDate('target_date', '<', $today)->count(),
            'jatuh_tempo_7'       => TitleProgress::whereNotIn('status', TitleProgress::FINAL_STAGES)
                                        ->whereNotNull('target_date')
                                        ->whereDate('target_date', '>=', $today)
                                        ->whereDate('target_date', '<=', $today->copy()->addDays(7))->count(),
            'selesai_bulan_ini'   => TitleProgress::whereIn('status', TitleProgress::FINAL_STAGES)
                                        ->whereYear('started_at', $today->year)->whereMonth('started_at', $today->month)->count(),
            'per_stage'           => $this->stageChart($perStage),
            'completion_trend'    => $this->completionTrend(),
        ];
    }

    /** {labels:[stage], series:[count]} untuk donut. */
    private function stageChart($perStage): array
    {
        return [
            'labels' => $perStage->keys()->map(fn ($s) => \Illuminate\Support\Str::title(str_replace('_', ' ', $s)))->values()->all(),
            'series' => $perStage->values()->all(),
        ];
    }

    /** Aktivitas harian user (log) 30 hari → {labels, series}. */
    private function activitySeries(int $userId): array
    {
        $days = collect(range(29, 0))->map(fn ($i) => Carbon::now()->subDays($i)->format('Y-m-d'));
        $byDate = TitleProgressLog::where('changed_by', $userId)
            ->where('created_at', '>=', Carbon::now()->subDays(29)->startOfDay())
            ->get(['created_at'])
            ->groupBy(fn ($l) => $l->created_at->format('Y-m-d'))->map->count();

        return [
            'labels' => $days->map(fn ($d) => Carbon::parse($d)->format('M d'))->all(),
            'series' => $days->map(fn ($d) => (int) ($byDate[$d] ?? 0))->all(),
        ];
    }

    /** Penyelesaian global per hari 30 hari (log to_value Terbit/Publish) → {labels, series}. */
    private function completionTrend(): array
    {
        $days = collect(range(29, 0))->map(fn ($i) => Carbon::now()->subDays($i)->format('Y-m-d'));
        $byDate = TitleProgressLog::whereIn('to_value', ['Terbit', 'Publish'])
            ->where('created_at', '>=', Carbon::now()->subDays(29)->startOfDay())
            ->get(['created_at'])
            ->groupBy(fn ($l) => $l->created_at->format('Y-m-d'))->map->count();

        return [
            'labels' => $days->map(fn ($d) => Carbon::parse($d)->format('M d'))->all(),
            'series' => $days->map(fn ($d) => (int) ($byDate[$d] ?? 0))->all(),
        ];
    }
}
