<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\CashAccount;
use Illuminate\Http\Request;

class CashAccountController extends Controller
{
    private function rules(): array
    {
        return [
            'name'            => 'required|string|max:100',
            'purpose'         => 'nullable|in:pemasukan,operational,harta,umum',
            'opening_balance' => 'required|numeric|min:0',
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $data['is_income_default'] = $request->boolean('is_income_default');
        $data['active']   = $request->boolean('active', true);
        $data['position'] = (int) (CashAccount::max('position') ?? 0) + 1;
        if ($data['is_income_default']) { CashAccount::query()->update(['is_income_default' => false]); }
        CashAccount::create($data);

        return back()->with('success', 'Akun ditambahkan.');
    }

    public function update(Request $request, int $id)
    {
        $acc = CashAccount::findOrFail($id);
        $data = $request->validate($this->rules());
        $data['is_income_default'] = $request->boolean('is_income_default');
        $data['active'] = $request->boolean('active');
        if ($data['is_income_default']) { CashAccount::where('id', '!=', $acc->id)->update(['is_income_default' => false]); }
        $acc->update($data);

        return back()->with('success', 'Akun diperbarui.');
    }

    public function destroy(int $id)
    {
        $acc = CashAccount::findOrFail($id);
        if ($acc->is_income_default) {
            return back()->with('error', 'Akun pemasukan default tidak bisa dihapus. Tetapkan akun default lain dulu.');
        }
        if ($acc->entries()->exists()) {
            return back()->with('error', 'Akun memiliki transaksi. Nonaktifkan saja, jangan dihapus.');
        }
        $acc->delete();

        return back()->with('success', 'Akun dihapus.');
    }
}
