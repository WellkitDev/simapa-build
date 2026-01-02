<?php

namespace App\Http\Controllers\Pages;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class IncomeController extends Controller
{
    //
    public function indexOrderIncome()
    {
        // Table 1: Per Tahun
        // Base Query: Payment yang diapprove DAN Order-nya berstatus 'lunas'
        $baseQuery = Payment::whereHas('approval', function($q) {
                $q->where('status', 'approved');
            })
            ->whereHas('order', function($q) {
                $q->where('status', 'lunas')->with('details')->when(Auth::user()->hasRole('marketing'), function ($q) {
                    return $q->where('user_id', Auth::id());
                });
            });

        // Rekap Tahunan
        $yearlyIncome = (clone $baseQuery)
            ->select(
                DB::raw('YEAR(created_at) as year'),
                DB::raw('SUM(amount) as total_income'),
                DB::raw('COUNT(DISTINCT order_id) as total_order')
            )
            ->groupBy('year')
            ->orderBy('year', 'desc')
            ->get();

        // Rekap Bulanan
        $monthlyIncome = (clone $baseQuery)
            ->select(
                DB::raw('MONTH(created_at) as month'),
                DB::raw('YEAR(created_at) as year'),
                DB::raw('SUM(amount) as total_income'),
                DB::raw('COUNT(DISTINCT order_id) as total_order')
            )
            ->groupBy('year', 'month')
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->get();

        return view('income.index-order', compact('yearlyIncome', 'monthlyIncome'));
    }

    public function indexPaymentIncome()
    {
        // Table 1: Total Income per Tahun
        // Query Dasar untuk Income yang sudah di-Approve
        $baseQuery = Payment::whereHas('approval', function($query) {
            $query->where('status', 'approved'); // Sesuaikan 'approved' dengan string di DB Anda
        })->whereHas('order', function($q) {
                $q->where('status', 'lunas')
                ->with('details')
                ->when(Auth::user()->hasRole('marketing'), function ($q) {
                    return $q->where('user_id', Auth::id());
                });
            });

        // Table 1: Total Income per Tahun
        $yearlyIncome = (clone $baseQuery)
            ->select(
                DB::raw('YEAR(created_at) as year'),
                DB::raw('SUM(amount) as total_income'),
                DB::raw('COUNT(DISTINCT order_id) as total_order')
            )
            ->groupBy('year')
            ->orderBy('year', 'desc')
            ->get();

        // Table 2: Total Income per Bulan & Tahun
        $monthlyIncome = (clone $baseQuery)
            ->select(
                DB::raw('MONTH(created_at) as month'),
                DB::raw('YEAR(created_at) as year'),
                DB::raw('SUM(amount) as total_income'),
                DB::raw('COUNT(DISTINCT order_id) as total_order')
            )
            ->groupBy('year', 'month')
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->get();

        return view('income.index-payment', compact('yearlyIncome', 'monthlyIncome'));
    }

    public function incomeReport()
    {
        // 1. Menghitung Total Harga Order (dari tb_order_details)
        // Hanya untuk order yang statusnya BELUM lunas
        $totalOrderAmount = Order::where('status', '!=', 'lunas')
            ->whereHas('details')
            ->get()
            ->sum(function($order) {
                return $order->details->sum('cost_amount');
            });

        // 2. Menghitung Total yang SUDAH dibayar (dari tb_payments)
        // Hanya payment yang APPROVED dan milik order yang BELUM lunas
        $totalPaidAmount = Payment::whereHas('approval', function($q) {
                $q->where('status', 'approved');
            })
            ->whereHas('order', function($q) {
                $q->where('status', '!=', 'lunas');
            })
            ->sum('amount');

        // 3. Kalkulasi Total Hutang
        $totalDebt = $totalOrderAmount - $totalPaidAmount;

        // Tambahan: Ambil data order pending untuk tabel detail
        $pendingOrders = Order::where('status', '!=', 'lunas')
            ->with(['details'])
                ->when(Auth::user()->hasRole('marketing'), function ($q) {
                    return $q->where('user_id', Auth::id());
                })
            ->withSum(['payments as total_paid' => function($q) {
                $q->whereHas('approval', fn($a) => $a->where('status', 'approved'));
            }], 'amount')
            ->get();

        return view('income.index-report', compact('totalDebt', 'pendingOrders', 'totalOrderAmount', 'totalPaidAmount'));
    }

    public function incomeLunas()
    {
        // Mengambil order dengan status 'lunas'
        // Eager load details dan payments untuk optimasi
        $completedOrders = Order::where('status', 'lunas')
            ->with(['details'])
                ->when(Auth::user()->hasRole('marketing'), function ($q) {
                    return $q->where('user_id', Auth::id());
                })
            ->withSum(['payments as total_paid' => function($q) {
                $q->whereHas('approval', fn($a) => $a->where('status', 'approved'));
            }], 'amount')
            ->orderBy('updated_at', 'desc')
            ->get();

        return view('income.index-lunas', compact('completedOrders'));
    }
}
