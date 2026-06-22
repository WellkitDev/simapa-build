<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Services\MarketingTargetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MarketingTargetController extends Controller
{
    public function index(Request $request, MarketingTargetService $service)
    {
        $year  = (int) $request->input('year', now()->year);
        $month = (int) $request->input('month', now()->month);
        $rows  = $service->monthlyOverview($year, $month);

        return view('marketing-target.index', compact('rows', 'year', 'month'));
    }

    public function me(MarketingTargetService $service)
    {
        $rows = $service->listForMarketing(Auth::user());
        return view('target.me', compact('rows'));
    }

    public function save(Request $request, MarketingTargetService $service)
    {
        $data = $request->validate([
            'year'             => 'required|integer|min:2000|max:2100',
            'month'            => 'required|integer|min:1|max:12',
            'targets'          => 'array',
            'targets.*.target' => 'nullable|numeric|min:0',
            'targets.*.rate'   => 'nullable|numeric|min:0|max:100',
        ]);

        $rows = collect($data['targets'] ?? [])
            ->map(fn ($v, $uid) => ['user_id' => (int) $uid, 'target' => $v['target'] ?? null, 'rate' => $v['rate'] ?? 0])
            ->values()->all();

        $service->upsertMany((int) $data['year'], (int) $data['month'], $rows, Auth::user());

        return redirect()->route('marketing-target.index', ['year' => $data['year'], 'month' => $data['month']])
            ->with('success', 'Target marketing tersimpan.');
    }
}
