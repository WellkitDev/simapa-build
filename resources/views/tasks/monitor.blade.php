@extends('layouts.master')
@section('title', 'Pemantauan Tugas - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
<link href="{{ asset('assets/plugins/select2/select2.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@php
    $sb = ['todo' => 'bg-secondary', 'in_progress' => 'bg-warning text-dark', 'done' => 'bg-success'];
    $sl = ['todo' => 'Menunggu', 'in_progress' => 'Dikerjakan', 'done' => 'Selesai'];
    $prioBadge = ['high' => 'bg-danger', 'normal' => 'bg-secondary', 'low' => 'bg-info'];
    $prioLabel = ['high' => 'Tinggi', 'normal' => 'Normal', 'low' => 'Rendah'];
@endphp

<h5 class="mb-3">Pemantauan Tugas</h5>

<div class="row g-2 mb-3">
    @foreach(['total' => 'Total', 'todo' => 'Menunggu', 'in_progress' => 'Dikerjakan', 'done' => 'Selesai', 'overdue' => 'Lewat tenggat'] as $k => $lbl)
        <div class="col"><div class="card"><div class="card-body py-2 text-center">
            <div class="text-muted" style="font-size:11px">{{ $lbl }}</div>
            <div class="fw-bold {{ $k === 'overdue' ? 'text-danger' : '' }}" style="font-size:20px">{{ $kpi[$k] }}</div>
        </div></div></div>
    @endforeach
</div>

<div class="card"><div class="card-body">
    <form method="GET" class="row g-2 mb-3">
        <div class="col-md-4">
            <select name="user_id" class="form-select form-select-sm select2-filter">
                <option value="">Semua karyawan</option>
                @foreach($employees as $e)<option value="{{ $e->id }}" {{ (string) $fUser === (string) $e->id ? 'selected' : '' }}>{{ $e->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-4">
            <select name="status" class="form-select form-select-sm">
                <option value="">Semua status</option>
                @foreach($sl as $k => $v)<option value="{{ $k }}" {{ $fStatus === $k ? 'selected' : '' }}>{{ $v }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Filter</button></div>
    </form>
    <div class="table-responsive">
        <table class="table table-hover datatable" style="width:100%">
            <thead><tr><th>Karyawan</th><th>Judul</th><th>Status</th><th>Prioritas</th><th>Tenggat</th><th></th></tr></thead>
            <tbody>
                @foreach($rows as $task)
                <tr>
                    <td>{{ $task->user?->name ?? '—' }}</td>
                    <td class="dt-judul">{{ $task->title }}</td>
                    <td><span class="badge {{ $sb[$task->status] }}">{{ $sl[$task->status] }}</span></td>
                    <td><span class="badge {{ $prioBadge[$task->priority] }}">{{ $prioLabel[$task->priority] }}</span></td>
                    <td>@if($task->due_date)<span class="@if($task->due_date->isPast() && $task->status !== 'done') text-danger fw-semibold @endif">{{ $task->due_date->format('d/m/Y') }}</span>@else<span class="text-muted">—</span>@endif</td>
                    <td><a href="{{ route('task.board', ['user_id' => $task->user_id]) }}" class="btn btn-xs btn-outline-primary">Board</a></td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div></div>
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
<script src="{{ asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
<script src="{{ asset('assets/plugins/select2/select2.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
$(function () {
    $('.datatable').DataTable({ pageLength: 15, order: [], language: { emptyTable: 'Belum ada tugas.' } });
    $('.select2-filter').select2();
});
</script>
@endpush
