<?php

namespace App\Support;

use App\Models\SalarySlip;

class SalarySlipPdfData
{
    public static function for(SalarySlip $slip): array
    {
        $slip->loadMissing('earnings', 'deductions', 'employee');

        return [
            'slip'        => $slip,
            'earnings'    => $slip->earnings,
            'deductions'  => $slip->deductions,
            'totalEarn'   => (float) $slip->total_earnings,
            'totalDed'    => (float) $slip->total_deductions,
            'netPay'      => (float) $slip->net_pay,
            'terbilang'   => Terbilang::rupiah($slip->net_pay),
            'periodLabel' => $slip->periodLabel(),
        ];
    }
}
