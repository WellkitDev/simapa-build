@extends('layouts.master')
@section('title', 'Tugas - Board - SiMAPA')

@section('content')
@php
    $cols = ['todo' => ['Menunggu', '#94A3B8'], 'in_progress' => ['Dikerjakan', '#F59E0B'], 'done' => ['Selesai', '#22C55E']];
    $prioBadge = ['high' => 'bg-danger', 'normal' => 'bg-secondary', 'low' => 'bg-info'];
    $prioLabel = ['high' => 'Tinggi', 'normal' => 'Normal', 'low' => 'Rendah'];
@endphp

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0">Papan Tugas</h5>
        <small class="text-muted">{{ $owner->name }}{{ $owner->id === auth()->id() ? ' (saya)' : '' }}</small>
    </div>
    <div class="btn-group btn-group-sm">
        <a href="{{ route('task.board') }}" class="btn btn-primary">Board</a>
        <a href="{{ route('task.calendar') }}" class="btn btn-outline-primary">Kalender</a>
        <a href="{{ route('task.index') }}" class="btn btn-outline-primary">Todo</a>
    </div>
</div>

<div class="row g-3">
    @foreach($cols as $key => [$label, $color])
        <div class="col-md-4">
            <div class="card h-100"><div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <span class="d-flex align-items-center gap-2">
                        <span style="width:9px;height:9px;border-radius:50%;background:{{ $color }}"></span>
                        <strong>{{ $label }}</strong>
                        <span class="badge bg-light text-muted" data-count>{{ $board[$key]->count() }}</span>
                    </span>
                    <button class="btn btn-xs btn-outline-primary" data-add-task data-status="{{ $key }}">+ Tambah</button>
                </div>
                <div data-column data-status="{{ $key }}" style="min-height:80px">
                    @foreach($board[$key] as $task)
                        @php $locked = $task->is_locked ?? false; @endphp
                        <div class="card mb-2 task-card {{ $locked ? 'task-locked' : '' }}" data-id="{{ $task->id }}" style="cursor:{{ $locked ? 'not-allowed' : 'grab' }};{{ $locked ? 'opacity:.75' : '' }}">
                            <div class="card-body p-2">
                                <div class="d-flex justify-content-between align-items-start">
                                    <span class="fw-semibold" style="font-size:13px">
                                        @if($locked)<i data-feather="lock" class="icon-xs me-1 text-muted"></i>@endif{{ $task->title }}
                                    </span>
                                    @unless($locked)
                                        <div class="dropdown">
                                            <button class="btn btn-xs p-0 border-0 bg-transparent" data-bs-toggle="dropdown" aria-label="Menu"><i data-feather="more-vertical" class="icon-sm"></i></button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li><button class="dropdown-item" type="button" data-edit-task
                                                    data-id="{{ $task->id }}" data-title="{{ $task->title }}"
                                                    data-description="{{ $task->description }}" data-priority="{{ $task->priority }}"
                                                    data-due="{{ optional($task->due_date)->toDateString() }}" data-assignee="{{ $task->user_id }}">Edit</button></li>
                                                <li>
                                                    <form action="{{ route('task.destroy', $task->id) }}" method="POST" data-confirm="Hapus tugas ini?">@csrf @method('DELETE')
                                                        <button class="dropdown-item text-danger">Hapus</button>
                                                    </form>
                                                </li>
                                            </ul>
                                        </div>
                                    @endunless
                                </div>
                                <div class="mt-1 d-flex gap-1 align-items-center flex-wrap">
                                    <span class="badge {{ $prioBadge[$task->priority] }}">{{ $prioLabel[$task->priority] }}</span>
                                    @if($task->due_date)
                                        <span class="badge {{ $task->due_date->isPast() && $task->status !== 'done' ? 'bg-danger' : 'bg-light text-muted' }}">{{ $task->due_date->format('d M') }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div></div>
        </div>
    @endforeach
</div>

@include('tasks.partials.form-modal')
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/plugins/sortablejs/Sortable.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
(function () {
    const token = document.querySelector('meta[name="_token"]').getAttribute('content');
    function send(url, method, body) {
        return fetch(url, { method: method, headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify(body) });
    }
    function refreshCounts() {
        document.querySelectorAll('[data-column]').forEach(function (col) {
            const badge = col.parentElement.querySelector('[data-count]');
            if (badge) badge.textContent = col.querySelectorAll('[data-id]').length;
        });
    }
    document.querySelectorAll('[data-column]').forEach(function (col) {
        new Sortable(col, {
            group: 'tasks', animation: 150, ghostClass: 'opacity-50', filter: '.task-locked',
            onEnd: function (evt) {
                const id = evt.item.getAttribute('data-id');
                const toStatus = evt.to.getAttribute('data-status');
                const fromStatus = evt.from.getAttribute('data-status');
                const ids = Array.from(evt.to.querySelectorAll('[data-id]')).map(function (n) { return n.getAttribute('data-id'); });
                if (toStatus === fromStatus) {
                    send("{{ route('task.reorder') }}", 'POST', { status: toStatus, ids: ids })
                        .then(function (r) { if (!r.ok) location.reload(); }).catch(function () { location.reload(); });
                } else {
                    send("{{ url('tasks') }}/" + id + "/status", 'PATCH', { status: toStatus, position: ids.indexOf(id) })
                        .then(function (r) { if (!r.ok) location.reload(); }).catch(function () { location.reload(); });
                    refreshCounts();
                }
            }
        });
    });
    document.querySelectorAll('[data-add-task]').forEach(function (b) {
        b.addEventListener('click', function () { window.openTaskModal({ status: b.getAttribute('data-status') }); });
    });
    document.querySelectorAll('[data-edit-task]').forEach(function (b) {
        b.addEventListener('click', function () {
            window.openTaskModal({ id: b.dataset.id, title: b.dataset.title, description: b.dataset.description, priority: b.dataset.priority, due: b.dataset.due, assignee: b.dataset.assignee });
        });
    });
})();
</script>
@endpush
