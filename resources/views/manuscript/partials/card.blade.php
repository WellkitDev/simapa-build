{{-- resources/views/manuscript/partials/card.blade.php — READ ONLY --}}
@php
    $p          = $detail->titleProgress;
    $primary    = $detail->authors->sortBy('pivot.position')->first();
    $service    = optional($detail->scopes->first())->name ?? strtoupper($detail->type);
    $orderCount = $detail->group_order_count ?? 1;
    $isBook     = in_array($detail->type, ['bk_mandiri', 'bk_kolab'], true);
    $targetWord = $isBook ? 'terbit' : 'publish';
    $overdue    = $p->target_date && $p->target_date->lt(today()) && ! in_array($p->status, ['terbit', 'publish'], true);
    $chapters   = $isBook ? (optional($detail->titleRef)->chapters ?? collect()) : collect();
@endphp
<div class="card mb-2 mt-card" data-id="{{ $p->id }}" data-status="{{ $p->status }}">
    <div class="card-body p-2">
        <div class="d-flex justify-content-between align-items-center">
            <span class="text-primary fw-bold" style="font-size:11px">{{ $detail->order->code_order ?? '—' }}</span>
            <div class="d-flex gap-1">
                @if(\App\Models\TitleProgress::isFinal($p->status))<span class="badge bg-success">🔒 Final</span>@endif
                @if($orderCount > 1)<span class="badge bg-secondary">{{ $orderCount }} order</span>@endif
                @if($p->priority === 'high')<span class="badge bg-danger">High</span>@endif
            </div>
        </div>

        <a href="{{ route('order.indexJudul.detail', $detail->id) }}"
           class="d-block fw-semibold text-dark text-decoration-none mt-1" style="font-size:13px; line-height:1.3">
            {{ Str::limit($detail->title, 60) }}
        </a>

        <div class="d-flex align-items-center gap-2 mt-2">
            <span class="badge bg-light text-secondary">{{ strtoupper(Str::substr(optional($primary)->name ?? '?', 0, 1)) }}</span>
            <div class="flex-grow-1" style="min-width:0">
                <div class="text-truncate" style="font-size:11px">{{ optional($primary)->name ?? '—' }}</div>
                <div class="text-muted text-truncate" style="font-size:10px">{{ optional($primary)->affiliation ?? '' }}</div>
            </div>
        </div>

        <div class="mt-1" style="font-size:10px">
            @if($p->target_date)
                <span class="badge {{ $overdue ? 'bg-danger' : 'bg-light text-dark border' }}">🎯 {{ $targetWord }}: {{ $p->target_date->format('d M Y') }}{{ $overdue ? ' · lewat!' : '' }}</span>
            @else
                <span class="text-muted">🎯 {{ $targetWord }}: —</span>
            @endif
        </div>

        <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top">
            <span class="badge bg-info">{{ Str::limit($service, 18) }}</span>
            <small class="text-muted">Editor: <strong>{{ optional($p->assignedUser)->name ?? 'Belum' }}</strong></small>
        </div>

        @if($isBook && $chapters->isNotEmpty())
            <div class="mt-2 pt-2 border-top">
                <button class="btn btn-sm btn-link p-0 text-decoration-none" type="button" data-bs-toggle="collapse" data-bs-target="#chapters-{{ $p->id }}" style="font-size:11px">📖 Bab ({{ $chapters->count() }})</button>
                <div class="collapse mt-1" id="chapters-{{ $p->id }}">
                    @foreach($chapters as $ch)
                        @php $cp = $ch->progress; $cstatus = optional($cp)->status ?? 'menunggu_proses'; @endphp
                        <div class="border rounded p-2 mb-1" style="font-size:11px">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="fw-semibold text-truncate" style="max-width:150px">{{ $ch->urutan }}. {{ $ch->judul }}</span>
                                <span class="badge {{ in_array($cstatus, \App\Models\TitleProgress::FINAL_STAGES, true) ? 'bg-success' : 'bg-info' }}">{{ \App\Models\Title::stageLabel($cstatus) }}</span>
                            </div>
                            <div class="text-muted mt-1">Editor: {{ optional(optional($cp)->assignedUser)->name ?? 'Belum' }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>
