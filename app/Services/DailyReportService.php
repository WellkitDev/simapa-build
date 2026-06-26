<?php

namespace App\Services;

use App\Models\DailyReport;
use App\Models\DailyReportFile;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DailyReportService
{
    /** Rekap tugas user untuk satu tanggal (live dari tb_tasks). */
    public function recapFor(User $user, Carbon $date): array
    {
        $range = [$date->copy()->startOfDay(), $date->copy()->endOfDay()];
        $selesai = Task::forUser($user->id)->whereBetween('completed_at', $range)->orderByDesc('completed_at')->get();
        $dibuat  = Task::forUser($user->id)->whereBetween('created_at', $range)->orderByDesc('created_at')->get();
        $dikerjakan = $date->isToday()
            ? Task::forUser($user->id)->where('status', 'in_progress')->get()
            : collect();

        return [
            'date'       => $date->toDateString(),
            'selesai'    => $selesai,
            'dibuat'     => $dibuat,
            'dikerjakan' => $dikerjakan,
            'counts'     => [
                'selesai'    => $selesai->count(),
                'dibuat'     => $dibuat->count(),
                'dikerjakan' => $dikerjakan->count(),
            ],
        ];
    }

    /** Agregasi bulanan (read-only). */
    public function monthlyRecap(User $user, int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end   = $start->copy()->endOfMonth();

        $completed = Task::forUser($user->id)
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$start, $end->copy()->endOfDay()])
            ->get();

        $withDue = $completed->filter(fn (Task $t) => $t->due_date !== null);
        $onTime  = $withDue->filter(fn (Task $t) => $t->completed_at->toDateString() <= $t->due_date->toDateString());

        $perHari = $completed->groupBy(fn (Task $t) => $t->completed_at->toDateString())->map->count();

        $reports = DailyReport::where('user_id', $user->id)
            ->whereBetween('report_date', [$start->toDateString(), $end->toDateString()])
            ->get()->keyBy(fn (DailyReport $r) => $r->report_date->toDateString());

        return [
            'year'         => $year,
            'month'        => $month,
            'selesai'      => $completed->count(),
            'tepat_waktu'  => $onTime->count(),
            'telat'        => $withDue->count() - $onTime->count(),
            'on_time_rate' => $withDue->count() > 0 ? round($onTime->count() / $withDue->count() * 100, 1) : null,
            'per_hari'     => $perHari,
            'reports'      => $reports,
            'dilaporkan'   => $reports->where('status', 'submitted')->count(),
        ];
    }

    /** Untuk Pemantauan: per user → sudah kirim? + jml selesai pada tanggal itu. */
    public function submissionsForDate(Carbon $date): Collection
    {
        $reports = DailyReport::whereDate('report_date', $date)->get(['id', 'user_id', 'status']);
        $submitted = $reports->where('status', 'submitted')->pluck('user_id')->flip();

        $buktiCounts = DailyReportFile::whereIn('daily_report_id', $reports->pluck('id'))
            ->selectRaw('daily_report_id, count(*) as c')->groupBy('daily_report_id')->pluck('c', 'daily_report_id');
        $buktiPerUser = $reports->mapWithKeys(fn ($r) => [$r->user_id => (int) ($buktiCounts[$r->id] ?? 0)]);

        $doneCounts = Task::whereBetween('completed_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->selectRaw('user_id, count(*) as c')->groupBy('user_id')->pluck('c', 'user_id');

        return User::orderBy('name')->get(['id', 'name'])->map(fn (User $u) => [
            'id'        => $u->id,
            'name'      => $u->name,
            'submitted' => $submitted->has($u->id),
            'selesai'   => (int) ($doneCounts[$u->id] ?? 0),
            'bukti'     => (int) ($buktiPerUser[$u->id] ?? 0),
        ])->values();
    }

    /** Ambil/buat baris report untuk (user, tanggal) — agar catatan/lampiran punya induk. */
    public function getOrCreateReport(User $user, Carbon $date): DailyReport
    {
        return DailyReport::firstOrCreate(['user_id' => $user->id, 'report_date' => $date->toDateString()]);
    }
}
