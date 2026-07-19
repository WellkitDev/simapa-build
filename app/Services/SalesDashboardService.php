<?php
// app/Services/SalesDashboardService.php

namespace App\Services;

use App\Models\User;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TitleProgress;
use App\Models\TitleProgressLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SalesDashboardService
{
    /** KPI pemasukan + order + progres naskah + data chart untuk satu marketing (ter-scope order.user_id). */
    public function forUser(User $user): array
    {
        return $this->build($user);
    }

    /**
     * KPI penjualan tingkat perusahaan.
     * $filter null = seluruh marketing; diisi = satu marketing (hasil identik dengan forUser).
     */
    public function forCompany(?User $filter = null): array
    {
        return $this->build($filter);
    }

    /**
     * Satu baris per marketing untuk chart perbandingan (YTD tahun berjalan):
     * [ ['name','pemasukan','refund','order'], ... ] urut pemasukan desc.
     * Refund TIDAK mengurangi pemasukan (kolom terpisah).
     */
    public function perMarketingComparison(): Collection
    {
        $year = Carbon::today()->year;

        return User::role('marketing')->orderBy('name')->get()
            ->map(fn (User $m) => [
                'name'      => $m->name,
                'pemasukan' => (int) Payment::income()->forOrdersOf($m)->whereYear('paid_at', $year)->sum('amount'),
                'refund'    => (int) Payment::refund()->forOrdersOf($m)->whereYear('paid_at', $year)->sum('amount'),
                'order'     => Order::where('user_id', $m->id)->whereYear('ordered_at', $year)->count(),
            ])
            ->sortByDesc('pemasukan')
            ->values();
    }

    /** $scopeUser null = tanpa filter (seluruh perusahaan) — idiom yang sama dengan Payment::forOrdersOf(). */
    private function build(?User $scopeUser): array
    {
        $uid   = $scopeUser?->id;
        $today = Carbon::today();

        // Uang masuk = definisi kanonik Payment::income() (paid, bukan refund).
        $income = fn () => Payment::income()->forOrdersOf($scopeUser);

        $orders = fn () => Order::query()->when($uid, fn ($q) => $q->where('user_id', $uid));

        $prog = fn () => TitleProgress::query()
            ->when($uid, fn ($q) => $q->whereHas('orderDetail.order', fn ($o) => $o->where('user_id', $uid)));

        // Nilai periode berjalan (dipakai kartu + sebagai 'current' delta).
        $incHari   = (int) $income()->whereDate('paid_at', $today)->sum('amount');
        $incMinggu = (int) $income()->whereBetween('paid_at', [$today->copy()->startOfWeek(), $today->copy()->endOfWeek()])->sum('amount');
        $incTahun  = (int) $income()->whereYear('paid_at', $today->year)->sum('amount');
        $jmlOrder    = $orders()->whereYear('ordered_at', $today->year)->count();
        $jmlOrderBln = $orders()->whereYear('ordered_at', $today->year)->whereMonth('ordered_at', $today->month)->count();

        // Pembanding setara (period-to-date pada periode sebelumnya).
        $incHariPrev    = (int) $income()->whereDate('paid_at', $today->copy()->subDay())->sum('amount');
        $incMingguPrev  = (int) $income()->whereBetween('paid_at', [$today->copy()->startOfWeek()->subWeek(), $today->copy()->endOfDay()->subWeek()])->sum('amount');
        $incTahunPrev   = (int) $income()->whereBetween('paid_at', [$today->copy()->startOfYear()->subYear(), $today->copy()->endOfDay()->subYear()])->sum('amount');
        $jmlOrderPrev    = $orders()->whereBetween('ordered_at', [$today->copy()->startOfYear()->subYear(), $today->copy()->endOfDay()->subYear()])->count();
        $jmlOrderBlnPrev = $orders()->whereBetween('ordered_at', [$today->copy()->startOfMonth()->subMonthNoOverflow(), $today->copy()->endOfDay()->subMonthNoOverflow()])->count();

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
            'total_piutang'   => (int) ((new FinancialReportService())->piutang($scopeUser)['kpi']['sisa']),
            'rata_rata_order' => $this->avgOrderValue($uid, $today->year),
            'target'          => $scopeUser
                                    ? app(\App\Services\MarketingTargetService::class)->currentForMarketing($scopeUser)
                                    : null,

            'income_trend'           => $this->dailySum($income(), 'paid_at', 'amount'),
            'order_trend'            => $this->dailyCount($orders(), 'ordered_at'),

            // Progres naskah (sesuai scope: satu marketing, atau seluruh perusahaan bila null)
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
            'deadline_rows'     => $this->deadlineRows($scopeUser),
        ];
    }

    /** Indikator naik/turun: pct (null bila pembanding 0) + arah + penanda lonjakan ekstrem. */
    private function delta(int $current, int $previous): array
    {
        if ($previous === 0) {
            return ['pct' => null, 'dir' => $current > 0 ? 'up' : 'flat', 'capped' => false];
        }
        $pct = round(($current - $previous) / $previous * 100, 1);

        return [
            'pct'    => abs($pct),
            'dir'    => $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat'),
            'capped' => abs($pct) > 999,
        ];
    }

    /** Rata-rata nilai order (cost_amount) tahun berjalan; 0 bila tanpa order. $uid null = seluruh perusahaan. */
    private function avgOrderValue(?int $uid, int $year): int
    {
        $avg = Order::query()
            ->when($uid, fn ($q) => $q->where('tb_orders.user_id', $uid))
            ->whereYear('tb_orders.ordered_at', $year)
            // Asumsi 1 OrderDetail per Order (invarian alur pembuatan order). Bila kelak
            // satu order bisa banyak detail, AVG lewat leftJoin ini akan menghitung ganda.
            ->leftJoin('tb_order_details', 'tb_order_details.order_id', '=', 'tb_orders.id')
            ->avg(DB::raw('COALESCE(tb_order_details.cost_amount, 0)'));

        // (int) cast truncates toward zero — matches the old intdiv() semantics exactly.
        // cost_amount is always >= 0, so truncation here equals floor.
        return (int) ($avg ?? 0);
    }

    /** Baris tabel naskah aktif mendekati/lewat deadline: satu marketing, atau seluruh perusahaan bila null. */
    public function deadlineRows(?User $scopeUser): \Illuminate\Support\Collection
    {
        return $this->deadlineFrom(
            TitleProgress::query()->when($scopeUser,
                fn ($q) => $q->whereHas('orderDetail.order', fn ($o) => $o->where('user_id', $scopeUser->id)))
        );
    }

    /** Baris deadline milik satu editor (scope assigned_user_id, bukan kepemilikan order). */
    public function deadlineRowsForEditor(User $editor): \Illuminate\Support\Collection
    {
        return $this->deadlineFrom(
            TitleProgress::query()->where('assigned_user_id', $editor->id)
        );
    }

    private function deadlineFrom($query): \Illuminate\Support\Collection
    {
        $today = Carbon::today();

        return $query
            ->whereNotIn('status', TitleProgress::FINAL_STAGES)
            ->whereNotNull('target_date')
            ->with('orderDetail.order')
            ->orderBy('target_date')
            ->limit(200)
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

    /** Σ kolom per hari 90 hari → {labels, series}. Agregasi di SQL. */
    private function dailySum($query, string $dateCol, string $sumCol): array
    {
        $days = collect(range(89, 0))->map(fn ($i) => Carbon::now()->subDays($i)->format('Y-m-d'));
        $byDate = $query->where($dateCol, '>=', Carbon::now()->subDays(89)->startOfDay())
            ->selectRaw("DATE($dateCol) as d, SUM($sumCol) as total")
            ->groupBy('d')
            ->pluck('total', 'd');

        return [
            'labels' => $days->map(fn ($d) => Carbon::parse($d)->format('M d'))->all(),
            'series' => $days->map(fn ($d) => (int) ($byDate[$d] ?? 0))->all(),
        ];
    }

    /** Count per hari 90 hari → {labels, series}. Agregasi di SQL. */
    private function dailyCount($query, string $dateCol): array
    {
        $days = collect(range(89, 0))->map(fn ($i) => Carbon::now()->subDays($i)->format('Y-m-d'));
        $byDate = $query->where($dateCol, '>=', Carbon::now()->subDays(89)->startOfDay())
            ->selectRaw("DATE($dateCol) as d, COUNT(*) as total")
            ->groupBy('d')
            ->pluck('total', 'd');

        return [
            'labels' => $days->map(fn ($d) => Carbon::parse($d)->format('M d'))->all(),
            'series' => $days->map(fn ($d) => (int) ($byDate[$d] ?? 0))->all(),
        ];
    }

    /** Penyelesaian naskah per hari 90 hari. $uid null = seluruh perusahaan. Agregasi di SQL. */
    private function completionTrend(?int $uid): array
    {
        $days = collect(range(89, 0))->map(fn ($i) => Carbon::now()->subDays($i)->format('Y-m-d'));
        // to_value is title-cased by TitleProgressService::log() (e.g. 'Terbit'/'Publish')
        $byDate = TitleProgressLog::whereIn('to_value', ['Terbit', 'Publish'])
            ->when($uid, fn ($q) => $q->whereHas('titleProgress.orderDetail.order', fn ($o) => $o->where('user_id', $uid)))
            ->where('created_at', '>=', Carbon::now()->subDays(89)->startOfDay())
            ->selectRaw('DATE(created_at) as d, COUNT(*) as total')
            ->groupBy('d')
            ->pluck('total', 'd');

        return [
            'labels' => $days->map(fn ($d) => Carbon::parse($d)->format('M d'))->all(),
            'series' => $days->map(fn ($d) => (int) ($byDate[$d] ?? 0))->all(),
        ];
    }
}
