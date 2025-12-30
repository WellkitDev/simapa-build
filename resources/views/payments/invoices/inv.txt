<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $invoice->invoice_no }}</title>
    <style>
        /* ... (Gunakan CSS yang Anda berikan tadi) ... */
        body {
            font-family: Arial, sans-serif;
            font-size: 12pt;
            line-height: 1.5;
            color: #333;
            margin: 40px;
        }

        .header,
        .footer {
            text-align: center;
            margin-bottom: 30px;
        }

        .header h1 {
            margin: 0;
            font-size: 20pt;
        }

        .header p {
            margin: 5px 0;
        }

        .separator {
            border-top: 2px solid #000;
            margin: 20px 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 8px;
            vertical-align: top;
        }

        .text-left {
            text-align: left;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .bold {
            font-weight: bold;
        }

        .status-lunas {
            color: green;
            font-weight: bold;
        }

        .status-pending {
            color: orange;
            font-weight: bold;
        }

        .mb-2 {
            margin-bottom: 0px;
        }

        .mt-2 {
            margin-top: 0px;
        }

        .status-lunas {
            color: #28a745;
            font-weight: bold;
        }

        .status-tagihan {
            color: #dc3545;
            font-weight: bold;
        }
    </style>
</head>

<body>

    <div class="header">
        <h1>AVIDPEDIA PUBLISHING</h1>
        <p>Jasa Layanan Publikasi Buku & Artikel Ilmiah</p>
        <p>Jl. Contoh No.123, Jakarta Selatan</p>
        <p>contact@avidpedia.com | 0812-3456-7890</p>
    </div>

    <div class="separator"></div>

    <p class="text-right">
        <strong>INVOICE : {{ $invoice->invoice_no }}</strong><br>
        Tanggal : {{ \Carbon\Carbon::parse($invoice->issued_at)->format('d F Y') }}
    </p>

    <div class="separator"></div>

    <p>
        <strong>Kepada Yth.</strong><br>
        @if ($detail && $detail->authors->isNotEmpty())
            {{ $detail->authors->first()->name }}, dkk.<br>
            {{ $detail->authors->first()->affiliation ?? '-' }}
        @else
            {{ $order->contact->cp_name ?? 'Pelanggan' }}
        @endif
    </p>

    <h3>Detail Order</h3>
    <div class="separator"></div>
    <table>
        <tr>
            <td width="30%"><strong>Jenis Layanan</strong></td>
            <td>: {{ $detail->type ?? 'Buku Mandiri' }} ({{ $detail->naskah_type ?? 'Naskah Mandiri' }})</td>
        </tr>
        <tr>
            <td><strong>Judul</strong></td>
            <td>: {{ $detail->title ?? '-' }}</td>
        </tr>
        <tr>
            <td><strong>Jumlah Bab</strong></td>
            <td>: {{ $detail->chapters ?? 0 }} Bab</td>
        </tr>
        <tr>
            <td><strong>Scope</strong></td>
            <td>: {{ $detail->scopes->pluck('scope')->implode(' / ') }}</td>
        </tr>
        <tr>
            <td><strong>Penulis</strong></td>
            <td>:
                <ol style="margin:0; padding-left:20px;">
                    @foreach ($detail->authors as $author)
                        <li>{{ $author->name }} ({{ $author->affiliation ?? '-' }})</li>
                    @endforeach
                </ol>
            </td>
        </tr>
    </table>

    <h3>Rincian Biaya</h3>
    <div class="separator"></div>
    <table>
        <tr>
            <td width="70%">Biaya Publikasi {{ $detail->type ?? 'Buku' }}</td>
            <td class="text-right">Rp {{ number_format($totalCost, 0, ',', '.') }}</td>
        </tr>
        <tr class="bold">
            <td>Total Tagihan</td>
            <td class="text-right">Rp {{ number_format($totalCost, 0, ',', '.') }}</td>
        </tr>
    </table>

    <h3>Riwayat Pembayaran</h3>
    <div class="separator"></div>
    <table>
        <thead>
            <tr>
                <th width="5%">No</th>
                <th width="25%">Tanggal</th>
                <th width="30%">Jenis</th>
                <th width="25%" class="text-right">Jumlah</th>
                <th width="15%">Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->payments as $index => $payment)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $payment->created_at->format('d M Y') }}</td>
                    <td>{{ strtoupper($payment->payment_type) }}</td>
                    <td class="text-right">Rp {{ number_format($payment->amount, 0, ',', '.') }}</td>
                    <td>Terbayar</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="bold">
        Sisa Tagihan :
        <span class="{{ $remainingBalance <= 0 ? 'status-lunas' : 'status-tagihan' }}">
            Rp {{ number_format($remainingBalance, 0, ',', '.') }}
        </span>
    </p>

    <p class="bold">
        Status Invoice :
        @if ($remainingBalance <= 0)
            <span class="status-lunas">LUNAS ✅</span>
        @else
            <span class="status-pending">MENUNGGU PELUNASAN</span>
        @endif
    </p>

    <div class="footer">
        <p>Terima kasih atas kepercayaan Anda kepada Avidpedia!</p>
    </div>

</body>

</html>
