<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Services\CashRecapService;
use App\Services\ExpenseGapService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class AccountingDashboardController extends Controller
{
    public function __construct(private CashRecapService $service) {}

    public function index(Request $request)
    {
        $year = (int) $request->query('year', now()->year);

        return view('accounting.dashboard', [
            'year'  => $year,
            'recap' => $this->service->monthlyRecap($year),
            'ytd'   => $this->service->ytd($year),
            'gap'          => app(ExpenseGapService::class)->check($year),
            'periodeLabel' => 'sepanjang ' . $year,
        ]);
    }

    public function exportCsv(Request $request)
    {
        $year = (int) $request->query('year', now()->year);
        $recap = $this->service->monthlyRecap($year);
        $ytd = $this->service->ytd($year);

        return response()->streamDownload(function () use ($recap, $ytd) {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF");
            fputcsv($h, ['Bulan', 'Pemasukan', 'Pengeluaran', 'Laba', 'Saldo Akhir'], ';');
            foreach ($recap as $r) {
                fputcsv($h, [$r['label'], (int) $r['totalIn'], (int) $r['totalOut'], (int) $r['laba'], (int) $r['saldoAkhir']], ';');
            }
            fputcsv($h, ['YTD', (int) $ytd['totalIn'], (int) $ytd['totalOut'], (int) $ytd['laba'], (int) $ytd['saldoAkhir']], ';');
            fclose($h);
        }, 'Rekap_' . $year . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportPdf(Request $request)
    {
        $year = (int) $request->query('year', now()->year);
        $recap = $this->service->monthlyRecap($year);
        $ytd = $this->service->ytd($year);

        return Pdf::loadView('accounting.pdf.recap', compact('year', 'recap', 'ytd'))
            ->download('Rekap_' . $year . '.pdf');
    }
}
