@extends('layouts.master')
@section('title', 'Direktori Judul - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@php
    $sb = ['draft' => 'bg-secondary', 'menunggu' => 'bg-warning text-dark', 'disetujui' => 'bg-success', 'ditolak' => 'bg-danger'];
    $sl = ['draft' => 'Draf', 'menunggu' => 'Menunggu', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak'];
@endphp
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Direktori Judul</h5>
    @if($canManage)
        <a href="{{ route('title.create') }}" class="btn btn-sm btn-primary">Buat Judul</a>
    @endif
</div>

<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <div class="table-responsive">
        <table class="table table-hover datatable" style="width:100%">
            <thead><tr><th>Judul</th><th>Jenis</th><th>Indeksasi</th><th>Tipe</th><th>Status</th><th>Pembuat</th><th>Aksi</th></tr></thead>
            <tbody>
                @foreach($titles as $t)
                    <tr>
                        <td>{{ $t->title }}</td>
                        <td>{{ ucfirst($t->jenis) }}</td>
                        <td>{{ $t->indeksasi ?: '—' }}</td>
                        <td>{{ ucfirst($t->tipe_naskah) }}</td>
                        <td><span class="badge {{ $sb[$t->status] ?? 'bg-secondary' }}">{{ $sl[$t->status] ?? $t->status }}</span></td>
                        <td><small>{{ $t->creator?->name ?? '—' }}</small></td>
                        <td>
                            <a href="{{ route('title.show', $t->id) }}" class="btn btn-xs btn-outline-primary">Lihat</a>
                            @if($canManage && $t->isEditable())
                                <a href="{{ route('title.edit', $t->id) }}" class="btn btn-xs btn-outline-secondary">Edit</a>
                                <form action="{{ route('title.submit', $t->id) }}" method="POST" class="d-inline m-0">@csrf<button class="btn btn-xs btn-outline-info">Ajukan</button></form>
                            @endif
                            @if($isApprover && $t->status === 'menunggu')
                                <form action="{{ route('title.approve', $t->id) }}" method="POST" class="d-inline m-0">@csrf<button class="btn btn-xs btn-outline-success">Setujui</button></form>
                            @endif
                        </td>
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
<script>$(function () { $('.datatable').DataTable({ pageLength: 10, order: [], language: { emptyTable: 'Belum ada judul.' } }); });</script>
@endpush
