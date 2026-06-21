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

        // Definisi kanonik (sama dengan FinancialReportService): uang masuk = Payment status paid, scoped order user.
        $income = fn () => Payment::approved()->forOrdersOf($user);

        $prog = fn () => TitleProgress::query()
            ->whereHas('orderDetail.order', fn ($q) => $q->where('user_id', $uid));

        // Nilai periode berjalan (dipakai kartu + sebagai 'current' delta).
        $incHari   = (int) $income()->whereDate('paid_at', $today)->sum('amount');
        $incMinggu = (int) $income()->whereBetween('paid_at', [$today->copy()->startOfWeek(), $today->copy()->endOfWeek()])->sum('amount');
        $incTahun  = (int) $income()->whereYear('paid_at', $today->year)->sum('amount');
        $jmlOrder    = Order::where('user_id', $uid)->whereYear('ordered_at', $today->year)->count();
        $jmlOrderBln = Order::where('user_id', $uid)->whereYear('ordered_at', $today->year)->whereMonth('ordered_at', $today->month)->count();

        // Pembanding setara (period-to-date pada periode sebelumnya).
        $incHariPrev    = (int) $income()->whereDate('paid_at', $today->copy()->subDay())->sum('amount');
        $incMingguPrev  = (int) $income()->whereBetween('paid_at', [$today->copy()->startOfWeek()->subWeek(), $today->copy()->endOfDay()->subWeek()])->sum('amount');
        $incTahunPrev   = (int) $income()->whereBetween('paid_at', [$today->copy()->startOfYear()->subYear(), $today->copy()->endOfDay()->subYear()])->sum('amount');
        $jmlOrderPrev   = Order::where('user_id', $uid)->whereBetween('ordered_at', [$today->copy()->startOfYear()->subYear(), $today->copy()->endOfDay()->subYear()])->count();
        $jmlOrderBlnPrev = Order::where('user_id', $uid)->whereBetween('ordered_at', [$today->copy()->startOfMonth()->subMonthNoOverflow(), $today->copy()->endOfDay()->subMonthNoOverflow()])->count();

        return [
            // Pemasukan (tiap Payment paid dihitung — termasuk DP/parsial/pelunasan)
            'pemasukan_hari_ini'     => $incHari,
            'pemasukan_minggu_ini'   => $incMinggu,
            'pemasukan_tahun_ini'    => $incTahun,
            'jumlah_order_tahun_ini' => $jmlOrder,
            'jumlah_order_bulan_ini' => $jmlOrderBln,

            // Indikator delta vs periode sebelumnya (period-to-date setara)
            'pemasukan_hari_ini_delta'     => $this->delta($incHari, $incHariPrev),
            'pemasukan_minggu_ini_delta'   => $this->delta($incMinggu, $incMingguPrev),
            'pemasukan_tahun_ini_delta'    => $this->delta($incTahun, $incTahunPrev),
            'jumlah_order_delta'           => $this->delta($jmlOrder, $jmlOrderPrev),
            'jumlah_order_bulan_ini_delta' => $this->delta($jmlOrderBln, $jmlOrderBlnPrev),

            // KPI baru
            'total_piutang'   => (int) ((new FinancialReportService())->piutang($user)['kpi']['sisa']),
            'rata_rata_order' => $this->avgOrderValue($uid, $today->year),

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
                                    (clone $prog())->whereNotIn('status', TitleProgress::FINAL_STAGES)->where('status', '!=', 'menunggu_proses')->get(['status'])->groupBy('status')->map->count()
                                   ),
            'completion_trend'  => $this->completionTrend($uid),
            'deadline_rows'     => $this->deadlineRows($user),
        ];
    }

    /** Indikator naik/turun: pct (null bila pembanding 0) + arah up/down/flat. */
    private function delta(int $current, int $previous): array
    {
        if ($previous === 0) {
            return ['pct' => null, 'dir' => $current > 0 ? 'up' : 'flat'];
        }
        $pct = round(($current - $previous) / $previous * 100, 1);
        return ['pct' => abs($pct), 'dir' => $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat')];
    }

    /** Rata-rata nilai order (cost_amount) tahun berjalan; 0 bila tanpa order. */
    private function avgOrderValue(int $uid, int $year): int
    {
        $orders = Order::where('user_id', $uid)->whereYear('ordered_at', $year)->with('details')->get();
        $count = $orders->count();
        if ($count === 0) {
            return 0;
        }
        $sum = (int) $orders->sum(fn ($o) => (int) ($o->details->cost_amount ?? 0));
        return intdiv($sum, $count);
    }

    /** Baris tabel naskah aktif mendekati/lewat deadline (ter-scope order marketing). */
    public function deadlineRows(User $user): \Illuminate\Support\Collection
    {
        $today = Carbon::today();

        return TitleProgress::query()
            ->whereNotIn('status', TitleProgress::FINAL_STAGES)
            ->whereNotNull('target_date')
            ->whereHas('orderDetail.order', fn ($q) => $q->where('user_id', $user->id))
            ->with('orderDetail.order')
            ->get()
            ->map(function (TitleProgress $tp) use ($today) {
                $target  = Carbon::parse($tp->target_date)->startOfDay();
                $overdue = $target->lt($today);
                $days    = $today->diffInDays($target);     // absolut (>= 0)
                $signed  = $overdue ? -$days : $days;       // negatif bila lewat
                $isD7    = ! $overdue && $target->lte($today->copy()->addDays(7));
                $isMonth = ! $overdue && $target->lte($today->copy()->endOfMonth());

                return [
                    'order_detail_id' => $tp->order_detail_id,
                    'title'        => $tp->orderDetail->title,
                    'code_order'   => $tp->orderDetail->order->code_order,
                    'stage'        => Str::title(str_replace('_', ' ', $tp->status)),
                    'target_date'  => $target->format('Y-m-d'),
                    'target_label' => $target->format('d M Y'),
                    'days'         => $signed,
                    'days_label'   => $overdue
                        ? 'Lewat ' . $days . ' hari'
                        : ($days === 0 ? 'Hari ini' : $days . ' hari lagi'),
                    'priority'     => $tp->priority,
                    'overdue'      => $overdue ? 1 : 0,
                    'd7'           => $isD7 ? 1 : 0,
                    'month'        => $isMonth ? 1 : 0,
                ];
            })
            ->sortBy('days')
            ->values();
    }

    private function stageChart($perStage): array
    {
        return [
            'labels' => $perStage->keys()->map(fn ($s) => Str::title(str_replace('_', ' ', $s)))->values()->all(),
            'series' => $perStage->values()->all(),
        ];
    }

    /** Σ kolom per hari 90 hari → {labels, series}. */
    private function dailySum($query, string $dateCol, string $sumCol): array
    {
        $days = collect(range(89, 0))->map(fn ($i) => Carbon::now()->subDays($i)->format('Y-m-d'));
        $byDate = $query->where($dateCol, '>=', Carbon::now()->subDays(89)->startOfDay())
            ->get([$dateCol, $sumCol])
            ->groupBy(fn ($r) => Carbon::parse($r->$dateCol)->format('Y-m-d'))
            ->map(fn ($g) => (int) $g->sum($sumCol));

        return [
            'labels' => $days->map(fn ($d) => Carbon::parse($d)->format('M d'))->all(),
            'series' => $days->map(fn ($d) => (int) ($byDate[$d] ?? 0))->all(),
        ];
    }

    /** Count per hari 90 hari → {labels, series}. */
    private function dailyCount($query, string $dateCol): array
    {
        $days = collect(range(89, 0))->map(fn ($i) => Carbon::now()->subDays($i)->format('Y-m-d'));
        $byDate = $query->where($dateCol, '>=', Carbon::now()->subDays(89)->startOfDay())
            ->get([$dateCol])
            ->groupBy(fn ($r) => Carbon::parse($r->$dateCol)->format('Y-m-d'))
            ->map->count();

        return [
            'labels' => $days->map(fn ($d) => Carbon::parse($d)->format('M d'))->all(),
            'series' => $days->map(fn ($d) => (int) ($byDate[$d] ?? 0))->all(),
        ];
    }

    /** Penyelesaian naskah marketing per hari 90 hari (log to_value Terbit/Publish, scoped). */
    private function completionTrend(int $uid): array
    {
        $days = collect(range(89, 0))->map(fn ($i) => Carbon::now()->subDays($i)->format('Y-m-d'));
        // to_value is title-cased by TitleProgressService::log() (e.g. 'Terbit'/'Publish')
        $byDate = TitleProgressLog::whereIn('to_value', ['Terbit', 'Publish'])
            ->whereHas('titleProgress.orderDetail.order', fn ($q) => $q->where('user_id', $uid))
            ->where('created_at', '>=', Carbon::now()->subDays(89)->startOfDay())
            ->get(['created_at'])
            ->groupBy(fn ($l) => $l->created_at->format('Y-m-d'))->map->count();

        return [
            'labels' => $days->map(fn ($d) => Carbon::parse($d)->format('M d'))->all(),
            'series' => $days->map(fn ($d) => (int) ($byDate[$d] ?? 0))->all(),
        ];
    }
}
