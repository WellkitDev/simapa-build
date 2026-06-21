<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#222}
h1{font-size:18px;margin:0 0 4px}
table{width:100%;border-collapse:collapse;margin-top:8px}
th,td{border:1px solid #ccc;padding:4px 6px;text-align:left}
.right{text-align:right}.muted{color:#666}
</style></head><body>
    <h1>Laporan Piutang (Outstanding)</h1>
    <div class="muted">Nilai: Rp {{ number_format($kpi['nilai'], 0, ',', '.') }} &middot; Dibayar: Rp {{ number_format($kpi['dibayar'], 0, ',', '.') }} &middot; Sisa: Rp {{ number_format($kpi['sisa'], 0, ',', '.') }}</div>
    <table>
        <thead><tr><th>Kode Order</th><th>Klien</th><th class="right">Nilai</th><th class="right">Sudah Bayar</th><th class="right">Sisa</th><th>Status</th></tr></thead>
        <tbody>
            @foreach($detail as $o)
            <tr>
                <td>{{ $o->code_order }}</td>
                <td>{{ optional($o->details)->title ?? '-' }}</td>
                <td class="right">{{ number_format($o->nilai, 0, ',', '.') }}</td>
                <td class="right">{{ number_format($o->paid_amount, 0, ',', '.') }}</td>
                <td class="right">{{ number_format($o->sisa, 0, ',', '.') }}</td>
                <td>{{ ucfirst($o->status) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body></html>
