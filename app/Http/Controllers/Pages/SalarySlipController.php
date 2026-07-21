<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Jobs\SendSalarySlipJob;
use App\Models\SalarySlip;
use App\Models\User;
use App\Services\Notifier;
use App\Support\SalarySlipPdfData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SalarySlipController extends Controller
{
    public function index(Request $request)
    {
        $now   = now();
        $year  = (int) $request->query('year', $now->year);
        $mq    = $request->query('month', (string) $now->month);
        $month = ($mq === 'all') ? null : (int) ($mq ?: $now->month);
        $eq    = $request->query('employee');
        $employeeId = ($eq === null || $eq === '' || $eq === 'all') ? null : (int) $eq;
        $status = in_array($request->query('status'), ['draft', 'terbit'], true) ? $request->query('status') : null;

        $slips = SalarySlip::with('employee')
            ->where('period_year', $year)
            ->when($month, fn ($q) => $q->where('period_month', $month))
            ->when($employeeId, fn ($q) => $q->where('user_id', $employeeId))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('period_year')->orderByDesc('period_month')->orderByDesc('id')
            ->get();

        $employees = User::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $years     = range($now->year, $now->year - 4);

        return view('salary.slips.index', compact('slips', 'employees', 'year', 'month', 'employeeId', 'status', 'years'));
    }
}
