<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Services\ProfitAnalysisService;
use Illuminate\Http\Request;

class ProfitAnalysisController extends Controller
{
    public function __construct(private ProfitAnalysisService $service) {}

    public function index(Request $request)
    {
        $year  = (int) $request->query('year', now()->year);
        $month = (int) $request->query('month', now()->month);
        if ($month < 1 || $month > 12) {
            $month = now()->month;
        }

        return view('accounting.profit-analysis', array_merge(
            $this->service->forMonth($year, $month),
            ['year' => $year, 'month' => $month, 'yearly' => $this->service->yearly($year)]
        ));
    }
}
