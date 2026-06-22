<?php

namespace App\Services;

use App\Models\MarketingTarget;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MarketingTargetService
{
    /** Progres + status untuk satu target. */
    public function progressFor(MarketingTarget $target): array
    {
        $target->loadMissing('user');
        $today = Carbon::today();
        $start = $target->start_date;
        $end   = $target->end_date;

        $realisasi = (int) Payment::approved()->forOrdersOf($target->user)
            ->whereBetween('paid_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->sum('amount');

        $t    = (int) $target->target_amount;
        $rate = (float) $target->commission_rate;

        $status = $today->lt($start) ? 'akan_datang' : ($today->gt($end) ? 'berakhir' : 'aktif');
        $tertunggak = $today->gt($end->copy()->addDays(7)) && ! $target->commission_paid;

        return [
            'id'                 => $target->id,
            'user_id'            => $target->user_id,
            'scope'              => $target->batch_id ? 'all' : 'individual',
            'batch_id'           => $target->batch_id,
            'target'             => $t,
            'rate'               => $rate,
            'realisasi'          => $realisasi,
            'capaian_persen'     => $t > 0 ? round($realisasi / $t * 100, 1) : 0.0,
            'komisi'             => (int) round($rate / 100 * $realisasi),
            'sisa'               => max($t - $realisasi, 0),
            'start_date'         => $start->format('Y-m-d'),
            'end_date'           => $end->format('Y-m-d'),
            'status'             => $status,
            'commission_paid'    => (bool) $target->commission_paid,
            'commission_paid_at' => optional($target->commission_paid_at)->format('Y-m-d'),
            'tertunggak'         => $tertunggak,
        ];
    }

    /** Target aktif (hari ini dalam rentang) untuk kartu dashboard; null bila tak ada. */
    public function currentForMarketing(User $marketing): ?array
    {
        $today = Carbon::today();
        $target = MarketingTarget::where('user_id', $marketing->id)
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->orderBy('end_date')
            ->first();

        return $target ? $this->progressFor($target) : null;
    }

    /** Semua target milik satu marketing (untuk halaman Target Saya). */
    public function listForMarketing(User $marketing): Collection
    {
        return MarketingTarget::where('user_id', $marketing->id)
            ->orderByDesc('start_date')->get()
            ->map(fn (MarketingTarget $t) => $this->progressFor($t))
            ->values();
    }

    /** Semua target + nama marketing untuk halaman admin (filter status opsional). */
    public function adminList(?string $status = null): Collection
    {
        $rows = MarketingTarget::with('user')->orderByDesc('start_date')->get()
            ->map(function (MarketingTarget $t) {
                $p = $this->progressFor($t);
                $p['name'] = $t->user?->name ?? '—';
                return $p;
            });

        if ($status) {
            $rows = $rows->where('status', $status);
        }

        return $rows->values();
    }

    /** Buat target: individual (untuk $userIds) atau all (1 baris per marketing aktif, batch_id sama). */
    public function createTarget(string $scope, array $userIds, int $amount, float $rate, string $start, string $end, User $actor): void
    {
        $marketingIds = User::role('marketing')->pluck('id')->all();
        $batchId = null;

        if ($scope === 'all') {
            $userIds = $marketingIds;
            $batchId = (string) Str::uuid();
        } else {
            $userIds = array_values(array_intersect(array_map('intval', $userIds), $marketingIds));
        }

        if (empty($userIds)) {
            return;
        }

        $created = [];
        DB::transaction(function () use ($userIds, $amount, $rate, $start, $end, $actor, $batchId, &$created) {
            foreach ($userIds as $uid) {
                $created[] = MarketingTarget::create([
                    'user_id'         => $uid,
                    'start_date'      => $start,
                    'end_date'        => $end,
                    'target_amount'   => $amount,
                    'commission_rate' => $rate,
                    'batch_id'        => $batchId,
                    'created_by'      => $actor->id,
                    'updated_by'      => $actor->id,
                ]);
            }
        });

        foreach ($created as $target) {
            app(Notifier::class)->targetAssigned($target, $actor);
        }
    }

    /** Tandai komisi target sudah dibayar. */
    public function markCommissionPaid(MarketingTarget $target, User $actor): void
    {
        $target->update([
            'commission_paid'    => true,
            'commission_paid_at' => now(),
            'commission_paid_by' => $actor->id,
        ]);

        app(Notifier::class)->commissionPaid($target, $actor);
    }
}
