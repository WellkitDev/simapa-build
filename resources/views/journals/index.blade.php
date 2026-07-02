@extends('layouts.master')
@section('title', 'Direktori Jurnal - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Direktori Jurnal</h5>
    @if($canManage)
        <a href="{{ route('journal.create') }}" class="btn btn-sm btn-primary">Tambah Jurnal</a>
    @endif
</div>

<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <div class="table-responsive">
        <table class="table table-hover datatable" style="width:100%">
            <thead><tr><th>Jurnal</th><th>Akreditasi</th><th>Terbitan</th><th>Scope</th><th>APC Reguler</th><th>Fastrack</th><th>Link</th><th>Aksi</th></tr></thead>
            <tbody>
                @foreach($journals as $j)
                    <tr>
                        <td>{{ $j->nama }}</td>
                        <td>@if($j->akreditasi)<span class="badge bg-dark">{{ $j->akreditasi }}</span>@else<span class="text-muted">—</span>@endif</td>
                        <td>@forelse($j->terbitanLabels() as $m)<span class="badge bg-light text-dark border me-1">{{ $m }}</span>@empty<span class="text-muted">—</span>@endforelse</td>
                        <td>{{ $j->scope?->scope ?? '—' }}</td>
                        <td>{{ $j->apc_reguler ?: '—' }}</td>
                        <td>{{ $j->apc_fastrack ?: '—' }}</td>
                        <td>@if($j->link)<a href="{{ $j->link }}" target="_blank" rel="noopener">buka</a>@else—@endif</td>
                        <td>
                            <a href="{{ route('journal.show', $j->id) }}" class="btn btn-xs btn-outline-primary">Lihat</a>
                            @if($canManage)
                                <a href="{{ route('journal.edit', $j->id) }}" class="btn btn-xs btn-outline-secondary">Edit</a>
                                <form action="{{ route('journal.destroy', $j->id) }}" method="POST" class="d-inline m-0" data-confirm="Hapus jurnal ini?">@csrf @method('DELETE')<button class="btn btn-xs btn-outline-danger">Hapus</button></form>
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
<script>$(function () { $('.datatable').DataTable({ pageLength: 10, order: [], language: { emptyTable: 'Belum ada jurnal.' } }); });</script>
@endpush
