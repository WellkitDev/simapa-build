<?php

namespace App\Http\Controllers\Pages;

use App\Models\Order;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class DebtBookController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
        $orders = Order::with(['payments', 'details', 'contact'])
            ->whereHas('payments', function($q) {
                $q->where('payment_type', 'dp');
            })
            ->whereHas('details', function($q) {
                $q->when(Auth::user()->hasRole('marketing'), function ($q) {
                    return $q->where('user_id', Auth::id());
                });
            })
            ->where('status', '!=', 'lunas') // Anggap 'success' adalah lunas & selesai
            ->latest()
            ->get();

        return view('payments.dp.index', compact('orders'));
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
