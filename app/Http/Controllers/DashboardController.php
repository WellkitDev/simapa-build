<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use App\Models\PaymentApproval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Services\ProductionDashboardService;
use App\Services\PerformanceService;
use App\Services\SalesDashboardService;

class DashboardController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
        // Filter dasar jika user adalah marketing
        // Filter dasar Marketing
        $isMarketing = Auth::user()->hasRole('marketing');
        $userId = Auth::id();

        $user = Auth::user();
        $isProductionOnly = $user->hasRole('production') && ! $user->hasAnyRole(['manager', 'superadmin', 'marketing']);

        if ($isProductionOnly) {
            return view('dashboard', [
                'dashboardView' => 'production',
                'prod' => app(ProductionDashboardService::class)->forUser($user),
                'perf' => app(PerformanceService::class)->forEditor($user),
            ]);
        }

        $isMarketingOnly = $user->hasRole('marketing') && ! $user->hasAnyRole(['manager', 'superadmin']);

        if ($isMarketingOnly) {
            return view('dashboard', [
                'dashboardView' => 'marketing',
                'mkt' => app(SalesDashboardService::class)->forUser($user),
            ]);
        }

        // 1. Rentang waktu 14 hari terakhir
        $days = collect(range(29, 0))->map(function($i) {
            return Carbon::now()->subDays($i)->format('Y-m-d');
        });
        $startDate = Carbon::now()->subDays(29)->startOfDay();

        // --- QUERY DATA HARIAN ---

        // A. Daily Orders
        $dailyOrders = Order::select(DB::raw('DATE(ordered_at) as date'), DB::raw('count(*) as total'))
            ->where('ordered_at', '>=', $startDate)
            ->when($isMarketing, fn($q) => $q->where('user_id', $userId))
            ->groupBy('date')->pluck('total', 'date');

        // B. Daily Income (Sum) — income() = uang masuk kanonik (paid, bukan refund).
        $dailyIncome = Payment::select(DB::raw('DATE(paid_at) as date'), DB::raw('sum(amount) as total'))
            ->income()->where('paid_at', '>=', $startDate)
            ->whereHas('order', fn($q) => $isMarketing ? $q->where('user_id', $userId) : $q)
            ->groupBy('date')->pluck('total', 'date');

        // C. Daily Payments (Count) — pembayaran diterima, refund bukan pembayaran.
        $dailyPayments = Payment::select(DB::raw('DATE(paid_at) as date'), DB::raw('count(*) as total'))
            ->income()->where('paid_at', '>=', $startDate)
            ->whereHas('order', fn($q) => $isMarketing ? $q->where('user_id', $userId) : $q)
            ->groupBy('date')->pluck('total', 'date');

        // D. Daily Approvals (Approved, Pending, Rejected)
        // Kita ambil data dari table PaymentApproval
        $dailyStatus = PaymentApproval::select(
                DB::raw('DATE(approved_at) as date'),
                'status',
                DB::raw('count(*) as total')
            )
            ->where('approved_at', '>=', $startDate)
            ->whereHas('payment.order', fn($q) => $isMarketing ? $q->where('user_id', $userId) : $q)
            ->groupBy('date', 'status')
            ->get();

        // --- MAPPING DATA KE SERIES ---

        $seriesOrder   = $days->map(fn($date) => (int) $dailyOrders->get($date, 0));
        $seriesIncome  = $days->map(fn($date) => (int) $dailyIncome->get($date, 0));
        $seriesPayment = $days->map(fn($date) => (int) $dailyPayments->get($date, 0));

        // Mapping status approval dari hasil collection get()
        $seriesApprove = $days->map(fn($date) => (int) $dailyStatus->where('date', $date)->where('status', 'approved')->first()?->total ?? 0);
        $seriesPending = $days->map(fn($date) => (int) $dailyStatus->where('date', $date)->where('status', 'pending')->first()?->total ?? 0);
        $seriesReject  = $days->map(fn($date) => (int) $dailyStatus->where('date', $date)->where('status', 'rejected')->first()?->total ?? 0);

        $chartLabels   = $days->map(fn($date) => Carbon::parse($date)->format('M d'));

        // Masukkan ke array data utama
        $data = [
            'total_orders'  => Order::when($isMarketing, fn($q) => $q->where('user_id', $userId))->count(),
            'total_income'  => Payment::income()
                                ->whereHas('order', fn($q) => $isMarketing ? $q->where('user_id', $userId) : $q)
                                ->sum('amount'),
            'total_payment' => Payment::income()
                                ->whereHas('order', fn($q) => $isMarketing ? $q->where('user_id', $userId) : $q)
                                ->count(),
            'total_approve' => PaymentApproval::where('status', 'approved')
                                ->whereHas('payment.order', fn($q) => $isMarketing ? $q->where('user_id', $userId) : $q)
                                ->count(),
            'total_pending' => PaymentApproval::where('status', 'pending')
                                ->whereHas('payment.order', fn($q) => $isMarketing ? $q->where('user_id', $userId) : $q)
                                ->count(),
            'total_reject'  => PaymentApproval::where('status', 'rejected')
                                ->whereHas('payment.order', fn($q) => $isMarketing ? $q->where('user_id', $userId) : $q)
                                ->count(),
            'chart_labels'   => $chartLabels->values()->all(),
            'series_order'   => $seriesOrder->values()->all(),
            'series_income'  => $seriesIncome->values()->all(),
            'series_payment' => $seriesPayment->values()->all(),
            'series_approve' => $seriesApprove->values()->all(),
            'series_pending' => $seriesPending->values()->all(),
            'series_reject'  => $seriesReject->values()->all(),
        ];

        $dashboardView = 'financial';
        $global  = null;
        $editors = collect();
        if ($user->hasAnyRole(['manager', 'superadmin'])) {
            $global  = app(ProductionDashboardService::class)->global();
            $editors = app(PerformanceService::class)->allEditors();
        }

        return view('dashboard', compact('data', 'dashboardView', 'global', 'editors'));
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
