<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #222; }
        .head { border-bottom: 2px solid #333; padding-bottom: 8px; margin-bottom: 16px; }
        .head h1 { margin: 0; font-size: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        td, th { padding: 6px 8px; border: 1px solid #ccc; text-align: left; }
        .right { text-align: right; }
        .muted { color: #666; }
        .total { font-size: 14px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="head">
        <h1>TAGIHAN</h1>
        <div class="muted">No: {{ $tagihan->tagihan_no }} · {{ $tagihan->created_at->format('d M Y') }}</div>
    </div>

    <table>
        <tr><th width="30%">Kepada</th><td>{{ $tagihan->client_name }}@if($tagihan->client_institution) — {{ $tagihan->client_institution }}@endif</td></tr>
        <tr><th>Kontak</th><td>{{ $tagihan->client_email ?: '-' }} / {{ $tagihan->client_phone ?: '-' }}</td></tr>
        <tr><th>Judul</th><td>{{ $tagihan->title }} ({{ ucfirst($tagihan->type) }})</td></tr>
        <tr><th>Author</th><td>{{ $tagihan->author_names ?: '-' }}</td></tr>
        <tr><th>Rincian</th><td>{{ $tagihan->description ?: '-' }}</td></tr>
        <tr><th>Jatuh Tempo</th><td>{{ $tagihan->due_at ? $tagihan->due_at->format('d M Y') : '-' }}</td></tr>
    </table>

    <table>
        <tr><th class="right" width="70%">TOTAL TAGIHAN</th><td class="right total">Rp {{ number_format($tagihan->amount, 0, ',', '.') }}</td></tr>
    </table>

    @if($tagihan->note)
        <p class="muted" style="margin-top:16px">Catatan: {{ $tagihan->note }}</p>
    @endif

    <p class="muted" style="margin-top:24px">Diterbitkan oleh {{ optional($tagihan->creator)->name }} · SiMAPA Avidpedia</p>
</body>
</html>
