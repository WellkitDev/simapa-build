<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\CashFixedExpense;
use App\Models\CashSetting;
use App\Services\BudgetTargetService;
use Illuminate\Http\Request;

class BudgetTargetController extends Controller
{
    const SCENARIOS = ['Minimum' => 150000000, 'Aman' => 200000000, 'Ideal' => 250000000, 'Agresif' => 300000000];

    public function __construct(private BudgetTargetService $service) {}

    public function index(Request $request)
    {
        $year    = (int) $request->query('year', now()->year);
        $setting = CashSetting::singleton();
        $monthly = $this->service->monthlyAchievement($year);

        return view('accounting.target', [
            'year'         => $year,
            'setting'      => $setting,
            'monthly'      => $monthly,
            'ytdRealisasi' => (float) array_sum(array_column($monthly, 'realisasi')),
            'ytdTarget'    => (float) $setting->target_operasional * 12,
            'fixedMonthly' => (float) CashFixedExpense::where('active', true)->get()->sum(fn ($e) => $e->monthlyAmount()),
            'scenarios'    => self::SCENARIOS,
        ]);
    }

    public function updateTarget(Request $request)
    {
        $data = $request->validate(['target_operasional' => 'required|numeric|min:0', 'target_order' => 'required|numeric|min:0']);
        CashSetting::singleton()->update($data);

        return back()->with('success', 'Target diperbarui.');
    }
}
