<?php

namespace App\Services;

use App\Models\CashEntry;
use App\Models\CashMargin;
use App\Models\OrderDetail;
use App\Models\Payment;

class ProfitAnalysisService
{
    private array $labels = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    /**
     * Tier + margin untuk satu OrderDetail.
     *
     * Tier tak dikenal (null/coppernicus) → S2 = margin TERENDAH: cadangan APC
     * lebih besar, siap-dibagi lebih kecil. Salah ke arah aman — lebih baik
     * kurang membagi daripada membagi uang yang ternyata dibutuhkan APC.
     *
     * @return array{code:?string,pct:float,unknownTier:bool,marginMissing:bool}
     */
    public function marginFor(?OrderDetail $detail): array
    {
        if (! $detail || ! $detail->type) {
            return ['code' => null, 'pct' => 0.0, 'unknownTier' => false, 'marginMissing' => false];
        }

        $isBook = str_starts_with($detail->type, 'bk_');
        $tier   = $this->tierOf($detail->indexation);

        // Buku tak bertingkat → tier tak relevan, jangan ditandai unknown.
        $unknown = ! $isBook && $tier === null;
        $bucket  = $tier ?? 'S2';

        $code = $isBook
            ? 'M_BK_ALL'
            : (str_starts_with($detail->type, 'at_mandiri') ? 'M_ART_' : 'M_KOL_') . $bucket;

        return $this->resolveMarginByCode($code, $unknown);
    }

    /** Resolve pct + marginMissing dari kode margin Asumsi. */
    private function resolveMarginByCode(string $code, bool $unknownTier): array
    {
        $margin = CashMargin::where('code', $code)->where('active', true)->first();

        return [
            'code'          => $code,
            'pct'           => (float) ($margin->margin_pct ?? 0),
            'unknownTier'   => $unknownTier,
            'marginMissing' => $margin === null,
        ];
    }

    /** "SINTA 2"/"sinta 2" → S2 · "sinta 4/5/6" → S4 · selain itu null. */
    private function tierOf(?string $indexation): ?string
    {
        if (! $indexation) {
            return null;
        }
        if (! preg_match('/sinta\s*(\d)/i', trim($indexation), $m)) {
            return null;
        }
        $n = (int) $m[1];

        return match (true) {
            in_array($n, [2, 3], true)    => 'S2',
            in_array($n, [4, 5, 6], true) => 'S4',
            default                       => null,
        };
    }

    /**
     * Margin untuk pemasukan MANUAL (tanpa order). produk menentukan lebih
     * dulu; bila produk bukan buku/artikel, map_key kategori dipakai; bila
     * tetap tak cocok -> 100% siap dibagi.
     *
     * @return array{code:?string,pct:float,unknownTier:bool,marginMissing:bool}
     */
    public function marginForManual(CashEntry $e): array
    {
        $produk = strtolower(trim((string) $e->produk));
        $mapKey = optional($e->category)->map_key;

        $code = null;
        if ($produk === 'buku') {
            $code = 'M_BK_ALL';
        } elseif ($produk === 'artikel') {
            $code = $mapKey === 'at_mandiri' ? 'M_ART_S2' : 'M_KOL_S2';
        } elseif (in_array($mapKey, ['bk_kolab', 'bk_mandiri'], true)) {
            $code = 'M_BK_ALL';
        } elseif ($mapKey === 'at_mandiri') {
            $code = 'M_ART_S2';
        } elseif ($mapKey === 'at_kolab') {
            $code = 'M_KOL_S2';
        }

        if ($code === null) {
            // Non-artikel/buku -> seluruhnya siap dibagi, tanpa cadangan APC.
            return ['code' => null, 'pct' => 100.0, 'unknownTier' => false, 'marginMissing' => false];
        }

        return $this->resolveMarginByCode($code, false);
    }

    /**
     * @return array{rows:array,totalIn:float,totalReserve:float,totalMargin:float,unknownTier:int,noOrder:int}
     */
    public function forMonth(int $year, int $month): array
    {
        $payments = Payment::where('status', 'paid')
            ->whereYear('paid_at', $year)->whereMonth('paid_at', $month)
            ->with(['order.details'])
            ->orderBy('paid_at')->get();

        $rows = [];
        $totalIn = $totalReserve = $totalMargin = 0.0;
        $unknownTier = $noOrder = 0;

        foreach ($payments as $p) {
            $detail = optional($p->order)->details;
            if (! $detail) {
                $noOrder++;
                $totalIn += $this->signed($p);
                continue;
            }

            $m    = $this->marginFor($detail);
            $base = $this->signed($p);                 // refund → negatif
            $marg = round($base * $m['pct'] / 100, 2);
            $res  = $base - $marg;

            $totalIn      += $base;
            $totalMargin  += $marg;
            $totalReserve += $res;
            if ($m['unknownTier']) {
                $unknownTier++;
            }

            $rows[] = [
                'tanggal'       => optional($p->paid_at)->format('d/m/y'),
                'code_order'    => optional($p->order)->code_order,
                'judul'         => $detail->title,
                'type'          => $detail->type,
                'indexation'    => $detail->indexation,
                'marginCode'    => $m['code'],
                'pct'           => $m['pct'],
                'base'          => $base,
                'reserve'       => $res,
                'margin'        => $marg,
                'unknownTier'   => $m['unknownTier'],
                'marginMissing' => $m['marginMissing'],
                'manual'        => false,
            ];
        }

        $manual = CashEntry::where('jenis', 'pemasukan')
            ->where('source', '!=', 'payment')
            ->where('is_transfer', false)
            ->whereYear('tanggal', $year)->whereMonth('tanggal', $month)
            ->with('category')
            ->orderBy('tanggal')->orderBy('id')->get();

        foreach ($manual as $e) {
            $m    = $this->marginForManual($e);
            $base = (float) $e->amount;
            $marg = round($base * $m['pct'] / 100, 2);
            $res  = $base - $marg;

            $totalIn      += $base;
            $totalMargin  += $marg;
            $totalReserve += $res;

            $rows[] = [
                'tanggal'       => optional($e->tanggal)->format('d/m/y'),
                'code_order'    => null,
                'judul'         => $e->keterangan,
                'type'          => $e->produk ?: '(manual)',
                'indexation'    => null,
                'marginCode'    => $m['code'],
                'pct'           => $m['pct'],
                'base'          => $base,
                'reserve'       => $res,
                'margin'        => $marg,
                'unknownTier'   => $m['unknownTier'],
                'marginMissing' => $m['marginMissing'],
                'manual'        => true,
            ];
        }

        return compact('rows', 'totalIn', 'totalReserve', 'totalMargin', 'unknownTier', 'noOrder');
    }

    /** Akumulasi 12 bulan (permintaan user). @return array<int,array> */
    public function yearly(int $year): array
    {
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $h = $this->forMonth($year, $m);
            $out[] = [
                'month'        => $m,
                'label'        => $this->labels[$m],
                'totalIn'      => $h['totalIn'],
                'totalReserve' => $h['totalReserve'],
                'totalMargin'  => $h['totalMargin'],
            ];
        }

        return $out;
    }

    /** Refund = uang keluar → negatif (cermin Order::paidNet). */
    private function signed(Payment $p): float
    {
        return $p->payment_type === 'refund' ? -(float) $p->amount : (float) $p->amount;
    }
}
