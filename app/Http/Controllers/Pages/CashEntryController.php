<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\CashAccount;
use App\Models\CashCategory;
use App\Models\CashEntry;
use App\Models\CashSetting;
use App\Services\CashJournalService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

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
            'account_id'       => 'nullable|exists:tb_cash_accounts,id',
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
        $data['account_id'] = $data['account_id'] ?? optional(CashAccount::incomeDefault())->id;
        $data['kode']       = $this->service->deriveKode(Carbon::parse($data['tanggal']));
        $data['source']     = 'manual';
        $data['created_by'] = Auth::id();
        CashEntry::create($data);

        return back()->with('success', 'Transaksi kas ditambahkan.');
    }

    /** Transfer dana antar akun: buat 2 baris (keluar + masuk), ditandai internal (is_transfer). */
    public function transfer(Request $request)
    {
        $data = $request->validate([
            'from_account_id' => 'required|exists:tb_cash_accounts,id',
            'to_account_id'   => 'required|exists:tb_cash_accounts,id|different:from_account_id',
            'amount'          => 'required|numeric|min:1',
            'tanggal'         => 'required|date',
            'catatan'         => 'nullable|string',
        ]);

        $from = CashAccount::find($data['from_account_id']);
        $to   = CashAccount::find($data['to_account_id']);
        $group = (string) Str::uuid();
        $kode  = $this->service->deriveKode(Carbon::parse($data['tanggal']));
        $ket   = "Transfer: {$from->name} → {$to->name}";

        $base = [
            'tanggal' => $data['tanggal'], 'kode' => $kode, 'keterangan' => $ket,
            'amount' => $data['amount'], 'catatan' => $data['catatan'] ?? null,
            'is_transfer' => true, 'transfer_group' => $group,
            'source' => 'manual', 'created_by' => Auth::id(),
        ];
        CashEntry::create($base + ['account_id' => $from->id, 'jenis' => 'pengeluaran']);
        CashEntry::create($base + ['account_id' => $to->id,   'jenis' => 'pemasukan']);

        return back()->with('success', 'Transfer dana dicatat.');
    }

    public function update(Request $request, int $id)
    {
        $entry = CashEntry::findOrFail($id);
        $data = $this->validated($request);
        $data['account_id'] = $data['account_id'] ?? optional(CashAccount::incomeDefault())->id;
        $data['kode'] = $this->service->deriveKode(Carbon::parse($data['tanggal']));
        $entry->update($data);

        return back()->with('success', 'Transaksi kas diperbarui.');
    }

    public function destroy(int $id)
    {
        $entry = CashEntry::findOrFail($id);
        if ($entry->is_transfer && $entry->transfer_group) {
            CashEntry::where('transfer_group', $entry->transfer_group)->delete();
            return back()->with('success', 'Transfer dihapus (kedua sisi).');
        }
        $entry->delete();

        return back()->with('success', 'Transaksi kas dihapus.');
    }
}
