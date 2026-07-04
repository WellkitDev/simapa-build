<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\CashCategory;
use App\Models\CashEntry;
use App\Models\CashSetting;
use App\Services\CashJournalService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CashEntryController extends Controller
{
    public function __construct(private CashJournalService $service) {}

    public function index(Request $request)
    {
        $now   = now();
        $year  = (int) $request->query('year', $now->year);
        $mq    = $request->query('month', (string) $now->month);
        $month = ($mq === 'all') ? null : (int) ($mq ?: $now->month);
        $jenis = in_array($request->query('jenis'), ['pemasukan', 'pengeluaran'], true) ? $request->query('jenis') : null;

        $data = $this->service->compute($year, $month, $jenis);

        return view('accounting.journal', array_merge($data, [
            'year'          => $year,
            'month'         => $month,
            'jenis'         => $jenis,
            'categories'    => CashCategory::active()->orderBy('jenis')->orderBy('position')->get(),
            'allCategories' => CashCategory::orderBy('jenis')->orderBy('position')->get(),
            'setting'       => CashSetting::singleton(),
        ]));
    }

    /** Set saldo awal (saldo pembukaan kas) — melanjutkan saldo dari data sebelumnya. */
    public function updateOpening(Request $request)
    {
        $data = $request->validate([
            'saldo_awal'   => 'required|numeric|min:0',
            'tanggal_awal' => 'nullable|date',
        ]);
        $data['tanggal_awal'] = ($data['tanggal_awal'] ?? '') ?: null;
        $data['updated_by'] = Auth::id();
        CashSetting::singleton()->update($data);

        return back()->with('success', 'Saldo awal diperbarui.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'tanggal'          => 'required|date',
            'jenis'            => 'required|in:pemasukan,pengeluaran',
            'cash_category_id' => 'nullable|exists:tb_cash_categories,id',
            'amount'           => 'required|numeric|min:0',
            'produk'           => 'nullable|in:artikel,buku,operasional',
            'keterangan'       => 'required|string|max:255',
            'ref'              => 'nullable|string|max:100',
            'catatan'          => 'nullable|string',
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['kode']       = $this->service->deriveKode(Carbon::parse($data['tanggal']));
        $data['source']     = 'manual';
        $data['created_by'] = Auth::id();
        CashEntry::create($data);

        return back()->with('success', 'Transaksi kas ditambahkan.');
    }

    public function update(Request $request, int $id)
    {
        $entry = CashEntry::findOrFail($id);
        $data = $this->validated($request);
        $data['kode'] = $this->service->deriveKode(Carbon::parse($data['tanggal']));
        $entry->update($data);

        return back()->with('success', 'Transaksi kas diperbarui.');
    }

    public function destroy(int $id)
    {
        CashEntry::findOrFail($id)->delete();

        return back()->with('success', 'Transaksi kas dihapus.');
    }
}
