<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans, sans-serif;font-size:11px;color:#222}
h2{margin:0 0 8px}table{width:100%;border-collapse:collapse}
th,td{border:1px solid #ccc;padding:5px 7px}th{background:#f0f0f0;text-align:left}
.text-end{text-align:right}tfoot td{font-weight:bold;background:#fafafa}
</style></head><body>
@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp
<h2>Rekap Keuangan {{ $year }}</h2>
<table>
    <thead><tr><th>Bulan</th><th class="text-end">Pemasukan</th><th class="text-end">Pengeluaran</th><th class="text-end">Laba</th><th class="text-end">Saldo Akhir</th></tr></thead>
    <tbody>
    @foreach($recap as $r)
        <tr>
            <td>{{ $r['label'] }}</td>
            <td class="text-end">{{ $rp($r['totalIn']) }}</td>
            <td class="text-end">{{ $rp($r['totalOut']) }}</td>
            <td class="text-end">{{ $rp($r['laba']) }}</td>
            <td class="text-end">{{ $rp($r['saldoAkhir']) }}</td>
        </tr>
    @endforeach
    </tbody>
    <tfoot><tr>
        <td>YTD</td>
        <td class="text-end">{{ $rp($ytd['totalIn']) }}</td>
        <td class="text-end">{{ $rp($ytd['totalOut']) }}</td>
        <td class="text-end">{{ $rp($ytd['laba']) }}</td>
        <td class="text-end">{{ $rp($ytd['saldoAkhir']) }}</td>
    </tr></tfoot>
</table>
</body></html>
