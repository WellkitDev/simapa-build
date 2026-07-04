<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\CashEntry;
use Carbon\Carbon;

class CashJournalService
{
    /** Kode transaksi otomatis: B{bulan}{yy} (Okt 2025 → B1025; Jan 2026 → B126). */
    public function deriveKode(Carbon $tanggal): string
    {
        return 'B' . $tanggal->month . substr((string) $tanggal->year, -2);
    }

    /**
     * Hitung jurnal periode: saldo berjalan (opening + kumulatif, termasuk transfer) + ringkasan.
     * Bila $accountId diisi → discope ke akun itu; else gabungan semua akun.
     * @return array{entries:\Illuminate\Support\Collection,opening:float,totalIn:float,totalOut:float,saldoAkhir:float,saldoAwal:float}
     */
    public function compute(int $year, ?int $month, ?string $jenis = null, ?int $accountId = null): array
    {
        $start = $month ? Carbon::create($year, $month, 1)->startOfDay() : Carbon::create($year, 1, 1)->startOfDay();

        $saldoAwal = $accountId
            ? (float) optional(CashAccount::find($accountId))->opening_balance
            : CashAccount::totalOpening();

        $priorIn  = (float) CashEntry::where('tanggal', '<', $start)->when($accountId, fn ($q) => $q->where('account_id', $accountId))->where('jenis', 'pemasukan')->sum('amount');
        $priorOut = (float) CashEntry::where('tanggal', '<', $start)->when($accountId, fn ($q) => $q->where('account_id', $accountId))->where('jenis', 'pengeluaran')->sum('amount');
        $opening  = $saldoAwal + $priorIn - $priorOut;

        $q = CashEntry::with('category', 'account')->whereYear('tanggal', $year);
        if ($month)     { $q->whereMonth('tanggal', $month); }
        if ($accountId) { $q->where('account_id', $accountId); }
        $all = $q->orderBy('tanggal')->orderBy('id')->get();

        $running = $opening;
        foreach ($all as $e) {
            $running += $e->isPemasukan() ? (float) $e->amount : -(float) $e->amount;
            $e->saldo = $running;
        }
        $saldoAkhir = $running; // termasuk transfer (benar untuk per-akun; net-nol untuk gabungan)

        $real     = $all->where('is_transfer', false);
        $totalIn  = (float) $real->where('jenis', 'pemasukan')->sum('amount');
        $totalOut = (float) $real->where('jenis', 'pengeluaran')->sum('amount');

        $entries = $jenis ? $all->where('jenis', $jenis)->values() : $all;

        return compact('entries', 'opening', 'totalIn', 'totalOut', 'saldoAkhir', 'saldoAwal');
    }

    /**
     * Saldo tiap akun aktif (opening + Σ pemasukan − Σ pengeluaran, termasuk transfer).
     * @return array{rows:array<int,array{account:\App\Models\CashAccount,saldo:float}>,total:float}
     */
    public function accountBalances(): array
    {
        $rows = [];
        $total = 0.0;
        foreach (CashAccount::active()->orderBy('position')->get() as $acc) {
            $in  = (float) CashEntry::where('account_id', $acc->id)->where('jenis', 'pemasukan')->sum('amount');
            $out = (float) CashEntry::where('account_id', $acc->id)->where('jenis', 'pengeluaran')->sum('amount');
            $saldo = (float) $acc->opening_balance + $in - $out;
            $rows[] = ['account' => $acc, 'saldo' => $saldo];
            $total += $saldo;
        }
        return ['rows' => $rows, 'total' => $total];
    }
}
