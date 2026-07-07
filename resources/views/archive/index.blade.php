@extends('layouts.master')
@section('title', 'Arsip Judul - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
<h5 class="mb-3">Arsip Judul</h5>

@if($canApprove && $pending->isNotEmpty())
<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card border-warning"><div class="card-body">
    <h6 class="card-title">Menunggu Persetujuan ({{ $pending->count() }})</h6>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr><th>Kode</th><th>Judul</th><th>Jenis</th><th>Diajukan</th><th>Aksi</th></tr></thead>
            <tbody>
                @foreach($pending as $t)
                    <tr>
                        <td>{{ $t->code ?? '—' }}</td>
                        <td class="dt-judul">{{ $t->title }}</td>
                        <td>{{ ucfirst($t->jenis) }}</td>
                        <td>{{ $t->archive->submitter?->name ?? '—' }} · {{ optional($t->archive->submitted_at)->format('d M Y') }}</td>
                        <td><a href="{{ route('archive.show', $t->id) }}" class="btn btn-xs btn-warning">Tinjau</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div></div></div></div>
@endif

<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <h6 class="card-title">Judul Selesai (Arsip)</h6>
    <div class="table-responsive">
        <table class="table table-hover datatable" style="width:100%">
            <thead><tr><th>Kode</th><th>Judul</th><th>Jenis</th><th>Disetujui</th><th>Approver</th><th>Aksi</th></tr></thead>
            <tbody>
                @foreach($approved as $t)
                    <tr>
                        <td>{{ $t->code ?? '—' }}</td>
                        <td class="dt-judul">{{ $t->title }}</td>
                        <td>{{ ucfirst($t->jenis) }}</td>
                        <td>{{ optional($t->archive->approved_at)->format('d M Y') ?? '—' }}</td>
                        <td>{{ $t->archive->approver?->name ?? '—' }}</td>
                        <td><a href="{{ route('archive.show', $t->id) }}" class="btn btn-xs btn-outline-primary">Lihat</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div></div></div></div>
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
<script src="{{ asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>$(function () { $('.datatable').DataTable({ pageLength: 10, order: [], language: { emptyTable: 'Belum ada judul di arsip.' } }); });</script>
@endpush
