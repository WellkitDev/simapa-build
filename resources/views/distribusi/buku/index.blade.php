@extends('layouts.master')
@section('title', 'Distribusi Buku - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
<link href="{{ asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <h6 class="card-title">Distribusi Naskah — Buku</h6>
    <div class="table-responsive">
        <table class="table table-hover datatable dt-responsive nowrap" style="width:100%">
            <thead>
                <tr>
                    <th>Kode</th><th>Judul</th><th>Jenis</th><th>Status</th><th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($titles as $t)
                    <tr>
                        <td><span class="badge bg-dark">{{ $t->code ?? '—' }}</span></td>
                        <td class="dt-judul">{{ $t->title }}</td>
                        <td><span class="badge bg-light text-dark">{{ ucfirst($t->tipe_naskah) }}</span></td>
                        <td>{{ $t->manuscriptStatusLabel() ?? '—' }}</td>
                        <td><a href="{{ route('distribusi.buku.show', $t->id) }}" class="btn btn-xs btn-outline-primary">Distribusi</a></td>
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
<script src="{{ asset('assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js') }}"></script>
<script src="{{ asset('assets/libs/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>$(function () { $('.datatable').DataTable({ pageLength: 10, responsive: true, order: [], language: { emptyTable: 'Belum ada buku.' } }); });</script>
@endpush
