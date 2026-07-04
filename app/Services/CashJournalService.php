<?php

namespace App\Services;

use App\Models\CashEntry;
use App\Models\CashSetting;
use Carbon\Carbon;

class CashJournalService
{
    /** Kode transaksi otomatis: B{bulan}{yy} (Okt 2025 → B1025; Jan 2026 → B126). */
    public function deriveKode(Carbon $tanggal): string
    {
        return 'B' . $tanggal->month . substr((string) $tanggal->year, -2);
    }

    /**
     * Hitung jurnal periode: saldo berjalan (opening + kumulatif) + ringkasan.
     * @return array{entries:\Illuminate\Support\Collection,opening:float,totalIn:float,totalOut:float,saldoAkhir:float}
     */
    public function compute(int $year, ?int $month, ?string $jenis = null): array
    {
        $start = $month ? Carbon::create($year, $month, 1)->startOfDay() : Carbon::create($year, 1, 1)->startOfDay();

        $saldoAwal = (float) CashSetting::singleton()->saldo_awal;
        $priorIn   = (float) CashEntry::where('tanggal', '<', $start)->where('jenis', 'pemasukan')->sum('amount');
        $priorOut  = (float) CashEntry::where('tanggal', '<', $start)->where('jenis', 'pengeluaran')->sum('amount');
        $opening   = $saldoAwal + $priorIn - $priorOut;

        $q = CashEntry::with('category')->whereYear('tanggal', $year);
        if ($month) { $q->whereMonth('tanggal', $month); }
        $all = $q->orderBy('tanggal')->orderBy('id')->get();

        $running = $opening;
        foreach ($all as $e) {
            $running += $e->isPemasukan() ? (float) $e->amount : -(float) $e->amount;
            $e->saldo = $running;
        }

        $totalIn  = (float) $all->where('jenis', 'pemasukan')->sum('amount');
        $totalOut = (float) $all->where('jenis', 'pengeluaran')->sum('amount');
        $saldoAkhir = $opening + $totalIn - $totalOut;

        $entries = $jenis ? $all->where('jenis', $jenis)->values() : $all;

        return compact('entries', 'opening', 'totalIn', 'totalOut', 'saldoAkhir', 'saldoAwal');
    }
}
