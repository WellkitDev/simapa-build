{{--
    Laporan Arsip Judul — dokumen resmi penutup satu judul.

    Gaya mengikuti invoice (kop dua kolom, warna korporat, blok Mengetahui bertanda
    tangan) supaya kedua dokumen resmi Avidpedia terbaca sebagai satu keluarga.

    Urutannya sengaja A–Z: identitas → uang → naskah → siapa mengerjakan → bukti →
    jejak perubahan → keterangan → persetujuan. Dibaca dari atas ke bawah, seseorang
    yang belum pernah melihat judul ini tetap bisa mengikutinya.
--}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 28px 34px 60px; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10.5px;
            color: #222;
            line-height: 1.5;
        }

        /* ── Kop ── */
        .kop { width: 100%; border-bottom: 3px solid #003366; padding-bottom: 10px; margin-bottom: 4px; }
        .kop td { border: none; padding: 0; vertical-align: bottom; }
        .kop .perusahaan { font-size: 9.5px; color: #555; line-height: 1.45; }
        .kop .perusahaan strong { color: #003366; font-size: 12px; }
        .kop .judul-dok { text-align: right; }
        .kop .judul-dok h1 { margin: 0; font-size: 19px; color: #003366; letter-spacing: 0.5px; }
        .kop .judul-dok p { margin: 2px 0 0; font-size: 9.5px; color: #555; }

        .subjudul {
            background: #f4f7fa;
            border-left: 3px solid #003366;
            padding: 7px 10px;
            margin: 12px 0 16px;
        }
        .subjudul .besar { font-size: 12.5px; font-weight: bold; color: #003366; }
        .subjudul .kecil { font-size: 9.5px; color: #666; }

        /* ── Bagian ── */
        h2 {
            font-size: 11.5px;
            color: #003366;
            margin: 18px 0 5px;
            padding-bottom: 3px;
            border-bottom: 1px solid #cdd8e3;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        table.data { width: 100%; border-collapse: collapse; margin-top: 3px; }
        table.data td, table.data th {
            padding: 5px 7px;
            border: 1px solid #d6dde5;
            text-align: left;
            vertical-align: top;
        }
        table.data th { background: #eef2f7; color: #003366; font-weight: bold; }
        table.data tbody tr:nth-child(even) td { background: #fafbfc; }
        .redup { color: #888; }
        .nowrap { white-space: nowrap; }

        /* ── Keterangan ── */
        .keterangan {
            margin-top: 18px;
            border: 1px solid #e4c86a;
            background: #fdf9ec;
            padding: 9px 12px;
            font-size: 9.5px;
            line-height: 1.6;
        }
        .keterangan h4 { margin: 0 0 5px; font-size: 10.5px; color: #7a5c00; }
        .keterangan ul { margin: 0; padding-left: 16px; }
        .keterangan li { margin-bottom: 3px; }

        /* ── Tanda tangan ── */
        table.ttd { width: 100%; margin-top: 26px; border-collapse: collapse; }
        table.ttd td { border: none; padding: 0 10px; text-align: center; vertical-align: top; font-size: 10px; }
        table.ttd .peran { color: #555; margin-bottom: 2px; }
        table.ttd .ruang { height: 56px; }
        table.ttd img { width: 88px; opacity: 0.9; }
        table.ttd .nama { font-weight: bold; border-top: 1px solid #999; padding-top: 4px; }

        .footer {
            position: fixed;
            bottom: -34px; left: 0; right: 0;
            border-top: 1px solid #dde3ea;
            padding-top: 6px;
            text-align: center;
            font-size: 8.5px;
            color: #777;
        }
    </style>
</head>
<body>

<div class="footer">
    Avidpedia Publishing &mdash; www.avidpedia.com &nbsp;·&nbsp;
    Dokumen dibuat otomatis {{ now()->translatedFormat('d F Y H:i') }} WIB &nbsp;·&nbsp;
    Arsip {{ $title->code ?: $title->id }}
</div>

<table class="kop">
    <tr>
        <td width="58%">
            @if(file_exists(public_path('assets/images/logo-av-90.png')))
                <img src="{{ public_path('assets/images/logo-av-90.png') }}" height="34"><br>
            @endif
            <div class="perusahaan">
                <strong>PT AVID MEDIA INDONESIA</strong><br>
                Simpang III Sipin, Kota Baru, Jambi, 36126<br>
                +62 851-5842-2426 &nbsp;|&nbsp; contact@avidpedia.com
            </div>
        </td>
        <td width="42%" class="judul-dok">
            <h1>LAPORAN ARSIP</h1>
            <p>Nomor: {{ $title->code ?: 'ARS-' . $title->id }}</p>
            <p>Tanggal: {{ now()->translatedFormat('d F Y') }}</p>
        </td>
    </tr>
</table>

<div class="subjudul">
    <div class="besar">{{ $title->title }}</div>
    <div class="kecil">
        {{ ucfirst($title->jenis) }} · {{ ucfirst($title->tipe_naskah) }}
        @if($title->scope?->scope) · {{ $title->scope->scope }} @endif
        · Status manuskrip: {{ $title->manuscriptStatusLabel() ?? '—' }}
    </div>
</div>

<h2>Info Judul</h2>
<table class="data">
    <tr><th width="25%">Kode</th><td>{{ $title->code ?? '-' }}</td></tr>
    <tr><th>Judul</th><td>{{ $title->title }}</td></tr>
    <tr><th>Jenis / Tipe</th><td>{{ ucfirst($title->jenis) }} / {{ ucfirst($title->tipe_naskah) }}</td></tr>
    <tr><th>Bidang Ilmu</th><td>{{ $title->scope?->scope ?? '-' }}</td></tr>
    <tr><th>{{ $title->jenis === 'buku' ? 'Link Buku Terbit' : 'Link Artikel Terbit' }}</th>
        <td>{{ $title->linkTerbit() ?? '-' }}</td></tr>
</table>

<h2>Info Order</h2>
<table class="data">
    <thead><tr><th>Kode Order</th><th>Marketing</th><th class="nowrap">Tanggal</th><th>Biaya</th><th>Bayar</th></tr></thead>
    <tbody>
    @forelse($title->orderDetails as $od)
        {{-- Sama persis dengan layar detail arsip: uang yang benar-benar masuk, bukan
             isLunas() (yang ikut jalan pintas invoice 'lunas'). Dokumen resmi tidak
             boleh menyebut "Lunas" untuk order yang masih menyisakan tagihan. --}}
        @php
            $ditarik = (bool) optional($od->titleProgress)->withdrawn_at || (bool) $od->order?->isWithdrawn();
            $kurang  = $od->order ? max(0, (int) $od->cost_amount - $od->order->paidNet()) : 0;
        @endphp
        <tr class="{{ $ditarik ? 'redup' : '' }}">
            <td>{{ $od->order?->code_order ?? '-' }}</td>
            <td>{{ $od->order?->user?->name ?? '-' }}</td>
            <td class="nowrap">{{ optional($od->order?->ordered_at)->format('d M Y') ?? '-' }}</td>
            <td class="nowrap">Rp {{ number_format((int) $od->cost_amount, 0, ',', '.') }}</td>
            <td>@if($ditarik)Ditarik (refund)@elseif(! $od->order)-@elseif($kurang > 0)Kurang Rp {{ number_format($kurang, 0, ',', '.') }}@else Lunas @endif</td>
        </tr>
    @empty
        <tr><td colspan="5">Belum ada order.</td></tr>
    @endforelse
    </tbody>
</table>

<h2>Info Manuskrip</h2>
<table class="data">
    <tr><th width="25%">Status</th><td>{{ $title->manuscriptStatusLabel() ?? '-' }}</td></tr>
    @if($title->jenis === 'buku' && $title->chapters->isNotEmpty())
        <tr><th>Bab ({{ $title->chapters->count() }})</th><td>{{ $title->chapters->pluck('judul')->join(', ') }}</td></tr>
    @endif
</table>

<h2>Penanggung Jawab &amp; Pelaksana</h2>
<table class="data">
    <thead><tr><th width="22%">Kode Order</th><th>Penanggung Jawab</th><th>Pelaksana</th><th width="18%">Tahap Akhir</th></tr></thead>
    <tbody>
    @forelse($riwayat['orang'] as $o)
        <tr class="{{ $o['ditarik'] ? 'redup' : '' }}">
            <td>{{ $o['kode'] }}{{ $o['ditarik'] ? ' (ditarik)' : '' }}</td>
            <td>{{ $o['pj'] }}</td>
            <td>{{ $o['pelaksana'] }}</td>
            <td>{{ $o['tahap'] }}</td>
        </tr>
    @empty
        <tr><td colspan="4">Belum ada order.</td></tr>
    @endforelse
    </tbody>
</table>

<h2>Artefak Penyelesaian</h2>
<table class="data">
    <thead><tr><th width="26%">Item</th><th>Nilai</th><th width="26%">Catatan</th></tr></thead>
    <tbody>
    @foreach($artifacts as $a)
        <tr>
            <td>{{ $a['label'] }}</td>
            <td>{{ $a['value'] ?: '-' }}{{ $a['type'] === 'file' && $a['file_name'] ? ' (' . $a['file_name'] . ')' : '' }}</td>
            <td>{{ $a['note'] ?? '-' }}</td>
        </tr>
    @endforeach
    @foreach($custom as $c)
        <tr>
            <td>{{ $c->label }}</td>
            <td>{{ $c->value ?: '-' }}</td>
            <td>{{ $c->note ?? '-' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<h2>Riwayat Perubahan</h2>
<table class="data">
    <thead><tr><th width="14%">Waktu</th><th width="15%">Sumber</th><th>Aksi</th><th width="15%">Oleh</th><th width="24%">Catatan</th></tr></thead>
    <tbody>
    @forelse($riwayat['riwayat'] as $r)
        <tr>
            <td class="nowrap">{{ optional($r['waktu'])->format('d/m/y H:i') ?? '-' }}</td>
            <td>{{ $r['sumber'] }}</td>
            <td>{{ $r['aksi'] }}@if($r['dari'] || $r['ke']) ({{ $r['dari'] ?? '-' }} &rarr; {{ $r['ke'] ?? '-' }})@endif</td>
            <td>{{ $r['oleh'] }}</td>
            <td>{{ $r['note'] ?? '-' }}</td>
        </tr>
    @empty
        <tr><td colspan="5">Belum ada riwayat.</td></tr>
    @endforelse
    </tbody>
</table>

@php
    // Nama yang disebut di klausul tanggung jawab diambil dari naskahnya sendiri —
    // bukan diketik ulang — supaya dokumen ini tak pernah menyebut orang yang berbeda
    // dari yang benar-benar tercatat memegang naskahnya.
    $paraPj  = collect($riwayat['orang'])->pluck('pj')->reject(fn ($n) => $n === '—')->unique()->values();
    $paraPel = collect($riwayat['orang'])->pluck('pelaksana')->reject(fn ($n) => $n === '—')->unique()->values();
@endphp

<div class="keterangan">
    <h4>Keterangan</h4>
    <ul>
        <li>
            Seluruh data pada laporan ini dihimpun otomatis dari sistem SiMAPA pada tanggal cetak.
            Nilai artefak diambil dari modul asalnya (Direktori ISBN, Direktori Jurnal, dan berkas
            naskah), bukan diketik ulang.
        </li>
        <li>
            <strong>Kekeliruan, kekurangan, atau kelalaian pada isi laporan ini menjadi tanggung
            jawab Penanggung Jawab naskah (admin) dan Pelaksana pembuatan naskah</strong>@if($paraPj->isNotEmpty() || $paraPel->isNotEmpty()), yaitu
            {{ $paraPj->isNotEmpty() ? 'PJ: ' . $paraPj->join(', ') : '' }}@if($paraPj->isNotEmpty() && $paraPel->isNotEmpty()); @endif{{ $paraPel->isNotEmpty() ? 'Pelaksana: ' . $paraPel->join(', ') : '' }}@endif.
        </li>
        <li>
            Riwayat perubahan bersifat menyeluruh dan tidak dapat dihapus, sehingga dapat dipakai
            sebagai rujukan bila terjadi perbedaan pemahaman di kemudian hari.
        </li>
        <li>
            Order yang ditandai <em>ditarik</em> adalah pesanan yang dananya telah dikembalikan
            penuh; datanya tetap ditampilkan sebagai bagian dari riwayat.
        </li>
        <li>Laporan ini sah secara digital tanpa tanda tangan basah.</li>
    </ul>
</div>

<table class="ttd">
    <tr>
        <td width="50%">
            <div class="peran">Penanggung Jawab Naskah,</div>
            <div class="ruang"></div>
            <div class="nama">{{ $paraPj->isNotEmpty() ? $paraPj->join(', ') : '(belum ditentukan)' }}</div>
        </td>
        <td width="50%">
            <div class="peran">Jambi, {{ now()->translatedFormat('d F Y') }}<br>Mengetahui,</div>
            @if(file_exists(public_path('assets/images/ttd.png')))
                <img src="{{ public_path('assets/images/ttd.png') }}" alt="Tanda tangan">
            @else
                <div class="ruang"></div>
            @endif
            <div class="nama">
                {{ optional($title->archive->approver)->name ?? 'PT AVID MEDIA INDONESIA' }}<br>
                <span style="font-weight:normal;font-size:9px;color:#666">
                    Disetujui {{ optional($title->archive->approved_at)->translatedFormat('d F Y') ?? '-' }}
                    @if($title->archive->approval_note) · {{ $title->archive->approval_note }} @endif
                </span>
            </div>
        </td>
    </tr>
</table>

</body>
</html>
