<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans, sans-serif;font-size:12px;color:#222}
h2{margin:0 0 2px}.muted{color:#666}
.box{border:1px solid #ccc;padding:10px;margin-top:12px}
table{width:100%;border-collapse:collapse;margin-top:6px}
th,td{border:1px solid #ccc;padding:4px 6px}th{background:#f0f0f0;text-align:left}
.text-end{text-align:right}.lbl{color:#666;width:150px;border:0}.big{font-size:15px;font-weight:bold}
.plain td{border:0;padding:3px 6px}
</style></head><body>
@php
    $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
    $ptype = ['dp' => 'DP', 'lunas' => 'Lunas', 'pelunasan' => 'Pelunasan', 'refund' => 'Refund'];
@endphp
<h2>BUKTI REFUND</h2>
<div class="muted">Order: {{ optional($order)->code_order ?? '-' }} · Tanggal Refund: {{ optional($refunded_at)->format('d/m/Y') }}</div>

<table class="plain" style="margin-top:10px">
    <tr><td class="lbl">Customer</td><td>{{ optional($contact)->cp_name ?? '-' }}</td></tr>
    <tr><td class="lbl">Judul</td><td>{{ optional($detail)->title ?? '-' }}</td></tr>
</table>

<div style="margin-top:12px;font-weight:bold">Riwayat Pembayaran</div>
<table>
    <thead><tr><th>Tanggal</th><th>Jenis</th><th class="text-end">Nominal</th><th>Status</th></tr></thead>
    <tbody>
    @foreach($payments as $p)
        <tr>
            <td>{{ optional($p->paid_at)->format('d/m/Y') }}</td>
            <td>{{ $ptype[$p->payment_type] ?? $p->payment_type }}</td>
            <td class="text-end">{{ $p->payment_type === 'refund' ? '-' . $rp($p->amount) : $rp($p->amount) }}</td>
            <td>{{ $p->status }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="plain" style="margin-top:10px">
    <tr><td class="lbl">Total Dibayar</td><td>{{ $rp($paidIn) }}</td></tr>
    <tr><td class="lbl">Nominal Refund</td><td class="big">{{ $rp($refundAmount) }}</td></tr>
    <tr><td class="lbl">Sisa Setelah Refund</td><td>{{ $rp($paidIn - $refundAmount) }}</td></tr>
</table>

<div class="box">
    <table class="plain">
        <tr><td class="lbl">Metode</td><td>{{ $method ?? '-' }}</td></tr>
        <tr><td class="lbl">Rekening/Tujuan</td><td>{{ $account ?? '-' }}</td></tr>
        <tr><td class="lbl">Alasan</td><td>{{ $reason ?? '-' }}</td></tr>
    </table>
</div>
<p style="margin-top:40px">Hormat kami,<br><br><br>Avidpedia</p>
</body></html>
