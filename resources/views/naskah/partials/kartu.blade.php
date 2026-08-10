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
        @elseif ($detail?->naskahMandiri())
            {{-- Bukan "belum ditugaskan": order ini memang tak butuh pelaksana. --}}
            Naskah Mandiri
        @else
            —
        @endif
    </div>
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
