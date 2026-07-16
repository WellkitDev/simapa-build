<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Services\CashPeriodService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CashPeriodController extends Controller
{
    public function __construct(private CashPeriodService $service) {}

    public function lock(Request $request)
    {
        $data = $this->validatePeriod($request);
        $this->service->lock($data['year'], $data['month'], Auth::user());

        return back()->with('success', "Periode {$data['month']}/{$data['year']} dikunci.");
    }

    public function unlock(Request $request)
    {
        $data = $this->validatePeriod($request);
        $this->service->unlock($data['year'], $data['month'], Auth::user());

        return back()->with('success', "Periode {$data['month']}/{$data['year']} dibuka.");
    }

    private function validatePeriod(Request $request): array
    {
        return $request->validate([
            'year'  => 'required|integer|min:2000|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);
    }
}
