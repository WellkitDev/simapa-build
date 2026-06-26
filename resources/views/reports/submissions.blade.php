@extends('layouts.master')
@section('title', 'Pemantauan Report - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
<link href="{{ asset('assets/plugins/flatpickr/flatpickr.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@php $sudah = $rows->where('submitted', true)->count(); $belum = $rows->count() - $sudah; @endphp
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0">Pemantauan Report</h5>
        <small class="text-muted">{{ $date->translatedFormat('l, d M Y') }}</small>
    </div>
    <form method="GET" class="d-flex gap-1 align-items-center">
        <input type="text" name="date" id="subDate" value="{{ $date->toDateString() }}" class="form-control form-control-sm" style="width:140px">
        <button class="btn btn-sm btn-primary">Lihat</button>
    </form>
</div>

<div class="row g-2 mb-3">
    <div class="col"><div class="card"><div class="card-body py-2 text-center"><div class="text-muted" style="font-size:11px">Sudah kirim</div><div class="fw-bold text-success" style="font-size:20px">{{ $sudah }}</div></div></div></div>
    <div class="col"><div class="card"><div class="card-body py-2 text-center"><div class="text-muted" style="font-size:11px">Belum kirim</div><div class="fw-bold text-danger" style="font-size:20px">{{ $belum }}</div></div></div></div>
</div>

<div class="card"><div class="card-body">
    <div class="table-responsive">
        <table class="table table-hover datatable" style="width:100%">
            <thead><tr><th>Karyawan</th><th>Status</th><th>Selesai</th><th>Bukti</th><th></th></tr></thead>
            <tbody>
                @foreach($rows as $row)
                    <tr>
                        <td>{{ $row['name'] }}</td>
                        <td>@if($row['submitted'])<span class="badge bg-success">Sudah kirim</span>@else<span class="badge bg-secondary">Belum</span>@endif</td>
                        <td>{{ $row['selesai'] }}</td>
                        <td>@if($row['bukti'])<span class="badge bg-info">{{ $row['bukti'] }}</span>@else<span class="text-muted">0</span>@endif</td>
                        <td><a href="{{ route('report.daily', ['user_id' => $row['id'], 'date' => $date->toDateString()]) }}" class="btn btn-xs btn-outline-primary">Buka report</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div></div>
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/plugins/flatpickr/flatpickr.min.js') }}"></script>
<script src="{{ asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
<script src="{{ asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>$(function () { if (window.flatpickr) flatpickr('#subDate', { dateFormat: 'Y-m-d' }); $('.datatable').DataTable({ pageLength: 15, order: [], language: { emptyTable: 'Belum ada karyawan.' } }); });</script>
@endpush
