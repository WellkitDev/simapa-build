@extends('layouts.master')
@section('title', 'Direktori ISBN - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
<link href="{{ asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Direktori ISBN</h5>
</div>

<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <p class="text-muted small mb-3">Buku yang manuskripnya telah mencapai tahap ISBN. E-book dan sertifikat bisa diunduh langsung dari baris masing-masing.</p>
    <div class="table-responsive">
        <table class="table table-hover datatable dt-responsive nowrap" style="width:100%">
            <thead><tr><th>Kode</th><th>Judul</th><th>No. ISBN</th><th>E-book</th><th>Sertifikat</th><th>Link Terbit</th><th>Status</th><th>Penerbit</th><th>Tgl ISBN</th><th>Aksi</th></tr></thead>
            <tbody>
                @foreach($books as $b)
                    @php
                        $ebook      = $berkas[$b->id . ':ebook'] ?? null;
                        $sertifikat = $berkas[$b->id . ':sertifikat_isbn'] ?? null;
                    @endphp
                    <tr>
                        <td>{{ $b->code ?? '—' }}</td>
                        <td class="dt-judul">{{ $b->title }}</td>
                        <td>{{ $b->bookIsbn?->no_isbn ?: '—' }}</td>
                        <td>@if($ebook && $ebook->drive_url)<a href="{{ $ebook->drive_url }}" target="_blank" rel="noopener">Unduh</a>@else—@endif</td>
                        <td>@if($sertifikat && $sertifikat->drive_url)<a href="{{ $sertifikat->drive_url }}" target="_blank" rel="noopener">Unduh</a>@else—@endif</td>
                        <td>@if($b->bookIsbn?->link_terbit)<a href="{{ $b->bookIsbn->link_terbit }}" target="_blank" rel="noopener">Buka</a>@else—@endif</td>
                        <td>@if($b->bookIsbn)<span class="badge bg-info">{{ $b->bookIsbn->statusLabel() }}</span>@else<span class="badge bg-light text-dark border">Belum didaftarkan</span>@endif</td>
                        <td>{{ $b->bookIsbn?->penerbit ?: '—' }}</td>
                        <td>{{ optional($b->bookIsbn?->tgl_isbn)->format('d M Y') ?? '—' }}</td>
                        <td>@if($canManage)<a href="{{ route('title.show', $b->id) }}" class="btn btn-xs btn-outline-primary">Kelola</a>@else<span class="text-muted">—</span>@endif</td>
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
<script>$(function () { $('.datatable').DataTable({ pageLength: 10, responsive: true, order: [], language: { emptyTable: 'Belum ada buku yang mencapai tahap ISBN.' } }); });</script>
@endpush
