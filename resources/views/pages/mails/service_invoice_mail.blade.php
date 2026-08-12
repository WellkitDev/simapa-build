<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice Layanan #{{ $invoice->invoice_no }}</title>
</head>

<body style="margin:0; padding:0; font-family:Arial, Helvetica, sans-serif; background-color:#f4f4f4;">
    <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%"
        style="max-width:600px; background-color:#ffffff; border:1px solid #e0e0e0; margin:20px auto;">

        <tr>
            <td style="padding:10px; text-align:center; background-color:#055eb6;">
                <img src="{{ asset('assets/images/logo-sm-white.png') }}" alt="Avidpedia" style="width:50px;">
                <p style="font-size:12px; color:#ffffff; margin:8px 0 0;">
                    <b>AVIDPEDIA PUBLISHER</b><br>
                    +62 851-5842-2426 | contact@avidpedia.com
                </p>
            </td>
        </tr>

        <tr>
            <td style="padding:30px;">
                <h1 style="color:#333333; font-size:24px; margin:0 0 20px; text-align:center;">
                    Invoice Layanan #{{ $invoice->invoice_no }}
                </h1>

                <p style="color:#555555; font-size:16px; line-height:1.6; margin:0 0 20px; text-align:center;">
                    Terima kasih telah mempercayakan pekerjaan Anda kepada <strong>Avidpedia</strong>!<br>
                    Berikut ringkasan invoice Anda:
                </p>

                <table border="0" cellpadding="0" cellspacing="0" style="width:100%; margin-bottom:20px;">
                    <tr>
                        <td style="padding:8px 0; color:#555;"><strong>No Invoice</strong></td>
                        <td style="padding:8px 0; color:#555;">{{ $invoice->invoice_no }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0; color:#555; vertical-align:top;"><strong>Layanan</strong></td>
                        <td style="padding:8px 0; color:#555;">
                            {{ $invoice->items->pluck('name')->implode(', ') }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0; color:#555;"><strong>Total Biaya</strong></td>
                        <td style="padding:8px 0; color:#555;">Rp {{ number_format($invoice->total, 0, ',', '.') }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0; color:#555;"><strong>Jumlah Dibayar</strong></td>
                        <td style="padding:8px 0; color:#555;">Rp {{ number_format($invoice->paid_total, 0, ',', '.') }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0; color:#555;">
                            <strong>{{ $invoice->isOverpaid() ? 'Lebih Bayar' : 'Sisa Bayar' }}</strong>
                        </td>
                        <td style="padding:8px 0; color:#555;">
                            Rp {{ number_format($invoice->isOverpaid() ? $invoice->overpaidAmount() : $invoice->remaining, 0, ',', '.') }}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0; color:#555;"><strong>Jatuh Tempo</strong></td>
                        <td style="padding:8px 0; color:#555;">{{ $invoice->due_at?->format('d F Y') ?? '-' }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px 0; color:#555;"><strong>Status Pengerjaan</strong></td>
                        <td style="padding:8px 0; color:#555;">{{ $invoice->workStatusLabel() }}</td>
                    </tr>
                </table>

                <p style="color:#555555; font-size:14px; line-height:1.6; margin:0;">
                    Invoice lengkap terlampir dalam berkas PDF. Bukti pembayaran dapat dikirim ke
                    WhatsApp Admin <strong>+62 851-5842-2426</strong>.
                </p>
            </td>
        </tr>

        <tr>
            <td style="padding:15px; text-align:center; background-color:#f4f4f4; color:#888; font-size:12px;">
                Avidpedia Publishing &mdash; www.avidpedia.com
            </td>
        </tr>
    </table>
</body>

</html>
