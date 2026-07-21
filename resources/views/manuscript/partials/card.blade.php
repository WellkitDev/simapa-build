{{-- resources/views/manuscript/partials/card.blade.php --}}
@php
    $p          = $detail->titleProgress;
    $next       = $p->getNextStatus();
    $primary    = $detail->authors->sortBy('pivot.position')->first();
    $service    = optional($detail->scopes->first())->name ?? strtoupper($detail->type);
    $orderCount = $detail->group_order_count ?? 1;
    $targetWord = in_array($detail->type, ['bk_mandiri', 'bk_kolab'], true) ? 'terbit' : 'publish';
    $overdue    = $p->target_date && $p->target_date->lt(today()) && ! in_array($p->status, ['terbit', 'publish'], true);
    $hasNewLog  = $p->last_log_at && $p->last_log_at->gt(now()->subDays(2));
    $isBook   = in_array($detail->type, ['bk_mandiri', 'bk_kolab'], true);
    $chapters = $isBook ? (optional($detail->titleRef)->chapters ?? collect()) : collect();
    $finalLocked = \App\Models\TitleProgress::isFinal($p->status) && ! auth()->user()->hasRole('superadmin');
@endphp
<div class="card mb-2 mt-card" data-id="{{ $p->id }}" data-status="{{ $p->status }}" @if($isBook || $finalLocked) data-no-drag @endif @if($finalLocked) data-final-locked @endif>
    <div class="card-body p-2">
        <div class="d-flex justify-content-between align-items-center">
            <span class="text-primary fw-bold" style="font-size:11px">{{ $detail->order->code_order ?? '—' }}</span>
            <div class="d-flex gap-1">
                @if($hasNewLog)
                    <span class="badge bg-success" title="Ada aktivitas baru dalam 2 hari terakhir">● log</span>
                @endif
                @if($p->needs_review)
                    <span class="badge bg-warning text-dark" title="Lompat tahap oleh non-superadmin — perlu ditinjau superadmin">⚑ tinjau</span>
                @endif
                @if(\App\Models\TitleProgress::isFinal($p->status))
                    <span class="badge bg-success" title="Naskah final — terkunci">🔒 Final</span>
                @endif
                @if($orderCount > 1)
                    <span class="badge bg-secondary" title="Jumlah order untuk judul ini">{{ $orderCount }} order</span>
                @endif
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

        {{-- Target terbit/publish (kontrol agar tidak ketinggalan) --}}
        <div class="mt-1" style="font-size:10px">
            @if($p->target_date)
                <span class="badge {{ $overdue ? 'bg-danger' : 'bg-light text-dark border' }}" title="Target {{ $targetWord }}">
                    🎯 Target {{ $targetWord }}: {{ $p->target_date->format('d M Y') }}{{ $overdue ? ' · lewat!' : '' }}
                </span>
            @else
                <span class="text-muted" title="Belum ada target {{ $targetWord }}">🎯 Target {{ $targetWord }}: —</span>
            @endif
        </div>

        <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top">
            <span class="badge bg-info">{{ Str::limit($service, 18) }}</span>
            <small class="text-muted">
                @if($detail->group_is_mixed ?? false)
                    <span class="badge bg-light text-muted" title="Status antar order belum seragam">beragam</span>
                @endif
                {{ optional($p->started_at)->diffForHumans() }}
            </small>
        </div>

        @if(is_null($p->assigned_user_id))
            <form method="POST" action="{{ route('manuscript.assign', $p->id) }}" class="mt-2">@csrf
                <input type="hidden" name="assigned_user_id" value="{{ auth()->id() }}">
                <button type="submit" class="btn btn-sm btn-success w-100 py-0" style="font-size:11px">+ Ambil naskah ini</button>
            </form>
        @endif
        <div class="d-flex justify-content-between align-items-center mt-1">
            <small class="text-muted text-truncate" style="max-width:140px">
                Editor: <strong>{{ optional($p->assignedUser)->name ?? 'Belum' }}</strong>
            </small>
            <div class="dropdown">
                <button class="btn btn-sm btn-link p-0 text-muted" type="button" data-bs-toggle="dropdown" aria-label="Aksi naskah">⋯</button>
                <ul class="dropdown-menu dropdown-menu-end p-2" style="min-width:230px">
                    @if($next && ! $isBook)
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
                    <li>
                        <form method="POST" action="{{ route('manuscript.target', $p->id) }}" class="px-2 mt-2">@csrf
                            <label class="form-label mb-1" style="font-size:11px">Target {{ $targetWord }}</label>
                            <input type="date" name="target_date" value="{{ optional($p->target_date)->format('Y-m-d') }}"
                                   class="form-control form-control-sm" onchange="this.form.submit()">
                        </form>
                    </li>
                </ul>
            </div>
        </div>

        @if($p->needs_review)
            <div class="mt-2 p-2 rounded" style="background:#FEF3C7; font-size:11px">
                <div class="text-dark"><strong>⚑ Lompat tahap</strong> oleh {{ optional($p->updatedBy)->name ?? '—' }}</div>
                @if($p->note)<div class="text-muted mt-1">"{{ Str::limit($p->note, 90) }}"</div>@endif
                @can('manuscript.review')
                    <form method="POST" action="{{ route('manuscript.reviewed', $p->id) }}" class="mt-1">@csrf
                        <button type="submit" class="btn btn-sm btn-warning py-0 px-2" style="font-size:11px">Tandai sudah ditinjau</button>
                    </form>
                @endcan
            </div>
        @endif

        @if($isBook && $chapters->isNotEmpty())
            <div class="mt-2 pt-2 border-top">
                <button class="btn btn-sm btn-link p-0 text-decoration-none" type="button" data-bs-toggle="collapse" data-bs-target="#chapters-{{ $p->id }}" style="font-size:11px">
                    📖 Bab ({{ $chapters->count() }})
                </button>
                <div class="collapse mt-1" id="chapters-{{ $p->id }}">
                    @foreach($chapters as $ch)
                        @php
                            $cp = $ch->progress;
                            $cstatus = optional($cp)->status ?? 'menunggu_proses';
                            $cix = array_search($cstatus, \App\Models\TitleProgress::BOOK_STAGES, true);
                            $cnext = $cix === false ? null : (\App\Models\TitleProgress::BOOK_STAGES[$cix + 1] ?? null);
                        @endphp
                        <div class="border rounded p-2 mb-1" style="font-size:11px">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="fw-semibold text-truncate" style="max-width:150px">{{ $ch->urutan }}. {{ $ch->judul }}</span>
                                <span class="badge {{ in_array($cstatus, \App\Models\TitleProgress::FINAL_STAGES, true) ? 'bg-success' : 'bg-info' }}">{{ \App\Models\Title::stageLabel($cstatus) }}</span>
                            </div>
                            <div class="text-muted mt-1">Editor: {{ optional(optional($cp)->assignedUser)->name ?? 'Belum' }}</div>
                            <div class="text-muted">Author: {{ $ch->authors->pluck('name')->join(', ') ?: '—' }}</div>
                            @if($cp)
                            <div class="d-flex gap-1 mt-1">
                                @if($cnext)
                                    <button type="button" class="btn btn-xs btn-outline-primary py-0" data-chapter-advance data-cp="{{ $cp->id }}" data-next="{{ $cnext }}" style="font-size:10px">Maju → {{ \App\Models\Title::stageLabel($cnext) }}</button>
                                @endif
                                @unless(\App\Models\TitleProgress::isFinal($cstatus) && ! auth()->user()->hasRole('superadmin'))
                                <button type="button" class="btn btn-xs btn-outline-secondary py-0"
                                        data-chapter-edit data-cp="{{ $cp->id }}"
                                        data-current="{{ $cstatus }}" data-next="{{ $cnext ?? '' }}"
                                        data-judul="{{ $ch->urutan }}. {{ $ch->judul }}"
                                        style="font-size:10px">Ubah…</button>
                                @endunless
                                <select class="form-select form-select-sm py-0" data-chapter-assign data-cp="{{ $cp->id }}" style="font-size:10px; max-width:130px">
                                    <option value="">Editor…</option>
                                    @foreach($editors as $ed)
                                        <option value="{{ $ed->id }}" {{ optional($cp)->assigned_user_id == $ed->id ? 'selected' : '' }}>{{ $ed->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>
