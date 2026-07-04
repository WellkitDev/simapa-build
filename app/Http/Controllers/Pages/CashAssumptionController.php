<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\CashFixedExpense;
use App\Models\CashMargin;
use Illuminate\Http\Request;

class CashAssumptionController extends Controller
{
    public function index()
    {
        $expenses = CashFixedExpense::orderBy('position')->get();

        return view('accounting.assumption', [
            'margins'      => CashMargin::orderBy('position')->get(),
            'expenses'     => $expenses,
            'totalMonthly' => (float) $expenses->where('active', true)->sum(fn ($e) => $e->monthlyAmount()),
        ]);
    }

    public function storeMargin(Request $request)
    {
        $data = $request->validate(['code' => 'nullable|string|max:50', 'label' => 'required|string|max:150', 'margin_pct' => 'required|numeric|min:0|max:100']);
        $data['active'] = true;
        CashMargin::create($data);

        return back()->with('success', 'Margin ditambahkan.');
    }

    public function updateMargin(Request $request, int $id)
    {
        $margin = CashMargin::findOrFail($id);
        $data = $request->validate(['code' => 'nullable|string|max:50', 'label' => 'required|string|max:150', 'margin_pct' => 'required|numeric|min:0|max:100']);
        $data['active'] = $request->boolean('active');
        $margin->update($data);

        return back()->with('success', 'Margin diperbarui.');
    }

    public function destroyMargin(int $id)
    {
        CashMargin::findOrFail($id)->delete();

        return back()->with('success', 'Margin dihapus.');
    }

    public function storeExpense(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:150', 'period' => 'required|in:bulanan,tahunan', 'amount' => 'required|numeric|min:0', 'note' => 'nullable|string']);
        $data['active'] = true;
        CashFixedExpense::create($data);

        return back()->with('success', 'Biaya tetap ditambahkan.');
    }

    public function updateExpense(Request $request, int $id)
    {
        $expense = CashFixedExpense::findOrFail($id);
        $data = $request->validate(['name' => 'required|string|max:150', 'period' => 'required|in:bulanan,tahunan', 'amount' => 'required|numeric|min:0', 'note' => 'nullable|string']);
        $data['active'] = $request->boolean('active');
        $expense->update($data);

        return back()->with('success', 'Biaya tetap diperbarui.');
    }

    public function destroyExpense(int $id)
    {
        CashFixedExpense::findOrFail($id)->delete();

        return back()->with('success', 'Biaya tetap dihapus.');
    }
}
