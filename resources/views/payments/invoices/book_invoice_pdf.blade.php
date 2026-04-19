<!DOCTYPE html>
<html lang="id">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <title>Invoice {{ $invoice->invoice_no }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
            color: #333;
            line-height: 1.5;
            padding: 40px;
            position: relative;
        }

        .background-logo {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 55%;
            opacity: 0.15;
            z-index: -1;
        }

        h2,
        h4 {
            color: #003366;
            margin-bottom: 8px;
        }

        .header {
            width: 100%;
            border-bottom: 3px solid #003366;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .header img {
            height: 40px;
        }

        .company-info p {
            margin: 4px 0;
        }

        .company-info b {
            font-size: 18px;
            color: #003366;
        }

        .invoice-info {
            text-align: right;
        }

        .invoice-info h2 {
            font-size: 28px;
            margin-bottom: 5px;
        }

        .info-table {
            width: 100%;
            margin: 25px 0;
        }

        .info-table td {
            vertical-align: top;
            padding: 5px 0;
        }

        table.detail {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .detail th,
        .detail td {
            border: 1px solid #ccc;
            padding: 5px 5px;
            text-align: left;
        }

        .detail th {
            background-color: #f0f4f8;
            color: #003366;
            font-weight: bold;
        }

        .detail .text-right {
            text-align: right;
        }

        .total-table {
            width: 45%;
            float: right;
            margin-top: 30px;
            border-collapse: collapse;
            /*background-color: #f9fbfc;*/
        }

        .total-table td {
            padding: 10px;
            border-bottom: 1px solid #ddd;
        }

        .total-table tr:last-child td {
            border-bottom: none;
            font-weight: bold;
            font-size: 14px;
        }

        .total-table .label {
            color: #555;
            width: 60%;
        }

        .total-table .value {
            text-align: right;
        }

        .status-lunas {
            color: #28a745;
            font-weight: bold;
        }

        .status-tagihan {
            color: #dc3545;
            font-weight: bold;
        }

        .clear {
            clear: both;
        }

        .signature {
            margin-top: 70px;
            text-align: right;
            width: 300px;
            float: right;
        }

        .signature img {
            width: 100px;
            margin: 15px 0;
            opacity: 0.9;
        }

        .notes {
            margin-top: 50px;
            font-size: 11px;
            line-height: 1.6;
        }

        .notes ul {
            margin: 8px 0 15px 20px;
        }

        .footer {
            margin-top: 40px;
            padding-top: 10px;
            border-top: 1px solid #ccc;
            text-align: center;
            font-size: 10px;
            color: #666;
        }
    </style>
</head>

<body>

    <!-- Background logo subtle -->
    <img class="background-logo" src="{{ public_path('assets/images/bg-pdf.png') }}" alt="Background">

    <!-- Header -->
    <table class="header" width="100%">
        <tr>
            <td width="50%" valign="top">
                <img src="{{ public_path('assets/images/logo-sm.png') }}" alt="Logo Avidpedia">
                <div class="company-info">
                    <p><b>AVIDPEDIA PUBLISHING</b></p>
                    <p>Jasa Layanan Publikasi Buku & Artikel Ilmiah</p>
                    <p>Simpang III Sipin, Kota Baru, Jambi, 36126</p>
                    <p>+62 851-5842-2426 | contact@avidpedia.com</p>
                </div>
            </td>
            <td width="50%" class="invoice-info" valign="bottom">
                <h2>INVOICE</h2>
                <p><strong>#{{ $invoice->invoice_no }}</strong></p>
                <p>Issue: {{ \Carbon\Carbon::parse($order->ordered_at)->format('d F Y') }}</p>
                <p>Expired: {{ \Carbon\Carbon::parse($invoice->due_at)->format('d F Y') }}</p>
            </td>
        </tr>
    </table>

    <!-- Informasi Pelanggan & Total Cepat -->
    <table class="info-table">
        <tr>
            <td width="55%">
                <h4>Kepada Yth.</h4>
                <p>
                    @if ($detail && $detail->authors->isNotEmpty())
                        {{ Str::title($detail->authors->first()->name) }}@if ($detail->authors->count() > 1)
                            , dkk.
                        @endif
                        <br>
                        {{ Str::title($detail->authors->first()->affiliation) ?? '-' }}<br>
                        {{ $order->contact->cp_email ?? '-' }}<br>
                        {{ $order->contact->cp_phone ?? '-' }}
                    @else
                        {{ $order->contact->cp_email ?? 'Pelanggan' }}
                    @endif
                </p>
            </td>
            <td width="45%" style="text-align: right;">
                <h4>Metode Pembayaran:</h4>
                <p>
                    Transfer Bank: BNI<br>
                    PT. AVID MEDIA INDONESIA<br>
                    <b>2017627745</b>
                </p>
                <h4>Total Tagihan</h4>
                <h5 style="color:#003366; margin:0;">Rp {{ number_format($totalCost, 0, ',', '.') }}</h5>
                <h4 style="margin-top:10px;">Status</h4>
                <h5 style="color:{{ $remainingBalance <= 0 ? '#28a745' : '#dc3545' }}; margin:0;">
                    {{ $remainingBalance <= 0 ? 'LUNAS' : 'MENUNGGU PELUNASAN' }}
                </h5>
            </td>
        </tr>
    </table>

    <!-- Detail Order -->
    <h4>Detail Order</h4>
    <table class="detail">
        <tr>
            <td width="30%"><strong>Jenis Layanan</strong></td>
            <td>:@switch($detail->type)
                    @case('bk_mandiri')
                        Buku Mandiri
                    @break

                    @case('bk_kolab')
                        Buku Kolaborasi
                    @break

                    @case('at_mandiri')
                        Artikel Mandiri
                    @break

                    @case('at_kolab')
                        Artikel Kolaborasi
                    @break
                @endswitch
                (Naskah {{ $detail->naskah_type === 'dibuatkan' ? 'Dibuatkan' : 'Mandiri' }})</td>
        </tr>
        <tr>
            <td><strong>Judul</strong></td>
            <td>: {{ Str::title($detail->title) ?? '-' }}</td>
        </tr>
        <tr>
            <td><strong>Scope</strong></td>
            <td>: {{ $detail->scopes->pluck('scope')->implode(' / ') }}</td>
        </tr>
        @if (str_starts_with($detail->type, 'at_'))
            @if ($detail->indexation)
                <tr>
                    <td><strong>Target Indeksasi</strong></td>
                    <td>: {{ strtoupper($detail->indexation) }}</td>
                </tr>
            @endif
        @else
            <tr>
                <td><strong>{{ $detail->type === 'bk_mandiri' ? 'Jumlah Bab' : 'Bab Order' }}</strong></td>
                <td>: {{ $detail->chapters ?? 0 }}</td>
            </tr>
        @endif
        <tr>
            <td><strong>Penulis</strong></td>
            <td>:
                <ol style="margin:5px 0 0 0; padding-left:20px;">
                    @foreach ($detail->authors as $author)
                        <li>{{ Str::title($author->name) }} ({{ Str::title($author->affiliation) ?? '-' }})</li>
                    @endforeach
                </ol>
            </td>
        </tr>
    </table>

    <!-- Rincian Biaya -->
    <h4 style="margin-top:30px;">Rincian Biaya</h4>
    <table class="detail">
        <tr>
            <td width="70%">Biaya Publikasi {{ $detail->type ?? 'Buku' }}</td>
            <td class="text-right">Rp {{ number_format($totalCost, 0, ',', '.') }}</td>
        </tr>
        <tr style="background-color:#f0f4f8; font-weight:bold;">
            <td>Total Tagihan</td>
            <td class="text-right">Rp {{ number_format($totalCost, 0, ',', '.') }}</td>
        </tr>
    </table>

    <!-- Riwayat Pembayaran -->
    <h4 style="margin-top:30px;">Riwayat Pembayaran</h4>
    <table class="detail">
        <thead>
            <tr>
                <th width="5%">No</th>
                <th width="25%">Tanggal</th>
                <th width="30%">Jenis</th>
                <th width="25%" class="text-right">Jumlah</th>
                <th width="15%"class="text-right">Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->payments as $index => $payment)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ \Carbon\Carbon::parse($payment->paid_at)->format('d M Y') }}</td>
                    <td>{{ strtoupper($payment->payment_type) }}</td>
                    <td class="text-right">Rp {{ number_format($payment->amount, 0, ',', '.') }}</td>
                    <td class="text-right">Terbayar</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <!-- Total Ringkasan -->
    <table class="total-table">
        <tr>
            <td class="label">Total Tagihan</td>
            <td class="value">Rp {{ number_format($totalCost, 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td class="label">Total Terbayar</td>
            <td class="value">Rp {{ number_format($totalCost - $remainingBalance, 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td class="label">Sisa Tagihan</td>
            <td class="value {{ $remainingBalance <= 0 ? 'status-lunas' : 'status-tagihan' }}">
                Rp {{ number_format($remainingBalance, 0, ',', '.') }}
            </td>
        </tr>
        <tr>
            <td class="label">Status Invoice</td>
            <td class="value {{ $remainingBalance <= 0 ? 'status-lunas' : 'status-tagihan' }}">
                {{ $remainingBalance <= 0 ? 'LUNAS' : 'MENUNGGU PELUNASAN' }}
            </td>
        </tr>
    </table>
    <div class="clear"></div>

    <!-- Tanda Tangan -->
    <div class="signature">
        <p>Jambi, {{ now()->format('d F Y') }}</p>
        <img src="{{ public_path('assets/images/ttd.png') }}" alt="Tanda tangan">
        <p><b><strong>PT AVID MEDIA INDONESIA</strong></b></p>
    </div>
    <div class="clear"></div>

    <!-- Catatan -->
    <div class="notes">
        <h4>Informasi Penting:</h4>
        <ul>
            <li>Bukti pembayaran silakan kirim ke WhatsApp Admin: <strong>+62 851-5842-2426</strong></li>
            <li>Pembayaran hanya ke rekening atas nama perusahaan.</li>
            <li>Invoice ini sah secara digital tanpa tanda tangan basah.</li>
            <li>Terima kasih atas kepercayaan Anda kepada Avidpedia Publishing!</li>
        </ul>
    </div>

    <div class="footer">
        <p>Terima kasih telah mempercayakan publikasi Anda kepada Avidpedia Publishing &mdash; <a
                href="https://avidpedia.com">www.avidpedia.com</a></p>
        <p>Dokumen ini dibuat secara otomatis pada {{ now()->format('d/m/Y H:i') }} WIB</p>
    </div>

</body>

</html>
