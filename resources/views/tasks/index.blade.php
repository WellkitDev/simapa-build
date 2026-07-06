@extends('layouts.master')
@section('title', 'Daftar Tugas - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@php
    $sb = ['todo' => 'bg-secondary', 'in_progress' => 'bg-warning text-dark', 'done' => 'bg-success'];
    $sl = ['todo' => 'Menunggu', 'in_progress' => 'Dikerjakan', 'done' => 'Selesai'];
    $prioBadge = ['high' => 'bg-danger', 'normal' => 'bg-secondary', 'low' => 'bg-info'];
    $prioLabel = ['high' => 'Tinggi', 'normal' => 'Normal', 'low' => 'Rendah'];
@endphp

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0">Daftar Tugas</h5>
        <small class="text-muted">{{ $owner->name }}{{ $owner->id === auth()->id() ? ' (saya)' : '' }}</small>
    </div>
    <div class="d-flex gap-2">
        <div class="btn-group btn-group-sm">
            <a href="{{ route('task.board') }}" class="btn btn-outline-primary">Board</a>
            <a href="{{ route('task.calendar') }}" class="btn btn-outline-primary">Kalender</a>
            <a href="{{ route('task.index') }}" class="btn btn-primary">Todo</a>
        </div>
        <button class="btn btn-sm btn-primary" data-add-task data-status="todo">+ Tambah</button>
    </div>
</div>

<div class="card"><div class="card-body"><div class="table-responsive">
    <table class="table table-hover datatable" style="width:100%">
        <thead><tr><th>Judul</th><th>Status</th><th>Prioritas</th><th>Tenggat</th><th>Aksi</th></tr></thead>
        <tbody>
            @foreach($tasks as $task)
            <tr>
                <td>{{ $task->title }}</td>
                <td><span class="badge {{ $sb[$task->status] }}">{{ $sl[$task->status] }}</span></td>
                <td><span class="badge {{ $prioBadge[$task->priority] }}">{{ $prioLabel[$task->priority] }}</span></td>
                <td>@if($task->due_date)<span class="@if($task->due_date->isPast() && $task->status !== 'done') text-danger fw-semibold @endif">{{ $task->due_date->format('d/m/Y') }}</span>@else<span class="text-muted">—</span>@endif</td>
                <td>
                    @if($task->status !== 'done')
                        <button class="btn btn-xs btn-outline-success" data-complete data-id="{{ $task->id }}">Selesai</button>
                    @endif
                    <button class="btn btn-xs btn-outline-primary" data-edit-task data-id="{{ $task->id }}" data-title="{{ $task->title }}" data-description="{{ $task->description }}" data-priority="{{ $task->priority }}" data-due="{{ optional($task->due_date)->toDateString() }}" data-assignee="{{ $task->user_id }}">Edit</button>
                    <form action="{{ route('task.destroy', $task->id) }}" method="POST" class="d-inline" data-confirm="Hapus tugas ini?">@csrf @method('DELETE')<button class="btn btn-xs btn-outline-danger">Hapus</button></form>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div></div></div>

@include('tasks.partials.form-modal')
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
<script src="{{ asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
$(function () {
    $('.datatable').DataTable({ pageLength: 10, order: [], language: { emptyTable: 'Belum ada tugas.' } });
    const token = document.querySelector('meta[name="_token"]').getAttribute('content');
    document.querySelectorAll('[data-add-task]').forEach(function (b) { b.addEventListener('click', function () { window.openTaskModal({ status: 'todo' }); }); });
    document.querySelectorAll('[data-edit-task]').forEach(function (b) {
        b.addEventListener('click', function () { window.openTaskModal({ id: b.dataset.id, title: b.dataset.title, description: b.dataset.description, priority: b.dataset.priority, due: b.dataset.due, assignee: b.dataset.assignee }); });
    });
    document.querySelectorAll('[data-complete]').forEach(function (b) {
        b.addEventListener('click', function () {
            fetch("{{ url('tasks') }}/" + b.dataset.id + "/status", { method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify({ status: 'done', position: 0 }) })
                .then(function (r) { if (r.ok) location.reload(); });
        });
    });
});
</script>
@endpush
