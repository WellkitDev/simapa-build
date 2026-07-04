<?php

namespace App\Services;

use App\Models\CashDistribution;
use App\Models\CashSetting;

class ProfitDistributionService
{
    /** @return array profit,members,lines(Collection),totalAllocated,remainder */
    public function distribute(float $profit, ?int $members = null): array
    {
        $members = $members ?? (int) CashSetting::singleton()->team_members;
        if ($members < 1) {
            $members = 1;
        }

        $lines = CashDistribution::active()->orderBy('position')->get()->map(function ($r) use ($profit, $members) {
            $value = (float) $r->value;
            if ($r->type === 'percent') {
                // Persen dari profit = pool; per anggota → dibagi jumlah anggota.
                $amount    = round($value / 100 * $profit);
                $perPerson = $r->per_member ? $amount / $members : null;
            } elseif ($r->per_member) {
                // Flat per anggota (mis. gaji pokok) = nominal per orang; total = nominal × anggota.
                $perPerson = $value;
                $amount    = $value * $members;
            } else {
                // Flat total.
                $amount    = $value;
                $perPerson = null;
            }

            return [
                'name'       => $r->name,
                'type'       => $r->type,
                'value'      => $value,
                'per_member' => (bool) $r->per_member,
                'amount'     => $amount,
                'perPerson'  => $perPerson,
            ];
        })->values();

        $totalAllocated = (float) $lines->sum('amount');

        return [
            'profit'         => $profit,
            'members'        => $members,
            'lines'          => $lines,
            'totalAllocated' => $totalAllocated,
            'remainder'      => $profit - $totalAllocated,
        ];
    }
}
