<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#222}
h1{font-size:18px;margin:0 0 4px}
table{width:100%;border-collapse:collapse;margin-top:8px}
th,td{border:1px solid #ccc;padding:4px 6px;text-align:left}
.right{text-align:right}.muted{color:#666}
</style></head><body>
    <h1>Laporan Order Selesai (Lunas)</h1>
    <div class="muted">{{ $kpi['jumlah'] }} order &middot; Total nilai: Rp {{ number_format($kpi['nilai'], 0, ',', '.') }}</div>
    <table>
        <thead><tr><th>Kode Order</th><th>Klien</th><th class="right">Nilai</th><th>Tanggal Lunas</th></tr></thead>
        <tbody>
            @foreach($detail as $o)
            <tr>
                <td>{{ $o->code_order }}</td>
                <td>{{ optional($o->details)->title ?? '-' }}</td>
                <td class="right">{{ number_format($o->nilai, 0, ',', '.') }}</td>
                <td>{{ optional($o->tanggal_lunas)->format('d/m/Y') ?? '-' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body></html>
