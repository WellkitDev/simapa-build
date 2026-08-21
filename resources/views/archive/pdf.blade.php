<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; }
        .head { border-bottom: 2px solid #333; padding-bottom: 8px; margin-bottom: 14px; }
        .head h1 { margin: 4px 0 0; font-size: 18px; }
        .muted { color: #666; }
        h2 { font-size: 13px; margin: 16px 0 6px; border-bottom: 1px solid #ccc; padding-bottom: 3px; }
        table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        td, th { padding: 5px 7px; border: 1px solid #ccc; text-align: left; vertical-align: top; }
        th { background: #f3f3f3; }
    </style>
</head>
<body>
    <div class="head">
        @if(file_exists(public_path('assets/images/logo-av-90.png')))
            <img src="{{ public_path('assets/images/logo-av-90.png') }}" height="36">
        @endif
        <h1>ARSIP JUDUL SELESAI</h1>
        <div class="muted">{{ $title->code ? $title->code . ' — ' : '' }}{{ $title->title }} · Dicetak: {{ now()->format('d M Y H:i') }}</div>
    </div>

    <h2>Info Judul</h2>
    <table>
        <tr><th width="25%">Kode</th><td>{{ $title->code ?? '-' }}</td></tr>
        <tr><th>Judul</th><td>{{ $title->title }}</td></tr>
        <tr><th>Jenis / Tipe</th><td>{{ ucfirst($title->jenis) }} / {{ ucfirst($title->tipe_naskah) }}</td></tr>
        <tr><th>Bidang Ilmu</th><td>{{ $title->scope?->scope ?? '-' }}</td></tr>
    </table>

    <h2>Info Order</h2>
    <table>
        <thead><tr><th>Kode Order</th><th>Marketing</th><th>Tanggal</th><th>Biaya</th><th>Bayar</th></tr></thead>
        <tbody>
        @forelse($title->orderDetails as $od)
            {{-- Sama persis dengan layar detail arsip: uang yang benar-benar masuk, bukan
                 isLunas() (yang ikut jalan pintas invoice 'lunas'). Dokumen resmi tidak
                 boleh menyebut "Lunas" untuk order yang masih menyisakan tagihan. --}}
            @php
                $ditarik = (bool) optional($od->titleProgress)->withdrawn_at || (bool) $od->order?->isWithdrawn();
                $kurang  = $od->order ? max(0, (int) $od->cost_amount - $od->order->paidNet()) : 0;
            @endphp
            <tr>
                <td>{{ $od->order?->code_order ?? '-' }}</td>
                <td>{{ $od->order?->user?->name ?? '-' }}</td>
                <td>{{ optional($od->order?->ordered_at)->format('d M Y') ?? '-' }}</td>
                <td>Rp {{ number_format((int) $od->cost_amount, 0, ',', '.') }}</td>
                <td>@if($ditarik)Ditarik (refund)@elseif(! $od->order)-@elseif($kurang > 0)Kurang Rp {{ number_format($kurang, 0, ',', '.') }}@else Lunas @endif</td>
            </tr>
        @empty
            <tr><td colspan="5">Belum ada order.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>Info Manuskrip</h2>
    <table>
        <tr><th width="25%">Status</th><td>{{ $title->manuscriptStatusLabel() ?? '-' }}</td></tr>
        @if($title->jenis === 'buku' && $title->chapters->isNotEmpty())
            <tr><th>Bab</th><td>{{ $title->chapters->pluck('judul')->join(', ') }}</td></tr>
        @endif
    </table>

    <h2>Artefak Penyelesaian</h2>
    <table>
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

    <h2>Penanggung Jawab &amp; Pelaksana</h2>
    <table>
        <thead><tr><th width="22%">Kode Order</th><th>Penanggung Jawab</th><th>Pelaksana</th><th width="18%">Tahap Akhir</th></tr></thead>
        <tbody>
        @forelse($riwayat['orang'] as $o)
            <tr>
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

    <h2>Riwayat Perubahan</h2>
    <table>
        <thead><tr><th width="16%">Waktu</th><th width="16%">Sumber</th><th>Aksi</th><th width="16%">Oleh</th><th width="24%">Catatan</th></tr></thead>
        <tbody>
        @forelse($riwayat['riwayat'] as $r)
            <tr>
                <td>{{ optional($r['waktu'])->format('d/m/y H:i') ?? '-' }}</td>
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

    <h2>Persetujuan</h2>
    <table>
        <tr><th width="25%">Status</th><td>Disetujui</td></tr>
        <tr><th>Disetujui oleh</th><td>{{ optional($title->archive->approver)->name ?? '-' }}</td></tr>
        <tr><th>Tanggal</th><td>{{ optional($title->archive->approved_at)->format('d M Y H:i') ?? '-' }}</td></tr>
        <tr><th>Catatan</th><td>{{ $title->archive->approval_note ?? '-' }}</td></tr>
    </table>
</body>
</html>
