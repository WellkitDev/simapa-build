@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp
<p>Yth. {{ optional($data['contact'])->cp_name ?? 'Pelanggan' }},</p>
<p>Kami telah memproses <strong>refund</strong> untuk pesanan Anda.</p>
<ul>
    <li>No. Invoice: {{ $invoice->invoice_no }}</li>
    <li>Order: {{ optional($data['order'])->code_order }}</li>
    <li>Nominal refund: <strong>{{ $rp($data['amount']) }}</strong></li>
    <li>Metode: {{ $data['method'] }}</li>
    @if($data['account'])<li>Tujuan: {{ $data['account'] }}</li>@endif
    <li>Alasan: {{ $data['reason'] }}</li>
</ul>
<p>Bukti refund terlampir (PDF).</p>
<p>Terima kasih.</p>
