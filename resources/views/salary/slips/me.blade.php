@extends('layouts.master')
@section('title', 'Slip Gaji Saya - SiMAPA')

@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp

<h5 class="mb-3">Slip Gaji Saya</h5>
<div class="card"><div class="card-body">
    <div class="table-responsive">
        <table class="table table-sm table-hover datatable" style="width:100%">
            <thead><tr><th>No. Slip</th><th>Periode</th><th class="text-end">Gaji Bersih</th><th>Aksi</th></tr></thead>
            <tbody>
            @foreach ($slips as $slip)
                <tr>
                    <td>{{ $slip->slip_no }}</td>
                    <td>{{ $slip->periodLabel() }}</td>
                    <td class="text-end fw-bold">{{ $rp($slip->net_pay) }}</td>
                    <td><a href="{{ route('salary.slip.me.pdf', $slip->id) }}" target="_blank" class="btn btn-xs btn-outline-primary">Unduh PDF</a></td>
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
@endpush
@push('custom-scripts')
    <script>$(function () { $('.datatable').DataTable({ pageLength: 25, order: [] }); });</script>
@endpush
