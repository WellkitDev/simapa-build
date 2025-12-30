<?php

namespace App\Http\Controllers\Pages;

use App\Models\Order;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class FullPaymentBookController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
        // Ambil order yang total pembayarannya (status paid) >= cost_amount
        $orders = Order::with(['payments.approval', 'details', 'contact', 'invoices'])
            ->whereHas('details', function($query) {
                $query->whereRaw('(SELECT SUM(amount) FROM tb_payments WHERE tb_payments.order_id = tb_orders.id AND status = "paid") >= tb_order_details.cost_amount');
            })
            ->latest()
            ->get();

        return view('payments.lunas.index', compact('orders'));
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
