{{--
    Kartu papan. Menjawab 5W1H sekali lihat: kode order, judul, siapa PJ & pelaksana,
    sudah berapa lama di tahap ini, target, prioritas. Buku kolaborasi menambah
    ringkasan bab + progress bar. Seluruh kartu adalah tautan ke Detail Naskah.
--}}
@php
    $p       = $k['progress'];
    $detail  = $p->orderDetail;
    $kode    = $detail?->order?->code_order ?? $detail?->titleRef?->code ?? '—';
    $telat   = $p->isOverdue();
@endphp

<a href="{{ route('naskah.show', $p->order_detail_id) }}"
   class="d-block text-decoration-none text-body bg-white border rounded-2 p-2 mb-2 small
          {{ $telat ? 'border-danger' : '' }}"
   style="{{ $telat ? 'background:#fef2f2 !important' : '' }}">

    <span class="fw-bold text-primary">{{ $kode }}</span>
    @if ($k['jumlah'] > 1)
        <span class="badge bg-light text-dark border" style="font-size:.65rem">{{ $k['jumlah'] }} order</span>
    @endif
    @if ($k['kolab'])
        <span class="badge bg-primary-subtle text-primary" style="font-size:.65rem">Kolab</span>
    @endif
    @if (($k['putaranTerbuka'] ?? 0) > 0)
        {{-- Naskah yang menunggu jawaban revisi tertahan lajunya. Di papan inilah orang
             memutuskan mana yang dikerjakan lebih dulu, jadi ia harus terlihat di sini
             — bukan baru ketahuan sesudah kartunya dibuka. --}}
        <span class="badge bg-warning text-dark" style="font-size:.65rem"
              title="Putaran perbaikan yang belum ditutup">
            ↩ {{ $k['putaranTerbuka'] }} revisi
        </span>
    @endif

    <div class="fw-semibold my-1">{{ \Illuminate\Support\Str::limit($detail?->title ?? '—', 40) }}</div>

    @if ($k['kolab'] && $k['ringkasan'])
        @php $r = $k['ringkasan']; @endphp
        <div class="text-muted">
            Bab: ✓{{ $r['counts']['selesai'] }} selesai · {{ $r['counts']['editing'] }} editing
            · {{ $r['counts']['pembuatan'] }} pembuatan · {{ $r['counts']['menunggu'] }} menunggu
        </div>
        <div class="progress mt-1" style="height:8px">
            <div class="progress-bar" style="width:{{ $r['persen'] }}%"></div>
        </div>
    @endif

    <div class="text-muted mt-1">
        PJ: {{ $p->pj?->name ?? '—' }} ·
        Pelaksana:
        @if ($p->pelaksana)
            {{ $p->pelaksana->name }}
        @elseif (($k['jenisNaskah'] ?? null) === 'mandiri')
            {{-- Seluruh order sejudul bernaskah mandiri → memang tak butuh pelaksana. --}}
            Naskah Mandiri
        @else
            —
        @endif
    </div>

    @if (($k['jenisNaskah'] ?? null) === 'campuran')
        {{-- Jujur mengaku tidak seragam: sebagian order naskahnya dibuatkan, sebagian
             dikirim author. Menyebut salah satu saja menyesatkan bagi order lainnya. --}}
        <div class="text-muted">Jenis naskah: campuran antar order</div>
    @endif
    <div class="text-muted">{{ $p->daysInStage() }} hari di tahap ini</div>

    @if ($p->target_date)
        <div class="{{ $telat ? 'text-danger fw-bold' : 'text-muted' }}">
            🎯 {{ $p->target_date->translatedFormat('j M Y') }}
            @if ($telat)
                — lewat target
                @if ($p->overdue_reason) · alasan: {{ $p->overdue_reason }} @endif
            @endif
        </div>
    @endif

    @if ($p->priority === 'high')
        <span class="badge bg-danger mt-1" style="font-size:.65rem">High</span>
    @endif
    @if ($p->is_on_hold)
        <span class="badge bg-warning text-dark mt-1" style="font-size:.65rem">Ditahan</span>
    @endif
</a>
