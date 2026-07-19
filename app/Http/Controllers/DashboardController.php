<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\ProductionDashboardService;
use App\Services\PerformanceService;
use App\Services\SalesDashboardService;
use App\Services\AdminDashboardService;
use App\Services\MarketingTargetService;
use App\Services\CashRecapService;
use App\Services\ExpenseGapService;
use App\Services\ManuscriptStageStatsService;

class DashboardController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        // Peta berurutan prioritas: role pertama yang cocok menang.
        // Menggantikan rentetan if yang tumpang tindih, yang membuat admin dan
        // accounting mendarat di kartu keuangan tanpa pernah dirancang ke sana.
        return match (true) {
            $user->hasRole('superadmin') => $this->company($request, true),
            $user->hasRole('manager')    => $this->company($request, false),
            $user->hasRole('accounting') => $this->accounting(),
            $user->hasRole('admin')      => $this->admin(),
            $user->hasRole('marketing')  => $this->sales($user),
            $user->hasRole('production') => $this->production($user),
            default                      => view('dashboard', ['dashboardView' => 'none']),
        };
    }

    private function sales($user)
    {
        return view('dashboard', [
            'dashboardView' => 'sales',
            'mkt' => app(SalesDashboardService::class)->forUser($user),
        ]);
    }

    private function production($user)
    {
        return view('dashboard', [
            'dashboardView' => 'production',
            'prod' => app(ProductionDashboardService::class)->forUser($user),
            'perf' => app(PerformanceService::class)->forEditor($user),
            'stageStats' => app(ManuscriptStageStatsService::class)->forEditor($user),
        ]);
    }

    private function admin()
    {
        return view('dashboard', [
            'dashboardView' => 'admin',
            'adm'    => app(AdminDashboardService::class)->forAdmin(),
            'global' => app(ProductionDashboardService::class)->global(),
            'stageStats' => app(ManuscriptStageStatsService::class)->global(),
        ]);
    }

    private function accounting()
    {
        $year = now()->year;

        return view('dashboard', [
            'dashboardView' => 'accounting',
            'year'  => $year,
            'recap' => app(CashRecapService::class)->monthlyRecap($year),
            'ytd'   => app(CashRecapService::class)->ytd($year),
            'gap'   => app(ExpenseGapService::class)->check($year),
        ]);
    }

    private function company(Request $request, bool $withCash)
    {
        // Id asing / bukan marketing → null = "Semua marketing", bukan error.
        $filter = null;
        if ($request->filled('marketing')) {
            $filter = User::role('marketing')->find((int) $request->query('marketing'));
        }

        $data = [
            'dashboardView' => 'company',
            'mkt'           => app(SalesDashboardService::class)->forCompany($filter),
            'global'        => app(ProductionDashboardService::class)->global(),
            'stageStats'    => app(ManuscriptStageStatsService::class)->global(),
            'perMarketing'  => app(SalesDashboardService::class)->perMarketingComparison(),
            'editors'       => app(PerformanceService::class)->allEditors(),
            'marketers'     => User::role('marketing')->orderBy('name')->get(['id', 'name']),
            'filterId'      => $filter?->id,
            'teamTargets'   => $filter ? collect() : app(MarketingTargetService::class)->adminList('aktif'),
            'cash'          => null,
        ];

        if ($withCash) {
            $data['cash'] = $this->cashSummary();
        }

        return view('dashboard', $data);
    }

    /** Blok kas superadmin. Kegagalan akuntansi tidak boleh menjatuhkan seluruh dashboard. */
    private function cashSummary(): ?array
    {
        try {
            $year = now()->year;
            return [
                'year' => $year,
                'ytd'  => app(CashRecapService::class)->ytd($year),
                'gap'  => app(ExpenseGapService::class)->check($year),
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Blok kas dashboard gagal: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
