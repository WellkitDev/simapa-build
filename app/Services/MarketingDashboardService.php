<?php
// app/Services/MarketingDashboardService.php

namespace App\Services;

use App\Models\User;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TitleProgress;
use App\Models\TitleProgressLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class MarketingDashboardService
{
    /** KPI pemasukan + order + progres naskah + data chart untuk satu marketing (ter-scope order.user_id). */
    public function forUser(User $user): array
    {
        $uid   = $user->id;
        $today = Carbon::today();

        $income = fn () => Payment::query()
            ->where('status', 'paid')
            ->whereHas('order', fn ($q) => $q->where('user_id', $uid));

        $prog = fn () => TitleProgress::query()
            ->whereHas('orderDetail.order', fn ($q) => $q->where('user_id', $uid));

        return [
            // Pemasukan (tiap Payment paid dihitung — termasuk DP/parsial/pelunasan)
            'pemasukan_hari_ini'     => (int) $income()->whereDate('paid_at', $today)->sum('amount'),
            'pemasukan_minggu_ini'   => (int) $income()->whereBetween('paid_at', [$today->copy()->startOfWeek(), $today->copy()->endOfWeek()])->sum('amount'),
            'pemasukan_tahun_ini'    => (int) $income()->whereYear('paid_at', $today->year)->sum('amount'),
            'jumlah_order_tahun_ini' => Order::where('user_id', $uid)->whereYear('ordered_at', $today->year)->count(),
            'income_trend'           => $this->dailySum($income(), 'paid_at', 'amount'),
            'order_trend'            => $this->dailyCount(Order::where('user_id', $uid), 'ordered_at'),

            // Progres naskah (dari order milik marketing)
            'naskah_aktif'      => (clone $prog())->whereNotIn('status', TitleProgress::FINAL_STAGES)->where('status', '!=', 'menunggu_proses')->count(),
            'belum_diproses'    => (clone $prog())->where('status', 'menunggu_proses')->count(),
            'lewat_target'      => (clone $prog())->whereNotIn('status', TitleProgress::FINAL_STAGES)->whereNotNull('target_date')->whereDate('target_date', '<', $today)->count(),
            'jatuh_tempo_7'     => (clone $prog())->whereNotIn('status', TitleProgress::FINAL_STAGES)->whereNotNull('target_date')
                                    ->whereDate('target_date', '>=', $today)->whereDate('target_date', '<=', $today->copy()->addDays(7))->count(),
            'selesai_bulan_ini' => (clone $prog())->whereIn('status', TitleProgress::FINAL_STAGES)->whereYear('started_at', $today->year)->whereMonth('started_at', $today->month)->count(),
            'total_selesai'     => (clone $prog())->whereIn('status', TitleProgress::FINAL_STAGES)->count(),
            'per_stage'         => $this->stageChart(
                                    (clone $prog())->whereNotIn('status', TitleProgress::FINAL_STAGES)->get(['status'])->groupBy('status')->map->count()
                                   ),
            'completion_trend'  => $this->completionTrend($uid),
        ];
    }

    private function stageChart($perStage): array
    {
        return [
            'labels' => $perStage->keys()->map(fn ($s) => Str::title(str_replace('_', ' ', $s)))->values()->all(),
            'series' => $perStage->values()->all(),
        ];
    }

    /** Σ kolom per hari 30 hari → {labels, series}. */
    private function dailySum($query, string $dateCol, string $sumCol): array
    {
        $days = collect(range(29, 0))->map(fn ($i) => Carbon::now()->subDays($i)->format('Y-m-d'));
        $byDate = $query->where($dateCol, '>=', Carbon::now()->subDays(29)->startOfDay())
            ->get([$dateCol, $sumCol])
            ->groupBy(fn ($r) => Carbon::parse($r->$dateCol)->format('Y-m-d'))
            ->map(fn ($g) => (int) $g->sum($sumCol));

        return [
            'labels' => $days->map(fn ($d) => Carbon::parse($d)->format('M d'))->all(),
            'series' => $days->map(fn ($d) => (int) ($byDate[$d] ?? 0))->all(),
        ];
    }

    /** Count per hari 30 hari → {labels, series}. */
    private function dailyCount($query, string $dateCol): array
    {
        $days = collect(range(29, 0))->map(fn ($i) => Carbon::now()->subDays($i)->format('Y-m-d'));
        $byDate = $query->where($dateCol, '>=', Carbon::now()->subDays(29)->startOfDay())
            ->get([$dateCol])
            ->groupBy(fn ($r) => Carbon::parse($r->$dateCol)->format('Y-m-d'))
            ->map->count();

        return [
            'labels' => $days->map(fn ($d) => Carbon::parse($d)->format('M d'))->all(),
            'series' => $days->map(fn ($d) => (int) ($byDate[$d] ?? 0))->all(),
        ];
    }

    /** Penyelesaian naskah marketing per hari 30 hari (log to_value Terbit/Publish, scoped). */
    private function completionTrend(int $uid): array
    {
        $days = collect(range(29, 0))->map(fn ($i) => Carbon::now()->subDays($i)->format('Y-m-d'));
        $byDate = TitleProgressLog::whereIn('to_value', ['Terbit', 'Publish'])
            ->whereHas('titleProgress.orderDetail.order', fn ($q) => $q->where('user_id', $uid))
            ->where('created_at', '>=', Carbon::now()->subDays(29)->startOfDay())
            ->get(['created_at'])
            ->groupBy(fn ($l) => $l->created_at->format('Y-m-d'))->map->count();

        return [
            'labels' => $days->map(fn ($d) => Carbon::parse($d)->format('M d'))->all(),
            'series' => $days->map(fn ($d) => (int) ($byDate[$d] ?? 0))->all(),
        ];
    }
}
