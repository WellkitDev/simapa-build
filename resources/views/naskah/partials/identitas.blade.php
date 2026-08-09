{{--
    Identitas satu baris tugas — kode order sebagai identitas utama, judul pendamping.
    Dipakai Meja Kerja & kartu papan supaya penyebutan naskah seragam di semua layar.
    Menerima: $jenis ('judul'|'bab'), $model (TitleProgress|ChapterProgress).
--}}
@php
    if ($jenis === 'judul') {
        $detail = $model->orderDetail;
        $kode   = $detail?->order?->code_order ?? $detail?->titleRef?->code ?? '—';
        $judul  = $detail?->title ?? '—';
        $buku   = in_array($detail?->type, ['bk_mandiri', 'bk_kolab'], true);
        $meta   = collect([
            $buku ? ($detail?->type === 'bk_kolab' ? 'Buku Kolaborasi' : 'Buku Mandiri') : 'Artikel',
            $detail?->indexation,
            $detail?->authors->isNotEmpty() ? 'Author: ' . $detail->authors->pluck('name')->join(', ') : null,
            $model->pj ? 'PJ: ' . $model->pj->name : null,
        ])->filter()->join(' · ');
    } else {
        $bab    = $model->chapter;
        $bukuRef = $bab?->title;
        $detail = $bukuRef?->orderDetails->first();
        $kode   = $detail?->order?->code_order ?? $bukuRef?->code ?? '—';
        $judul  = trim(($bukuRef?->title ?? 'Buku') . ' — ' . ($bab?->judul ?? 'Bab'));
        $meta   = collect([
            'Buku Kolaborasi',
            $bab?->authors->isNotEmpty()
                ? 'Author bab: ' . $bab->authors->pluck('name')->join(', ')
                : 'Author bab belum dipetakan',
        ])->filter()->join(' · ');
    }
@endphp
<span class="fw-bold text-primary small">{{ $kode }}</span>
@if (($jumlahOrder ?? 1) > 1)
    <span class="badge bg-light text-dark border">{{ $jumlahOrder }} order sejudul</span>
@endif
<div class="fw-semibold">{{ $judul }}</div>
<div class="text-muted small">{{ $meta }}</div>
