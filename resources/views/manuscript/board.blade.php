@extends('layouts.master')
@section('title', 'Manuscript Tracker - SiMAPA')

@section('content')
@php
    $statusColors = [
        'menunggu_proses' => '#94A3B8',
        'templating' => '#F59E0B', 'editing' => '#F59E0B', 'layout' => '#F59E0B',
        'revisi' => '#FB923C', 'proofreading' => '#FB923C', 'isbn' => '#FB923C',
        'submit' => '#4C5FD5', 'cetak' => '#4C5FD5', 'loa' => '#4C5FD5',
        'publish' => '#22C55E', 'terbit' => '#22C55E',
    ];
@endphp
<div class="page-content">
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
</div>
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/plugins/sortablejs/Sortable.min.js') }}"></script>
@endpush

@push('custom-scripts')
<script>
(function () {
    const token = document.querySelector('meta[name="_token"]').getAttribute('content');
    const base  = "{{ url('management/manuscript') }}";

    function toast(msg, ok) {
        const el = document.createElement('div');
        el.className = 'alert alert-' + (ok ? 'success' : 'danger');
        el.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:9999;min-width:240px;box-shadow:0 2px 8px rgba(0,0,0,.15)';
        el.textContent = msg;
        document.body.appendChild(el);
        setTimeout(() => el.remove(), 4000);
    }

    function refreshCounts() {
        document.querySelectorAll('[data-stage-col]').forEach((col) => {
            const list = col.querySelector('[data-column]');
            const badge = col.querySelector('[data-count]');
            if (list && badge) badge.textContent = list.querySelectorAll('[data-id]').length;
        });
    }

    document.querySelectorAll('[data-column]').forEach((col) => {
        new Sortable(col, {
            group: 'manuscript',
            animation: 150,
            ghostClass: 'opacity-50',
            onEnd: function (evt) {
                const item = evt.item;
                const toCol = evt.to;
                const fromCol = evt.from;
                if (toCol === fromCol) return;

                const target = toCol.getAttribute('data-status');
                const id = item.getAttribute('data-id');

                fetch(base + '/' + id + '/move', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': token,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ status: target }),
                })
                .then(async (res) => {
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok) throw new Error(data.message || 'Gagal memindahkan naskah.');
                    item.setAttribute('data-status', target);
                    toast(data.message || 'Naskah dipindahkan.', true);
                    refreshCounts();
                })
                .catch((err) => {
                    const ref = fromCol.children[evt.oldIndex] || null;
                    fromCol.insertBefore(item, ref);
                    toast(err.message, false);
                });
            },
        });
    });

    if (!location.search.includes('view=') && window.innerWidth < 768) {
        const sep = location.search ? '&' : '?';
        location.replace(location.pathname + location.search + sep + 'view=list');
    }
})();
</script>
@endpush
