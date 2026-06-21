<?php

namespace App\Http\Controllers\Pages;

use App\Services\FinancialReportService;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncomeController extends Controller
{
    public function __construct(private FinancialReportService $svc) {}

    private function scope()
    {
        return $this->svc->resolveScope(Auth::user());
    }

    public function pemasukan()
    {
        return view('income.pemasukan', $this->svc->pemasukan($this->scope()));
    }

    public function piutang()
    {
        return view('income.piutang', $this->svc->piutang($this->scope()));
    }

    public function lunas()
    {
        return view('income.lunas', $this->svc->orderSelesai($this->scope()));
    }

    public function pemasukanPdf()
    {
        return Pdf::loadView('income.pdf.pemasukan', $this->svc->pemasukan($this->scope()))
            ->stream('Laporan_Pemasukan.pdf');
    }

    public function pemasukanCsv(): StreamedResponse
    {
        $d = $this->svc->pemasukan($this->scope());
        return $this->csv('pemasukan.csv',
            ['Tanggal', 'Kode Order', 'Klien', 'Tipe', 'Nominal', 'No Invoice'],
            $d['detail']->map(fn ($p) => [
                optional($p->paid_at)->format('Y-m-d') ?? '',
                optional($p->order)->code_order,
                optional(optional($p->order)->details)->title,
                $p->payment_type,
                $p->amount,
                optional($p->invoice)->invoice_no ?? '-',
            ])->all()
        );
    }

    public function piutangPdf()
    {
        return Pdf::loadView('income.pdf.piutang', $this->svc->piutang($this->scope()))
            ->stream('Laporan_Piutang.pdf');
    }

    public function piutangCsv(): StreamedResponse
    {
        $d = $this->svc->piutang($this->scope());
        return $this->csv('piutang.csv',
            ['Kode Order', 'Klien', 'Nilai', 'Sudah Bayar', 'Sisa', 'Status'],
            $d['detail']->map(fn ($o) => [
                $o->code_order,
                optional($o->details)->title,
                $o->nilai, $o->paid_amount, $o->sisa, $o->status,
            ])->all()
        );
    }

    public function lunasPdf()
    {
        return Pdf::loadView('income.pdf.lunas', $this->svc->orderSelesai($this->scope()))
            ->stream('Laporan_Order_Selesai.pdf');
    }

    public function lunasCsv(): StreamedResponse
    {
        $d = $this->svc->orderSelesai($this->scope());
        return $this->csv('order_selesai.csv',
            ['Kode Order', 'Klien', 'Nilai', 'Tanggal Lunas'],
            $d['detail']->map(fn ($o) => [
                $o->code_order,
                optional($o->details)->title,
                $o->nilai,
                optional($o->tanggal_lunas)->format('Y-m-d') ?? '',
            ])->all()
        );
    }

    private function csv(string $filename, array $headers, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
