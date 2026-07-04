<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\CashCategory;
use Illuminate\Http\Request;

class CashCategoryController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'jenis' => 'required|in:pemasukan,pengeluaran']);
        $data['active'] = true;
        CashCategory::create($data);

        return back()->with('success', 'Kategori ditambahkan.');
    }

    public function update(Request $request, int $id)
    {
        $cat = CashCategory::findOrFail($id);
        $data = $request->validate(['name' => 'required|string|max:100', 'jenis' => 'required|in:pemasukan,pengeluaran']);
        $data['active'] = $request->boolean('active');
        $cat->update($data);

        return back()->with('success', 'Kategori diperbarui.');
    }

    public function destroy(int $id)
    {
        CashCategory::findOrFail($id)->delete();

        return back()->with('success', 'Kategori dihapus.');
    }
}
