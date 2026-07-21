<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans, sans-serif;font-size:12px;color:#222}
h2{margin:0 0 2px}.muted{color:#666}
.box{border:1px solid #ccc;padding:10px;margin-top:12px}
table{width:100%;border-collapse:collapse;margin-top:6px}
th,td{border:1px solid #ccc;padding:4px 6px}th{background:#f0f0f0;text-align:left}
.text-end{text-align:right}.text-center{text-align:center}
.lbl{color:#666;width:180px;border:0}.big{font-size:16px;font-weight:bold}
.plain td{border:0;padding:3px 6px}
.sub td{background:#fafafa;font-weight:bold}
.thp{border:2px solid #333;padding:10px;margin-top:14px}
</style></head><body>
@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp

<h2>SLIP GAJI KARYAWAN</h2>
<div class="muted">Avidpedia &middot; Periode: {{ $periodLabel }} &middot; No. Slip: {{ $slip->slip_no }}</div>

<table class="plain" style="margin-top:10px">
    <tr><td class="lbl">Nama</td><td>{{ $slip->employee_name }}</td></tr>
    <tr><td class="lbl">Jabatan</td><td>{{ $slip->employee_position ?? '-' }}</td></tr>
    <tr><td class="lbl">Periode</td><td>{{ $periodLabel }}</td></tr>
</table>

<div style="margin-top:12px;font-weight:bold">Rincian Penghasilan</div>
<table>
    <thead><tr><th>Komponen</th><th class="text-end">Nominal</th></tr></thead>
    <tbody>
    @forelse($earnings as $e)
        <tr><td>{{ $e->label }}</td><td class="text-end">{{ $rp($e->amount) }}</td></tr>
    @empty
        <tr><td colspan="2" class="muted">Tidak ada.</td></tr>
    @endforelse
        <tr class="sub"><td>Subtotal Penghasilan</td><td class="text-end">{{ $rp($totalEarn) }}</td></tr>
    </tbody>
</table>

<div style="margin-top:12px;font-weight:bold">Rincian Potongan</div>
<table>
    <thead><tr><th>Komponen</th><th class="text-end">Nominal</th></tr></thead>
    <tbody>
    @forelse($deductions as $d)
        <tr><td>{{ $d->label }}</td><td class="text-end">{{ $rp($d->amount) }}</td></tr>
    @empty
        <tr><td colspan="2" class="muted">Tidak ada.</td></tr>
    @endforelse
        <tr class="sub"><td>Subtotal Potongan</td><td class="text-end">{{ $rp($totalDed) }}</td></tr>
    </tbody>
</table>

<div class="thp">
    <table class="plain">
        <tr><td class="lbl">GAJI BERSIH / TAKE HOME PAY</td><td class="big">{{ $rp($netPay) }}</td></tr>
        <tr><td class="lbl">Terbilang</td><td><em>{{ $terbilang }}</em></td></tr>
    </table>
</div>

@if($slip->note)
<div class="box"><strong>Catatan:</strong> {{ $slip->note }}</div>
@endif

<table class="plain" style="margin-top:40px">
    <tr>
        <td style="width:60%;border:0"></td>
        <td class="text-center" style="border:0">Hormat kami,<br><br><br>Bagian Keuangan<br>Avidpedia</td>
    </tr>
</table>
<p class="muted" style="margin-top:20px;font-size:10px">Dokumen ini bersifat rahasia dan hanya untuk karyawan bersangkutan.</p>
</body></html>
