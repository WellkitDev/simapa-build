<!DOCTYPE html>
<html lang="en">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport"
        content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Invoice</title>
    <style>
        * {
            margin: 0;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            color: #333;
            margin: 40px;
        }

        .background-logo {
            position: fixed;
            top: 50%;
            left: 50%;
            width: 50%;
            /* ukuran rekomendasi */
            transform: translate(-50%, -50%);
            opacity: 0.25;
            /* 35% transparansi */
            z-index: -1;
        }

        h2 {
            color: #003366;
        }

        .header {
            border-bottom: 2px solid #333;
        }

        .header>tr>td>img {
            width: 35px;
            margin-bottom: 10px;
        }

        .company-info {
            margin-top: 5px;
        }

        .company-info>p>b {
            font-size: 16px;
            color: #003366;
        }

        /* MAIN CONTENT */
        .info-table {
            width: 100%;
            margin-top: 20px;
            margin-bottom: 25px;
        }

        .info-table td {
            vertical-align: top;
            padding: 5px 0;
        }

        /* TABLE DETAIL */
        table.detail {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .detail th,
        .detail td {
            border: 1px solid #ccc;
            padding: 8px;
        }

        .detail th {
            background-color: #f4f6f9;
            color: #003366;
            text-align: left;
        }

        .detail td {
            text-align: left;
        }

        /* TOTAL SECTION */
        .total-table {
            width: 45%;
            float: right;
            margin-top: 25px;
            border-collapse: collapse;
        }

        .total-table td {
            padding: 6px;
            border-bottom: 1px solid #ddd;
        }

        .total-table tr:last-child td {
            border-bottom: none;
        }

        .total-table .label {
            color: #555;
        }

        .total-table .value {
            text-align: right;
            font-weight: bold;
        }

        /* SIGNATURE */
        .signature {
            margin-top: 60px;
            text-align: right;
        }

        .signature img {
            width: 60px;
            opacity: 0.8;
            margin-bottom: 10px;
            margin-top: 10px;
        }

        .mt-2 {
            margin-top: 20px;
        }

        /* FOOTER */
        /* .footer {
            position: fixed;
            bottom: -30px;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 10px;
            color: #666;
            border-top: 1px solid #ccc;
            padding-top: 5px;
        } */
        .footer {
            text-align: center;
            font-size: 10px;
            margin-top: 20px;
            border-top: 1px solid #ccc;
            padding-top: 8px;
            color: #666;
        }

        /* NOTES */
        .notes {
            margin-top: 40px;
            font-size: 11px;
            line-height: 1.5;
        }

        .notes h4 {
            color: #003366;
            margin-bottom: 5px;
        }

        .notes ul {
            margin: 5px 0 0 15px;
            padding-left: 5px;
        }
    </style>
</head>

<body>
    <img class="background-logo" src="{{ public_path('assets/images/bg-pdf.png') }}" alt="bg">
    <table class="header" width="100%">
        <tr>
            <td width="50%">
                <img src="{{ public_path('assets/images/logo-sm.png') }}" alt="Logo">
                <div class="company-info">
                    <p><b>AVIDPEDIA PUBLISHER</b><br>
                        Simpang III Sipin, Kota Baru, Jambi, 36126<br>
                        +62 851-5842-2426 | Avidpedia@gmail.com</p>
                </div>
            </td>
            <td width="50%" style="text-align: right; vertical-align: bottom;">
                <h2>Invoice</h2>
                <p><b>#{{ $invoice->inv_no }}</b></p>
                <p>Terbit: {{ $invoice->issued_at->format('d F Y') }}</p>
                <p>Jatuh Tempo: {{ $invoice->dued_at->format('d F Y') }}</p>
            </td>
        </tr>
    </table>
    <!-- INFORMASI CLIENT -->
    <table class="info-table">
        <tr>
            <td width="50%">
                <h4>Kepada Yth.</h4>
                <p>
                    {{ $order->authors->first()->name }}
                    @if ($order->authors->count() > 1)
                        , dkk.
                    @endif
                    <br>
                    {{ $order->contact_email }}<br>
                    {{ $order->contact_phone }}
                </p>
            </td>
            <td width="50%" style="text-align:right;">
                <h4>Metode Pembayaran:</h4>
                <p>
                    Transfer Bank: BRI<br>
                    Rahmat Purnomo<br>
                    <b>00898997767888</b>
                </p>
                <h4>Total Biaya:</h4>
                <h3 style="color:#003366;">Rp {{ number_format($order->cost_amount, 0, ',', '.') }}</h3>
                <h3 style="color:#029433;">Status:
                    {{ $order->pay_amount >= $order->cost_amount ? 'LUNAS' : 'DP' }}</h3>
            </td>
        </tr>
    </table>
    <!-- TABEL DETAIL -->
    <h4>Detail Order</h4>
    <table>
        <tr>
            <td width="30%"><strong>Jenis Layanan</strong></td>
            <td>:
                @switch($order->type)
                    @case('bk_mandiri')
                        Buku Mandiri
                    @break

                    @case('bk_kolab')
                        Buku Kolaborasi
                    @break

                    @case('at_mandiri')
                        Artikel Jurnal Mandiri
                    @break

                    @case('at_kolab')
                        Artikel Jurnal Kolaborasi
                    @break
                @endswitch
                (Naskah {{ $order->naskah_type === 'dibuatkan' ? 'Dibuatkan' : 'Mandiri' }})
            </td>
        </tr>
        <tr>
            <td><strong>Judul</strong></td>
            <td>: {{ $order->title }}</td>
        </tr>

        @if (str_starts_with($order->type, 'bk_'))
            <tr>
                <td><strong>
                        @if ($order->type == 'bk_kolab' ? 'Order Bab' : 'Jumlah Bab')
                        @endif
                    </strong></td>
                <td>: {{ $order->chapters }} Bab</td>
            </tr>
        @endif

        @if (str_starts_with($order->type, 'at_'))
            @if ($order->scope_id && $order->scope)
                <tr>
                    <td><strong>Scope</strong></td>
                    <td>: {{ $order->scope->scope }}</td>
                </tr>
            @endif
            @if ($order->indexation)
                <tr>
                    <td><strong>Target Indeksasi</strong></td>
                    <td>: {{ $order->indexation }}</td>
                </tr>
            @endif
            <tr>
                <td><strong>Jumlah Penulis</strong></td>
                <td>: {{ $order->count_authors }} orang</td>
            </tr>
        @endif

        <tr>
            <td><strong>Penulis</strong></td>
            <td>:
                <ol style="margin:0; padding-left:20px;">
                    @foreach ($order->authors->sortBy('pivot.possition') as $author)
                        <li>{{ $author->name }}@if ($author->affiliation)
                                ({{ $author->affiliation }})
                            @endif
                        </li>
                    @endforeach
                </ol>
            </td>
        </tr>



        @if ($order->authors->first()->phone || $order->authors->first()->email)
            <tr>
                <td><strong>Kontak</strong></td>
                <td>:
                    @if ($order->authors->first()->phone)
                        {{ $order->authors->first()->phone }}
                    @endif
                    @if ($order->authors->first()->email)
                        | {{ $order->authors->first()->email }}
                    @endif
                </td>
            </tr>
        @endif
    </table>
    <!-- Riwayat Pembayaran (persis rancanganmu) -->
    <h4 class="mt-2">Riwayat Pembayaran</h4>
    <table class="class= detail">
        <thead class="bg-light">
            <tr>
                <th width="5%">No.</th>
                <th width="25%">Tanggal</th>
                <th width="30%">Jenis Pembayaran</th>
                <th width="25%" class="text-right">Jumlah</th>
                <th width="15%">Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->payments as $i => $payment)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $payment->date->format('d F Y') }}</td>
                    <td>{{ ucfirst($payment->type) }}</td>
                    <td class="text-right">Rp {{ number_format($payment->amount, 0, ',', '.') }}</td>
                    <td>
                        @if ($payment->amount >= $order->cost_amount)
                            <span class="status-lunas">Lunas</span>
                        @else
                            Terbayar
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <!-- TOTAL -->
    <table class="total-table">
        <tr>
            <td class="label">Total Biaya</td>
            <td class="value">Rp {{ number_format($order->cost_amount, 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td class="label">Total Pembayaran</td>
            <td class="value">Rp {{ number_format($order->pay_amount, 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td class="label">Sisa Pembayaran</td>
            <td class="value">Rp {{ number_format($order->debit_amount, 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td class="label">Status Pembayaran</td>
            <td class="value" style="color:{{ $order->pay_amount >= $order->cost_amount ? 'green' : 'red' }}">
                {{ $order->pay_amount >= $order->cost_amount ? 'LUNAS' : 'DP' }}
            </td>
        </tr>
    </table>
    <div style="clear: both;"></div>
    <!-- TANDA TANGAN -->
    <div class="signature">
        <p>Jambi, {{ $invoice->created_at->format('d F Y') }}</p>
        <img src="{{ public_path('assets/images/ttd.png') }}" alt="Tanda tangan">
        <p><b>Eric Krisna Sandi</b></p>
    </div>
    <!-- CATATAN -->
    <div class="notes">
        <h4>Informasi & Kebijakan:</h4>
        <ul>
            <li>Bukti pembayaran dikirim ke WhatsApp <a
                    href="http://wa.me/{{ $order->user->profile->phone_number ?? '6285158422426' }}">{{ $order->user->profile->phone_number ?? '6285158422426' }}</a>
            </li>
            <li>Komplain via WhatsApp/email yang tercantum di atas atau melalui marketing kami.</li>
            <li>Refund diproses maksimal 30 hari kalender setelah disetujui.</li>
            <li>DP wajib dilunasi sebelum tanggal jatuh tempo.</li>
            <li>Pembayaran hanya ke rekening atas nama PT Avidpedia Publisher.</li>
            <li>Invoice dikirim otomatis via WhatsApp & email.</li>
            <li>Kerahasiaan naskah dijamin sepenuhnya.</li>
        </ul>
        <h4>kontak WhatsApp lainnya:</h4>
        <ul>
            <li>Admin: <a href="http://wa.me/6285158422426">+62-851-5842-2426</a></li>
            <li>Admin: <a href="http://wa.me/6288905858743">+62-889-0585-8743</a></li>
            {{-- <li>Admin: <a href="http://wa.me/6282279814793">+62-822-7981-4793</a></li> --}}
        </ul>
    </div>

    <div class="footer">
        <p>Terima kasih telah bekerja sama dengan Avidpedia Publisher. &mdash; <a
                href="https://avidpedia.com">www.Avidpedia.com</a></p>
        <p>Dokumen ini dibuat atau dicetak otomasis pada {{ now()->format('d/m/Y H:i') }} dan sah tanpa tanda tangan
            basah.</p>
    </div>
</body>

</html>
