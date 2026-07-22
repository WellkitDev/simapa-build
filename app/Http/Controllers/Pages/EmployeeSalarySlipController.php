<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\SalarySlip;
use App\Support\SalarySlipPdfData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;

class EmployeeSalarySlipController extends Controller
{
    public function me()
    {
        $slips = SalarySlip::where('user_id', Auth::id())
            ->where('status', 'terbit')
            ->orderByDesc('period_year')->orderByDesc('period_month')->orderByDesc('id')
            ->get();

        return view('salary.slips.me', compact('slips'));
    }

    public function pdf(int $id)
    {
        $slip = SalarySlip::with('earnings', 'deductions', 'employee')
            ->where('user_id', Auth::id())
            ->where('status', 'terbit')
            ->findOrFail($id);

        return Pdf::loadView('salary.slips.salary_slip_pdf', SalarySlipPdfData::for($slip))
            ->stream('SlipGaji_' . $slip->slip_no . '.pdf');
    }
}
