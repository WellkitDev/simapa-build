@extends('layouts.master')
@section('title', 'Pelacak Naskah - SiMAPA')

@section('content')
@php
    $statusColors = [
        'menunggu_proses' => '#94A3B8',
        'pembuatan' => '#F59E0B', 'templating' => '#F59E0B', 'editing' => '#F59E0B', 'layout' => '#F59E0B',
        'revisi' => '#FB923C', 'proofreading' => '#FB923C', 'isbn' => '#FB923C',
        'submit' => '#4C5FD5', 'cetak' => '#4C5FD5', 'loa' => '#4C5FD5',
        'publish' => '#22C55E', 'terbit' => '#22C55E',
    ];
@endphp
@include('manuscript.partials.toolbar')

<div style="overflow-x:auto">
        <div class="d-flex gap-4 pb-2" style="min-width:max-content; align-items:flex-start">
            @foreach($zones as $zone)
                <div class="manuscript-zone" style="flex-shrink:0; padding:12px 14px; border-radius:14px;
                    background:{{ $zone['tint'] }}; border:1px solid {{ $zone['accent'] }}40;
                    border-top:3px solid {{ $zone['accent'] }}; box-shadow:0 6px 18px rgba(15,23,42,.10)">
                    <div class="d-flex align-items-center gap-2 mb-3" style="padding:0 2px">
                        <span style="font-size:11px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:{{ $zone['accent'] }}">{{ $zone['label'] }}</span>
                        <span class="text-muted" style="font-size:10px">· {{ $zone['sub'] }}</span>
                    </div>
                    <div class="d-flex gap-3">
                        @foreach($zone['stages'] as $stage)
                            @php $cards = $byStatus[$stage] ?? collect(); @endphp
                            <div data-stage-col style="width:264px; flex-shrink:0">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <span style="width:8px;height:8px;border-radius:50%;display:inline-block;background:{{ $statusColors[$stage] ?? '#94A3B8' }}"></span>
                                    <strong style="font-size:13px">{{ Str::title(str_replace('_', ' ', $stage)) }}</strong>
                                    <span class="badge bg-light text-muted" data-count>{{ $cards->count() }}</span>
                                </div>
                                <div data-column data-status="{{ $stage }}" style="min-height:60px; background:rgba(255,255,255,.7); border-radius:8px; padding:8px">
                                    @forelse($cards as $detail)
                                        @include('manuscript.partials.card')
                                    @empty
                                        <div class="text-muted text-center py-3" style="font-size:11px">Tidak ada naskah</div>
                                    @endforelse
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endsection

@push('custom-scripts')
<script>
// Papan Pelacakan Naskah kini hanya-baca. Pada layar kecil, alihkan ke tampilan Daftar.
(function () {
    if (!location.search.includes('view=') && window.innerWidth < 768) {
        const sep = location.search ? '&' : '?';
        location.replace(location.pathname + location.search + sep + 'view=list');
    }
})();
</script>
@endpush
