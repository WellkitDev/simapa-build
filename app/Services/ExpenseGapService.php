<?php

namespace App\Services;

use App\Models\CashEntry;
use App\Models\CashFixedExpense;

class ExpenseGapService
{
    /**
     * Periksa apakah periode ini tak punya pengeluaran tercatat sama sekali.
     * $month null → satu tahun penuh.
     *
     * Transfer internal dikecualikan (konsisten dgn CashRecapService):
     * memindahkan uang antar akun sendiri bukan pengeluaran.
     *
     * @return array{recorded:float, fixedMonthly:float, hasGap:bool}
     */
    public function check(int $year, ?int $month = null): array
    {
        $q = CashEntry::whereYear('tanggal', $year)
            ->where('jenis', 'pengeluaran')
            ->where('is_transfer', false);

        if ($month !== null) {
            $q->whereMonth('tanggal', $month);
        }

        $recorded = (float) $q->sum('amount');

        return [
            'recorded'     => $recorded,
            'fixedMonthly' => (float) CashFixedExpense::where('active', true)->get()
                                ->sum(fn (CashFixedExpense $e) => $e->monthlyAmount()),
            'hasGap'       => $recorded == 0.0,
        ];
    }
}
