<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans, sans-serif;font-size:12px;color:#222}
h2{margin:0 0 2px}.muted{color:#666}
.box{border:1px solid #ccc;padding:12px;margin-top:12px}
table{width:100%;border-collapse:collapse}
td{padding:4px 6px;vertical-align:top}.lbl{color:#666;width:160px}.big{font-size:16px;font-weight:bold}
</style></head><body>
@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp
<h2>BUKTI REFUND</h2>
<div class="muted">No. Invoice: {{ $invoice->invoice_no }} · Tanggal: {{ optional($refunded_at)->format('d/m/Y') }}</div>
<div class="box"><table>
    <tr><td class="lbl">Customer</td><td>{{ optional($contact)->cp_name ?? '-' }}</td></tr>
    <tr><td class="lbl">Order</td><td>{{ optional($order)->code_order ?? '-' }}</td></tr>
    <tr><td class="lbl">Judul</td><td>{{ optional($detail)->title ?? '-' }}</td></tr>
    <tr><td class="lbl">Nominal Refund</td><td class="big">{{ $rp($amount) }}</td></tr>
    <tr><td class="lbl">Metode</td><td>{{ $method ?? '-' }}</td></tr>
    <tr><td class="lbl">Tujuan</td><td>{{ $account ?? '-' }}</td></tr>
    <tr><td class="lbl">Alasan</td><td>{{ $reason ?? '-' }}</td></tr>
</table></div>
<p style="margin-top:40px">Hormat kami,<br><br><br>Avidpedia</p>
</body></html>
