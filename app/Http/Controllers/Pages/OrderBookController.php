<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\Scope;
use Illuminate\Http\Request;

class OrderBookController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
        return view('pages.order.book.index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
        return view('pages.order.book.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
        $validate = $request->validate([
            'type'               => 'required|in:bk_mandiri,bk_kolab',
            'title'              => 'required|string|max:255',
            'scope_id'           => 'nullable',
            'chapters'           => 'nullable|integer|min:1',

            'naskah_type'        => 'required|in:dibuatkan,mandiri',
            'publication_type'   => 'required|in:regular,fastrack',

            'cost_amount'        => 'required|numeric|min:0',
            'pay_amount'         => 'required|numeric|min:0',
            'status'             => 'required|in:dp,lunas,pelunasan',
            'struk_payment'      => 'required|file|mimes:jpg,jpeg,png,pdf|max:10240',

            'contact_phone'      => 'required|string',
            'contact_email'      => 'required|email',

            'authors'            => 'required|array|min:1',
            'authors.*.name'     => 'required|string',
            'authors.*.email'    => 'nullable|email',
            'authors.*.phone'    => 'nullable|string',
            'authors.*.affiliation' => 'nullable|string',
            'authors.*.possition'   => 'required|integer|min:1',
            'note'               => 'nullable|string',
            'send_invoice_email' => 'sometimes|boolean',
        ]);

        // Handle science (create new if not exists)
        $scope_id = $request->scope_id;
        if (!is_numeric($scope_id)) {
            $scope = Scope::create([
                'scope' => $scope_id,
            ]);
            $scope_id = $scope->id;
        }
    }

    /**
     * Display the specified resource.
     */
    public function show()
    {
        //
        return view('pages.order.book.show');
    }
    public function inv()
    {
        //
        return view('pages.invoices.inv_book');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
        return view('pages.order.book.edit');
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
