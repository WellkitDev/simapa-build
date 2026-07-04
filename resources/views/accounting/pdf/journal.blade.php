<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans, sans-serif;font-size:11px;color:#222}
h2{margin:0 0 4px}.muted{color:#666;margin-bottom:8px}
table{width:100%;border-collapse:collapse}
th,td{border:1px solid #ccc;padding:4px 6px}th{background:#f0f0f0;text-align:left}
.text-end{text-align:right}
</style></head><body>
@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp
<h2>Jurnal Kas {{ $year }}@if($month) — Bulan {{ $month }}@endif</h2>
<div class="muted">Saldo awal periode: {{ $rp($opening) }} · Pemasukan: {{ $rp($totalIn) }} · Pengeluaran: {{ $rp($totalOut) }} · Saldo akhir: {{ $rp($saldoAkhir) }}</div>
<table>
    <thead><tr><th>Tgl</th><th>Kode</th><th>Keterangan</th><th>Akun</th><th>Kategori</th><th class="text-end">Masuk</th><th class="text-end">Keluar</th><th class="text-end">Saldo</th></tr></thead>
    <tbody>
    @foreach($entries as $e)
        <tr>
            <td>{{ optional($e->tanggal)->format('d/m/y') }}</td>
            <td>{{ $e->kode }}</td>
            <td>{{ $e->keterangan }}</td>
            <td>{{ $e->account?->name ?? '-' }}</td>
            <td>{{ $e->category?->name ?? '-' }}</td>
            <td class="text-end">{{ $e->isPemasukan() ? $rp($e->amount) : '' }}</td>
            <td class="text-end">{{ ! $e->isPemasukan() ? $rp($e->amount) : '' }}</td>
            <td class="text-end">{{ $rp($e->saldo ?? 0) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
</body></html>
