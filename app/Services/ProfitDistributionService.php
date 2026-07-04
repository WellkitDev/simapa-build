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
            $amount = $r->type === 'percent' ? round((float) $r->value / 100 * $profit) : (float) $r->value;
            return [
                'name'       => $r->name,
                'type'       => $r->type,
                'value'      => (float) $r->value,
                'per_member' => (bool) $r->per_member,
                'amount'     => $amount,
                'perPerson'  => $r->per_member ? $amount / $members : null,
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
