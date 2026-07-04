<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Services\CashRecapService;
use Illuminate\Http\Request;

class AccountingDashboardController extends Controller
{
    public function __construct(private CashRecapService $service) {}

    public function index(Request $request)
    {
        $year = (int) $request->query('year', now()->year);

        return view('accounting.dashboard', [
            'year'  => $year,
            'recap' => $this->service->monthlyRecap($year),
            'ytd'   => $this->service->ytd($year),
        ]);
    }
}
