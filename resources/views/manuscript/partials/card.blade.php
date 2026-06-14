{{-- resources/views/manuscript/partials/card.blade.php --}}
@php
    $p       = $detail->titleProgress;
    $next    = $p->getNextStatus();
    $primary = $detail->authors->sortBy('pivot.position')->first();
    $service = optional($detail->scopes->first())->name ?? strtoupper($detail->type);
@endphp
<div class="card mb-2 mt-card" data-id="{{ $p->id }}" data-status="{{ $p->status }}">
    <div class="card-body p-2">
        <div class="d-flex justify-content-between align-items-center">
            <span class="text-primary fw-bold" style="font-size:11px">{{ $detail->order->code_order ?? '—' }}</span>
            @if($p->priority === 'high')<span class="badge bg-danger">High</span>@endif
        </div>

        <a href="{{ route('order.indexJudul.progress', $detail->id) }}"
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

        <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top">
            <span class="badge bg-info">{{ Str::limit($service, 18) }}</span>
            <small class="text-muted">{{ optional($p->started_at)->diffForHumans() }}</small>
        </div>

        <div class="d-flex justify-content-between align-items-center mt-1">
            <small class="text-muted text-truncate" style="max-width:140px">
                Editor: <strong>{{ optional($p->assignedUser)->name ?? 'Belum' }}</strong>
            </small>
            <div class="dropdown">
                <button class="btn btn-sm btn-link p-0 text-muted" type="button" data-bs-toggle="dropdown" aria-label="Aksi naskah">⋯</button>
                <ul class="dropdown-menu dropdown-menu-end p-2" style="min-width:230px">
                    @if($next)
                    <li>
                        <form method="POST" action="{{ route('manuscript.move', $p->id) }}">@csrf
                            <input type="hidden" name="status" value="{{ $next }}">
                            <button type="submit" class="dropdown-item">
                                Majukan ke {{ Str::title(str_replace('_', ' ', $next)) }}
                            </button>
                        </form>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    @endif
                    <li>
                        <form method="POST" action="{{ route('manuscript.assign', $p->id) }}" class="px-2">@csrf
                            <label class="form-label mb-1" style="font-size:11px">Editor</label>
                            <select name="assigned_user_id" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="">— Belum —</option>
                                @foreach($editors as $ed)
                                    <option value="{{ $ed->id }}" {{ $p->assigned_user_id == $ed->id ? 'selected' : '' }}>{{ $ed->name }}</option>
                                @endforeach
                            </select>
                        </form>
                    </li>
                    <li>
                        <form method="POST" action="{{ route('manuscript.priority', $p->id) }}" class="px-2 mt-2">@csrf
                            <label class="form-label mb-1" style="font-size:11px">Prioritas</label>
                            <select name="priority" class="form-select form-select-sm" onchange="this.form.submit()">
                                @foreach(['low', 'normal', 'high'] as $pr)
                                    <option value="{{ $pr }}" {{ $p->priority === $pr ? 'selected' : '' }}>{{ ucfirst($pr) }}</option>
                                @endforeach
                            </select>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>
