<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\CashFixedExpense;
use App\Models\CashSetting;
use App\Services\BudgetTargetService;
use App\Services\CashJournalService;
use App\Services\CashRecapService;
use Illuminate\Http\Request;

class AccountingOverviewController extends Controller
{
    public function __construct(
        private CashJournalService $journal,
        private CashRecapService $recap,
        private BudgetTargetService $budget,
    ) {}

    public function index(Request $request)
    {
        $year = (int) $request->query('year', now()->year);

        $balances = $this->journal->accountBalances();
        $ytd = $this->recap->ytd($year);
        $achievement = $this->budget->monthlyAchievement($year);
        $ytdRealisasi = (float) array_sum(array_column($achievement, 'realisasi'));
        $target = (float) CashSetting::singleton()->target_operasional;
        $ytdTarget = $target * 12;
        $pct = $ytdTarget > 0 ? (int) round($ytdRealisasi / $ytdTarget * 100) : 0;
        $fixedMonthly = CashFixedExpense::where('active', true)->get()->sum(fn ($e) => $e->monthlyAmount());

        return view('accounting.overview', compact('year', 'balances', 'ytd', 'ytdRealisasi', 'ytdTarget', 'pct', 'fixedMonthly'));
    }
}
