@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp
<p>Yth. {{ $slip->employee_name }},</p>
<p>Berikut kami sampaikan <strong>slip gaji</strong> Anda untuk periode <strong>{{ $data['periodLabel'] }}</strong>.</p>
<ul>
    <li>No. Slip: {{ $slip->slip_no }}</li>
    <li>Total Penghasilan: {{ $rp($data['totalEarn']) }}</li>
    <li>Total Potongan: {{ $rp($data['totalDed']) }}</li>
    <li><strong>Gaji Bersih (Take Home Pay): {{ $rp($data['netPay']) }}</strong></li>
</ul>
<p>Rincian lengkap ada pada slip gaji terlampir (PDF).</p>
<p>Dokumen ini bersifat rahasia dan hanya ditujukan untuk Anda.</p>
<p>Terima kasih.</p>
