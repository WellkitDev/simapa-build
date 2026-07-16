<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\CashDistribution;
use App\Models\CashSetting;
use App\Services\CashRecapService;
use App\Services\ExpenseGapService;
use App\Services\ProfitDistributionService;
use Illuminate\Http\Request;

class ProfitDistributionController extends Controller
{
    public function __construct(private ProfitDistributionService $service, private CashRecapService $recap) {}

    public function index(Request $request)
    {
        $year  = (int) $request->query('year', now()->year);
        $month = (int) $request->query('month', now()->month);
        if ($month < 1 || $month > 12) {
            $month = now()->month;
        }

        if ($request->has('profit') && $request->query('profit') !== '') {
            $profit = (float) $request->query('profit');
        } else {
            $recap = $this->recap->monthlyRecap($year);
            $profit = (float) ($recap[$month - 1]['laba'] ?? 0);
        }

        return view('accounting.distribution', [
            'year'    => $year,
            'month'   => $month,
            'profit'  => $profit,
            'gap'          => app(ExpenseGapService::class)->check($year, $month),
            'periodeLabel' => 'pada ' . \Carbon\Carbon::create()->month($month)->translatedFormat('F') . ' ' . $year,
            'result'  => $this->service->distribute($profit, null),
            'rules'   => CashDistribution::orderBy('position')->get(),
            'setting' => CashSetting::singleton(),
        ]);
    }

    public function updateSetting(Request $request)
    {
        $data = $request->validate(['team_members' => 'required|integer|min:1']);
        CashSetting::singleton()->update($data);

        return back()->with('success', 'Jumlah anggota tim diperbarui.');
    }

    public function storeRule(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'type' => 'required|in:percent,flat', 'value' => 'required|numeric|min:0']);
        $data['per_member'] = $request->boolean('per_member');
        $data['active'] = true;
        CashDistribution::create($data);

        return back()->with('success', 'Aturan distribusi ditambahkan.');
    }

    public function updateRule(Request $request, int $id)
    {
        $rule = CashDistribution::findOrFail($id);
        $data = $request->validate(['name' => 'required|string|max:100', 'type' => 'required|in:percent,flat', 'value' => 'required|numeric|min:0']);
        $data['per_member'] = $request->boolean('per_member');
        $data['active'] = $request->boolean('active');
        $rule->update($data);

        return back()->with('success', 'Aturan distribusi diperbarui.');
    }

    public function destroyRule(int $id)
    {
        CashDistribution::findOrFail($id)->delete();

        return back()->with('success', 'Aturan distribusi dihapus.');
    }
}
