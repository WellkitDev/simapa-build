<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#222}
h1{font-size:18px;margin:0 0 4px}
table{width:100%;border-collapse:collapse;margin-top:8px}
th,td{border:1px solid #ccc;padding:4px 6px;text-align:left}
.right{text-align:right}.muted{color:#666}
</style></head><body>
    <h1>Laporan Pemasukan (Kas Masuk)</h1>
    <div class="muted">Total tahun ini: Rp {{ number_format($kpi['total'], 0, ',', '.') }} &middot; {{ $kpi['pembayaran'] }} pembayaran &middot; {{ $kpi['order'] }} order</div>
    <table>
        <thead><tr><th>Tanggal</th><th>Kode Order</th><th>Klien</th><th>Tipe</th><th class="right">Nominal</th><th>No Invoice</th></tr></thead>
        <tbody>
            @foreach($detail as $p)
            <tr>
                <td>{{ optional($p->paid_at)->format('d/m/Y') ?? '-' }}</td>
                <td>{{ optional($p->order)->code_order }}</td>
                <td>{{ optional(optional($p->order)->details)->title ?? '-' }}</td>
                <td>{{ ucfirst($p->payment_type) }}</td>
                <td class="right">{{ number_format($p->amount, 0, ',', '.') }}</td>
                <td>{{ optional($p->invoice)->invoice_no ?? '-' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body></html>
