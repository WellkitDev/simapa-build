<?php

namespace App\Services;

use App\Models\MarketingTarget;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Collection;

class MarketingTargetService
{
    /** Progres target satu marketing untuk satu bulan. */
    public function progressFor(User $marketing, int $year, int $month): array
    {
        $target = MarketingTarget::where('user_id', $marketing->id)
            ->where('year', $year)->where('month', $month)->first();

        // Definisi kanonik (sama dengan dashboard & laporan): pembayaran paid, scoped order user.
        $realisasi = (int) Payment::approved()->forOrdersOf($marketing)
            ->whereYear('paid_at', $year)->whereMonth('paid_at', $month)->sum('amount');

        return $this->buildProgress(
            $target ? (int) $target->target_amount : 0,
            $target ? (float) $target->commission_rate : 0.0,
            $realisasi,
            (bool) $target,
        );
    }

    /** Satu baris per marketing untuk halaman admin / laporan. */
    public function monthlyOverview(int $year, int $month): Collection
    {
        $marketers = User::role('marketing')->orderBy('name')->get();

        $targets = MarketingTarget::where('year', $year)->where('month', $month)
            ->get()->keyBy('user_id');

        // Realisasi seluruh marketing dalam SATU query grouped (hindari N+1).
        // Kolom di-qualify (tb_payments.status / paid_at) karena join ke tb_orders
        // yang juga punya kolom 'status' — kalau pakai scope approved() yang unqualified akan ambigu.
        $realisasiByUser = Payment::query()
            ->where('tb_payments.status', 'paid')
            ->whereYear('tb_payments.paid_at', $year)
            ->whereMonth('tb_payments.paid_at', $month)
            ->join('tb_orders', 'tb_payments.order_id', '=', 'tb_orders.id')
            ->selectRaw('tb_orders.user_id as uid, SUM(tb_payments.amount) as total')
            ->groupBy('tb_orders.user_id')
            ->pluck('total', 'uid');

        return $marketers->map(function (User $u) use ($targets, $realisasiByUser) {
            $t = $targets->get($u->id);
            $realisasi = (int) ($realisasiByUser[$u->id] ?? 0);
            $progress = $this->buildProgress(
                $t ? (int) $t->target_amount : 0,
                $t ? (float) $t->commission_rate : 0.0,
                $realisasi,
                (bool) $t,
            );
            return array_merge(['user_id' => $u->id, 'name' => $u->name], $progress);
        })->values();
    }

    /** Simpan massal target bulan terpilih. Baris target kosong dilewati; user non-marketing diabaikan. */
    public function upsertMany(int $year, int $month, array $rows, User $actor): void
    {
        $marketingIds = User::role('marketing')->pluck('id')->all();

        foreach ($rows as $row) {
            $uid = (int) ($row['user_id'] ?? 0);
            $target = $row['target'] ?? null;

            if ($target === null || $target === '' || ! in_array($uid, $marketingIds, true)) {
                continue;
            }

            $existingCreatedBy = MarketingTarget::where(['user_id' => $uid, 'year' => $year, 'month' => $month])->value('created_by');

            MarketingTarget::updateOrCreate(
                ['user_id' => $uid, 'year' => $year, 'month' => $month],
                [
                    'target_amount'   => (int) $target,
                    'commission_rate' => (float) ($row['rate'] ?? 0),
                    'created_by'      => $existingCreatedBy ?? $actor->id,
                    'updated_by'      => $actor->id,
                ]
            );
        }
    }

    private function buildProgress(int $target, float $rate, int $realisasi, bool $hasTarget): array
    {
        return [
            'has_target'     => $hasTarget,
            'target'         => $target,
            'rate'           => $rate,
            'realisasi'      => $realisasi,
            'capaian_persen' => $target > 0 ? round($realisasi / $target * 100, 1) : 0.0,
            'komisi'         => (int) round($rate / 100 * $realisasi),
            'sisa'           => max($target - $realisasi, 0),
        ];
    }
}
