<?php

namespace App\Http\Controllers\Pages;

use App\Services\FinancialReportService;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class IncomeController extends Controller
{
    public function __construct(private FinancialReportService $svc) {}

    private function scope()
    {
        return $this->svc->resolveScope(Auth::user());
    }

    public function pemasukan()
    {
        return view('income.pemasukan', $this->svc->pemasukan($this->scope()));
    }

    public function piutang()
    {
        return view('income.piutang', $this->svc->piutang($this->scope()));
    }

    public function lunas()
    {
        return view('income.lunas', $this->svc->orderSelesai($this->scope()));
    }
}
