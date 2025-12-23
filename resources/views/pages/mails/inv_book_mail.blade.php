<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #INV-XXXXXX</title>
    <link rel="shortcut icon" href="{{ asset('assets/images/favicon.png') }}">
</head>

<body style="margin:0; padding:0; font-family:Arial, Helvetica, sans-serif; background-color:#f4f4f4;">
    <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%"
        style="max-width:600px; background-color:#ffffff; border:1px solid #e0e0e0; margin:20px auto;">

        <!-- Header -->
        <tr>
            <td style="padding:10px; text-align:center; background-color:#055eb6;">
                <img src="{{ asset('assets/images/logo-sm-white.png') }}" alt="Avidpedia" style="width:50px;">
                <p style="font-size:12px; color:#ffffff; margin:8px 0 0;">
                    <b>AVIDPEDIA PUBLISHER</b><br>
                    +62 851-5842-2426 | Avidpedia@gmail.com
                </p>
            </td>
        </tr>

        <!-- Content -->
        <tr>
            <td style="padding:30px;">
                <h1 style="color:#333333; font-size:24px; margin:0 0 20px; text-align:center;">
                    Invoice #{{ $invoice->inv_no }}
                </h1>

                <p style="color:#555555; font-size:16px; line-height:1.6; margin:0 0 20px; text-align:center;">
                    Terima kasih telah melakukan pemesanan dengan <strong>Avidpedia</strong>!<br>
                    Berikut adalah ringkasan invoice Anda:
                </p>

                <!-- Detail Invoice -->
                <table border="0" cellpadding="0" cellspacing="0" style="width:100%; margin-bottom:20px;">
                    <tr>
                        <td style="padding:8px 0; color:#555;"><strong>INVOICE :</strong></td>
                        <td style="padding:8px 0; color:#555;">{{ $invoice->inv_no }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0; color:#555;"><strong>Judul Buku</strong></td>
                        <td style="padding:8px 0; color:#555;">{{ $order->title }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0; color:#555;"><strong>Total Biaya</strong></td>
                        <td style="padding:8px 0; color:#555;">Rp {{ number_format($order->cost_amount, 0, ',', '.') }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0; color:#555;"><strong>Jumlah Dibayar</strong></td>
                        <td style="padding:8px 0; color:#555;">Rp
                            {{ number_format($order->pay_amount, 0, ',', '.') }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0; color:#555;"><strong>Sisa Bayar</strong></td>
                        <td style="padding:8px 0; color:#555;">Rp
                            {{ number_format($order->debit_amount, 0, ',', '.') }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0; color:#555;"><strong>Tanggal Terbit</strong></td>
                        <td style="padding:8px 0; color:#555;">{{ $invoice->issued_at->format('d F Y') }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0; color:#555;"><strong>Jatuh Tempo</strong></td>
                        <td style="padding:8px 0; color:#555;">{{ $invoice->dued_at->format('d F Y') }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0; color:#555;"><strong>Status Pembayaran</strong></td>
                        <td style="padding:8px 0; color:#555;">
                            {{ $order->pay_amount >= $order->cost_amount ? 'LUNAS' : 'DP' }}</td>
                    </tr>
                </table>

                <p style="color:#555555; font-size:16px; line-height:1.5; margin:0 0 20px;">
                    File invoice lengkap dapat diunduh di link berikut: <a href="{{ $invoice->inv_pdf_url }}"
                        class="bold">
                        {{ $invoice->inv_pdf_url }}
                    </a>.
                    Silakan periksauntuk detail lebih lanjut.
                </p>

                <!-- CTA -->
                <p style="text-align:center; margin:30px 0;">
                    <a href="https://wa.me/6285158422426"
                        style="display:inline-block; padding:12px 24px; background-color:#003366; color:#ffffff;
                          text-decoration:none; font-size:16px; border-radius:5px;">
                        Hubungi Admin
                    </a>
                </p>
            </td>
        </tr>

        <!-- Footer -->
        <tr>
            <td
                style="padding:20px; text-align:center; background-color:#f8f8f8;
                   color:#777777; font-size:14px;">
                <p style="margin:0;">
                    Jika Anda memiliki pertanyaan, silakan hubungi kami di
                    <a href="mailto:avidpedia@gmail.com" style="color:#007bff; text-decoration:none;">
                        avidpedia@gmail.com
                    </a>
                    atau
                    <a href="mailto:admin@avidpedia.com" style="color:#007bff; text-decoration:none;">
                        admin@avidpedia.com
                    </a>
                </p>
                <p style="margin:5px 0;">© 2025 SiMAPA. All rights reserved.</p>
            </td>
        </tr>

    </table>
</body>

</html>
