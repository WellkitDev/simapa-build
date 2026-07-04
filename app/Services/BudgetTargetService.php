<?php

namespace App\Services;

use App\Models\CashSetting;

class BudgetTargetService
{
    public function __construct(private CashRecapService $recap) {}

    /** @return array<int,array> 12 bulan: month,label,realisasi,target,pct,achieved */
    public function monthlyAchievement(int $year): array
    {
        $target = (float) CashSetting::singleton()->target_operasional;
        $recap  = $this->recap->monthlyRecap($year);

        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $realisasi = (float) $recap[$m - 1]['totalIn'];
            $pct = $target > 0 ? (int) round($realisasi / $target * 100) : 0;
            $out[] = [
                'month'     => $m,
                'label'     => $recap[$m - 1]['label'],
                'realisasi' => $realisasi,
                'target'    => $target,
                'pct'       => $pct,
                'achieved'  => $target > 0 && $realisasi >= $target,
            ];
        }
        return $out;
    }
}
