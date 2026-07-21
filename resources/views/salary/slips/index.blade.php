@extends('layouts.master')
@section('title', 'Slip Gaji - SiMAPA')

@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Slip Gaji Karyawan</h5>
    @can('salary.create')
        <a href="{{ route('salary.slip.create') }}" class="btn btn-sm btn-primary">+ Buat Slip</a>
    @endcan
</div>

<div class="card mb-3"><div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-auto">
            <label class="form-label small mb-1">Tahun</label>
            <select name="year" class="form-select form-select-sm">
                @foreach ($years as $y)
                    <option value="{{ $y }}" @selected($year == $y)>{{ $y }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <label class="form-label small mb-1">Bulan</label>
            <select name="month" class="form-select form-select-sm">
                <option value="all" @selected($month === null)>Semua</option>
                @foreach (\App\Models\SalarySlip::MONTHS as $num => $label)
                    <option value="{{ $num }}" @selected($month === $num)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <label class="form-label small mb-1">Karyawan</label>
            <select name="employee" class="form-select form-select-sm">
                <option value="all">Semua</option>
                @foreach ($employees as $emp)
                    <option value="{{ $emp->id }}" @selected($employeeId === $emp->id)>{{ $emp->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <label class="form-label small mb-1">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="all">Semua</option>
                <option value="draft"  @selected($status === 'draft')>Draft</option>
                <option value="terbit" @selected($status === 'terbit')>Terbit</option>
            </select>
        </div>
        <div class="col-auto">
            <button class="btn btn-sm btn-outline-primary">Filter</button>
            <a href="{{ route('salary.slip.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
        </div>
    </form>
</div></div>

<div class="card"><div class="card-body">
    <div class="table-responsive">
        <table class="table table-sm table-hover datatable dt-responsive nowrap" style="width:100%">
            <thead><tr>
                <th>No. Slip</th><th>Karyawan</th><th>Periode</th>
                <th class="text-end">Penghasilan</th><th class="text-end">Potongan</th><th class="text-end">Gaji Bersih</th>
                <th>Status</th><th>Aksi</th>
            </tr></thead>
            <tbody>
            @foreach ($slips as $slip)
                <tr>
                    <td>{{ $slip->slip_no }}</td>
                    <td class="dt-judul">{{ $slip->employee_name }}</td>
                    <td>{{ $slip->periodLabel() }}</td>
                    <td class="text-end">{{ $rp($slip->total_earnings) }}</td>
                    <td class="text-end">{{ $rp($slip->total_deductions) }}</td>
                    <td class="text-end fw-bold">{{ $rp($slip->net_pay) }}</td>
                    <td>
                        <span class="badge {{ $slip->status === 'terbit' ? 'bg-success' : 'bg-secondary' }}">
                            {{ \App\Models\SalarySlip::STATUS[$slip->status] ?? $slip->status }}
                        </span>
                    </td>
                    <td class="text-nowrap">
                        <a href="{{ route('salary.slip.show', $slip->id) }}" class="btn btn-xs btn-outline-primary">Lihat</a>
                        @can('salary.edit')
                            @if ($slip->isDraft())
                                <a href="{{ route('salary.slip.edit', $slip->id) }}" class="btn btn-xs btn-outline-secondary">Edit</a>
                            @endif
                        @endcan
                        @can('salary.export')
                            <a href="{{ route('salary.slip.pdf', $slip->id) }}" target="_blank" class="btn btn-xs btn-outline-dark">PDF</a>
                        @endcan
                        @can('salary.send')
                            <form method="POST" action="{{ route('salary.slip.send', $slip->id) }}" class="d-inline" data-confirm="Kirim slip ke email karyawan?">
                                @csrf @idempotent
                                <button class="btn btn-xs btn-outline-info">Kirim</button>
                            </form>
                        @endcan
                        @can('salary.delete')
                            <form method="POST" action="{{ route('salary.slip.destroy', $slip->id) }}" class="d-inline" data-confirm="Hapus slip ini?">
                                @csrf @method('DELETE')
                                <button class="btn btn-xs btn-outline-danger">Hapus</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div></div>
@endsection

@push('plugin-scripts')
    <script src="{{ URL::asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
    <script>$(function () { $('.datatable').DataTable({ pageLength: 25, responsive: true, order: [] }); });</script>
@endpush
