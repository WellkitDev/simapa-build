<?php

namespace App\Services;

use App\Models\CashEntry;
use App\Models\CashAccount;
use Carbon\Carbon;

class CashRecapService
{
    private array $labels = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    /** @return array<int,array> 12 bulan: month,label,inArtikel,inBuku,totalIn,totalOut,laba,saldoAkhir */
    public function monthlyRecap(int $year): array
    {
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $opening = CashAccount::totalOpening()
            + (float) CashEntry::where('tanggal', '<', $yearStart)->where('is_transfer', false)->where('jenis', 'pemasukan')->sum('amount')
            - (float) CashEntry::where('tanggal', '<', $yearStart)->where('is_transfer', false)->where('jenis', 'pengeluaran')->sum('amount');

        $entries = CashEntry::whereYear('tanggal', $year)->where('is_transfer', false)->get();
        $running = $opening;
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $mo = $entries->filter(fn ($e) => (int) $e->tanggal->month === $m);
            $in = $mo->where('jenis', 'pemasukan');
            $inArtikel = (float) $in->where('produk', 'artikel')->sum('amount');
            $inBuku    = (float) $in->where('produk', 'buku')->sum('amount');
            $totalIn   = (float) $in->sum('amount');
            $totalOut  = (float) $mo->where('jenis', 'pengeluaran')->sum('amount');
            $laba      = $totalIn - $totalOut;
            $running  += $laba;
            $out[] = [
                'month' => $m, 'label' => $this->labels[$m],
                'inArtikel' => $inArtikel, 'inBuku' => $inBuku,
                'totalIn' => $totalIn, 'totalOut' => $totalOut, 'laba' => $laba, 'saldoAkhir' => $running,
            ];
        }
        return $out;
    }

    /** @return array totalIn,totalOut,laba,saldoAkhir,avgLaba,incomeArtikel,incomeBuku,expenseByCategory,bestMonthLabel */
    public function ytd(int $year): array
    {
        $recap = $this->monthlyRecap($year);
        $totalIn  = (float) array_sum(array_column($recap, 'totalIn'));
        $totalOut = (float) array_sum(array_column($recap, 'totalOut'));
        $laba     = $totalIn - $totalOut;
        $saldoAkhir = (float) end($recap)['saldoAkhir'];
        $activeMonths = count(array_filter($recap, fn ($r) => $r['totalIn'] > 0 || $r['totalOut'] > 0));
        $avgLaba = $laba / max(1, $activeMonths);
        $incomeArtikel = (float) array_sum(array_column($recap, 'inArtikel'));
        $incomeBuku    = (float) array_sum(array_column($recap, 'inBuku'));

        $expenseByCategory = CashEntry::whereYear('tanggal', $year)->where('jenis', 'pengeluaran')->where('is_transfer', false)
            ->with('category')->get()
            ->groupBy(fn ($e) => optional($e->category)->name ?? 'Tanpa Kategori')
            ->map(fn ($g) => (float) $g->sum('amount'))
            ->sortDesc()->toArray();

        $best = collect($recap)->filter(fn ($r) => $r['totalIn'] > 0 || $r['totalOut'] > 0)->sortByDesc('laba')->first();
        $bestMonthLabel = $best['label'] ?? null;

        return compact('totalIn', 'totalOut', 'laba', 'saldoAkhir', 'avgLaba', 'incomeArtikel', 'incomeBuku', 'expenseByCategory', 'bestMonthLabel');
    }
}
