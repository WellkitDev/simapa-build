@extends('layouts.master')
@section('title', 'Tugas - Kalender - SiMAPA')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0">Kalender Tugas</h5>
        <small class="text-muted">{{ $owner->name }}{{ $owner->id === auth()->id() ? ' (saya)' : '' }}</small>
    </div>
    <div class="btn-group btn-group-sm">
        <a href="{{ route('task.board') }}" class="btn btn-outline-primary">Board</a>
        <a href="{{ route('task.calendar') }}" class="btn btn-primary">Kalender</a>
        <a href="{{ route('task.index') }}" class="btn btn-outline-primary">Todo</a>
    </div>
</div>
<div class="card"><div class="card-body"><div id="taskCalendar"></div></div></div>
@include('tasks.partials.form-modal')
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/plugins/fullcalendar/index.global.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
(function () {
    const token = document.querySelector('meta[name="_token"]').getAttribute('content');
    const el = document.getElementById('taskCalendar');
    if (!el || !window.FullCalendar) return;
    const cal = new FullCalendar.Calendar(el, {
        initialView: 'dayGridMonth',
        locale: 'id',
        height: 'auto',
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,listMonth' },
        events: "{{ route('task.events') }}",
        editable: true,
        dateClick: function (info) { window.openTaskModal({ due: info.dateStr }); },
        eventClick: function (info) {
            window.openTaskModal({
                id: info.event.id, title: info.event.title, due: info.event.startStr,
                priority: info.event.extendedProps.priority,
                description: info.event.extendedProps.description,
                assignee: info.event.extendedProps.assignee,
                status: info.event.extendedProps.status
            });
        },
        eventDrop: function (info) {
            fetch("{{ url('tasks') }}/" + info.event.id + "/schedule", {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ due_date: info.event.startStr })
            }).then(function (r) { if (!r.ok) info.revert(); }).catch(function () { info.revert(); });
        }
    });
    cal.render();
})();
</script>
@endpush
