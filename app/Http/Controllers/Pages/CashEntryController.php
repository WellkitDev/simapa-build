<?php

namespace App\Http\Controllers\Pages;

use App\Exceptions\CashEntryGuardException;
use App\Http\Controllers\Controller;
use App\Models\CashAccount;
use App\Models\CashCategory;
use App\Models\CashEntry;
use App\Services\CashJournalService;
use App\Services\CashPeriodService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class CashEntryController extends Controller
{
    public function __construct(private CashJournalService $service) {}

    /** @return array{0:int,1:?int,2:?string,3:?int} [year, month, jenis, accountId] dari query. */
    private function resolveFilters(Request $request): array
    {
        $now   = now();
        $year  = (int) $request->query('year', $now->year);
        $mq    = $request->query('month', (string) $now->month);
        $month = ($mq === 'all') ? null : (int) ($mq ?: $now->month);
        $jenis = in_array($request->query('jenis'), ['pemasukan', 'pengeluaran'], true) ? $request->query('jenis') : null;
        $acc   = $request->query('account');
        $accountId = ($acc === null || $acc === '' || $acc === 'all') ? null : (int) $acc;
        return [$year, $month, $jenis, $accountId];
    }

    public function index(Request $request)
    {
        [$year, $month, $jenis, $accountId] = $this->resolveFilters($request);

        $data = $this->service->compute($year, $month, $jenis, $accountId);

        return view('accounting.journal', array_merge($data, [
            'year'          => $year,
            'month'         => $month,
            'jenis'         => $jenis,
            'accountId'     => $accountId,
            // $month null saat filter "semua bulan" → tombol kunci tak relevan.
            'periodLock'    => $month ? app(CashPeriodService::class)->lockFor($year, $month) : null,
            'canLock'       => (bool) optional(auth()->user())->hasRole('superadmin'),
            'categories'    => CashCategory::active()->orderBy('jenis')->orderBy('position')->get(),
            'allCategories' => CashCategory::orderBy('jenis')->orderBy('position')->get(),
            'accounts'      => CashAccount::active()->orderBy('position')->get(),
            'allAccounts'   => CashAccount::orderBy('position')->get(),
            'balances'      => $this->service->accountBalances(),
        ]));
    }

    public function exportCsv(Request $request)
    {
        [$year, $month, $jenis, $accountId] = $this->resolveFilters($request);
        $data = $this->service->compute($year, $month, $jenis, $accountId);
        $filename = 'Jurnal_Kas_' . $year . '_' . ($month ?? 'semua') . '.csv';

        return response()->streamDownload(function () use ($data) {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF"); // BOM UTF-8
            fputcsv($h, ['Tanggal', 'Kode', 'Keterangan', 'Akun', 'Kategori', 'Produk', 'Pemasukan', 'Pengeluaran', 'Saldo', 'Ref'], ';');
            foreach ($data['entries'] as $e) {
                fputcsv($h, [
                    optional($e->tanggal)->format('Y-m-d'),
                    $e->kode,
                    $e->keterangan,
                    $e->account?->name ?? '',
                    $e->category?->name ?? '',
                    \App\Models\CashEntry::PRODUK[$e->produk] ?? ($e->produk ?: ''),
                    $e->isPemasukan() ? (int) $e->amount : '',
                    ! $e->isPemasukan() ? (int) $e->amount : '',
                    (int) ($e->saldo ?? 0),
                    $e->ref ?? '',
                ], ';');
            }
            fclose($h);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportPdf(Request $request)
    {
        [$year, $month, $jenis, $accountId] = $this->resolveFilters($request);
        $data = $this->service->compute($year, $month, $jenis, $accountId);
        $data['year'] = $year;
        $data['month'] = $month;

        return Pdf::loadView('accounting.pdf.journal', $data)
            ->download('Jurnal_Kas_' . $year . '_' . ($month ?? 'semua') . '.pdf');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'tanggal'          => 'required|date',
            'jenis'            => 'required|in:pemasukan,pengeluaran',
            // Tanpa `string`: id numerik dari form/test bukan is_string(); resolveCategoryId() yang memilah id vs nama baru.
            'cash_category_id' => 'nullable|max:100',
            'account_id'       => 'nullable|exists:tb_cash_accounts,id',
            'amount'           => 'required|numeric|min:0',
            'produk'           => 'nullable|string|max:50',
            'keterangan'       => 'required|string|max:255',
            'ref'              => 'nullable|string|max:100',
            'catatan'          => 'nullable|string',
        ]);
    }

    /**
     * id kategori dari input entri. Numerik = referensi id kategori yang sudah
     * ada (select2 mengirim TEKS untuk tag baru, jadi angka selalu berarti id);
     * discope ke jenis entri, id basi/tak cocok -> null (jangan buat kategori
     * "angka"). Teks non-numerik -> firstOrCreate (nama+jenis). Kosong -> null.
     */
    private function resolveCategoryId($raw, string $jenis): ?int
    {
        if (! is_scalar($raw)) {
            return null;
        }
        $raw = is_string($raw) ? trim($raw) : $raw;
        if ($raw === '' || $raw === null) {
            return null;
        }
        if (is_numeric($raw)) {
            return optional(CashCategory::where('jenis', $jenis)->find((int) $raw))->id;
        }

        return CashCategory::firstOrCreate(
            ['name' => (string) $raw, 'jenis' => $jenis],
            ['active' => true, 'position' => (int) CashCategory::where('jenis', $jenis)->max('position') + 1]
        )->id;
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['cash_category_id'] = $this->resolveCategoryId($data['cash_category_id'] ?? null, $data['jenis']);
        app(CashPeriodService::class)->assertUnlocked($data['tanggal']);
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

        app(CashPeriodService::class)->assertUnlocked($data['tanggal']);

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
        if ($entry->source === 'payment') {
            throw CashEntryGuardException::autoEntry();
        }
        $data = $this->validated($request);
        $data['cash_category_id'] = $this->resolveCategoryId($data['cash_category_id'] ?? null, $data['jenis']);
        $periode = app(CashPeriodService::class);
        $periode->assertUnlocked($entry->tanggal);   // tanggal LAMA
        $periode->assertUnlocked($data['tanggal']);  // tanggal BARU
        $data['account_id'] = $data['account_id'] ?? optional(CashAccount::incomeDefault())->id;
        $data['kode'] = $this->service->deriveKode(Carbon::parse($data['tanggal']));
        $entry->update($data);

        return back()->with('success', 'Transaksi kas diperbarui.');
    }

    public function destroy(int $id)
    {
        $entry = CashEntry::findOrFail($id);
        if ($entry->source === 'payment') {
            throw CashEntryGuardException::autoEntry();
        }
        app(CashPeriodService::class)->assertUnlocked($entry->tanggal);

        if ($entry->is_transfer && $entry->transfer_group) {
            CashEntry::where('transfer_group', $entry->transfer_group)->delete();
            return back()->with('success', 'Transfer dihapus (kedua sisi).');
        }
        $entry->delete();

        return back()->with('success', 'Transaksi kas dihapus.');
    }
}
