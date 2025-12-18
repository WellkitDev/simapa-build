<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Invoice INV-XXXXXX</title>
    <style>
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
    </style>
</head>

<body>

    <!-- Header -->
    <div class="header">
        <h1>AVIDPEDIA PUBLISHING</h1>
        <p>Jasa Layanan Publikasi Buku & Artikel Ilmiah</p>
        <p>Jl. Contoh No.123, Jakarta Selatan</p>
        <p>contact@avidpedia.com | 0812-3456-7890</p>
    </div>

    <div class="separator mb-2"></div>
    <table class="mb-2">
        <tr>
            <td>
                <p class="text-left">
                    <strong>Kepada Yth.</strong><br>
                    Nama Penulis Utama, dkk.<br>
                    Universitas / Institusi
                </p>
            </td>
            <td>
                <p class="text-right">
                    <strong>INVOICE : INV-XXXXXX</strong><br>
                    Tanggal : 01 Januari 2025
                </p>
            </td>
        </tr>


    </table>
    <!-- Kepada -->

    <!-- Info Invoice -->

    <div class="separator mt-2"></div>

    <h3>Detail Order</h3>
    <div class="separator"></div>

    <table>
        <tr>
            <td width="30%"><strong>Jenis Layanan</strong></td>
            <td>: Buku Mandiri (Naskah Mandiri)</td>
        </tr>
        <tr>
            <td><strong>Judul</strong></td>
            <td>: Judul Buku / Artikel</td>
        </tr>
        <tr>
            <td><strong>Jumlah Bab</strong></td>
            <td>: 10 Bab</td>
        </tr>
        <tr>
            <td><strong>Scope</strong></td>
            <td>: Pendidikan / Sosial</td>
        </tr>
        <tr>
            <td><strong>Target Indeksasi</strong></td>
            <td>: SINTA / Scopus</td>
        </tr>
        <tr>
            <td><strong>Jumlah Penulis</strong></td>
            <td>: 3 orang</td>
        </tr>
        <tr>
            <td><strong>Penulis</strong></td>
            <td>:
                <ol style="margin:0; padding-left:20px;">
                    <li>Nama Penulis Utama (Universitas A)</li>
                    <li>Nama Penulis Kedua (Universitas B)</li>
                    <li>Nama Penulis Ketiga</li>
                </ol>
            </td>
        </tr>
        <tr>
            <td><strong>Marketing</strong></td>
            <td>: Nama Marketing</td>
        </tr>
        <tr>
            <td><strong>Kontak</strong></td>
            <td>: 08123456789 | email@contoh.com</td>
        </tr>
    </table>

    <h3>Rincian Biaya</h3>
    <div class="separator"></div>

    <table>
        <tr>
            <td width="70%">
                Biaya Publikasi Buku Mandiri (10 Bab)
            </td>
            <td class="text-right">Rp 10.000.000</td>
        </tr>
        <tr class="bold">
            <td>Total Tagihan</td>
            <td class="text-right">Rp 10.000.000</td>
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
            <tr>
                <td>1</td>
                <td>01 Jan 2025</td>
                <td>DP</td>
                <td class="text-right">Rp 5.000.000</td>
                <td>Terbayar</td>
            </tr>
            <tr>
                <td>2</td>
                <td>10 Jan 2025</td>
                <td>Pelunasan</td>
                <td class="text-right">Rp 5.000.000</td>
                <td><span class="status-lunas">Lunas ✅</span></td>
            </tr>
        </tbody>
    </table>

    <p class="bold">
        Sisa Tagihan :
        <strong>Rp 0</strong>
    </p>

    <p class="bold">
        Status Invoice :
        <strong class="status-lunas">LUNAS</strong>
    </p>

    <!-- Footer -->
    <div class="footer">
        <p>Terima kasih atas kepercayaan Anda kepada Avidpedia!</p>
    </div>

</body>

</html>
