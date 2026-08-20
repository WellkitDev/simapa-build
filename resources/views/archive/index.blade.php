@extends('layouts.master')
@section('title', 'Arsip Judul - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
<link href="{{ asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
<h5 class="mb-3">Arsip Judul</h5>

{{-- Pintu masuk arsip: judul final yang belum punya baris arsip sama sekali. --}}
@if($siap->isNotEmpty())
<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card border-info"><div class="card-body">
    <h6 class="card-title">Siap Diarsipkan ({{ $siap->count() }})</h6>
    <p class="text-muted small mb-2">Naskahnya sudah final. Lengkapi artefaknya lalu ajukan ke arsip.</p>
    <div class="table-responsive">
        <table class="table table-hover datatable dt-responsive nowrap" style="width:100%">
            <thead><tr><th>Kode</th><th>Judul</th><th>Jenis</th><th>Naskah</th><th>Pembayaran</th><th>Aksi</th></tr></thead>
            <tbody>
                @foreach($siap as $t)
                    @php $sisa = $t->sisaTagihan(); $ditarik = $t->jumlahDitarik(); @endphp
                    <tr>
                        <td>{{ $t->code ?? '—' }}</td>
                        <td class="dt-judul">{{ $t->title }}</td>
                        <td>{{ ucfirst($t->jenis) }}</td>
                        <td><span class="badge bg-success">{{ $t->manuscriptStatusLabel() }}</span></td>
                        <td>
                            @if($sisa > 0)
                                <span class="badge bg-danger">Kurang Rp {{ number_format($sisa, 0, ',', '.') }}</span>
                            @else
                                <span class="badge bg-success">Lunas</span>
                            @endif
                            @if($ditarik > 0)
                                <span class="badge bg-secondary">{{ $ditarik }} ditarik</span>
                            @endif
                        </td>
                        <td><a href="{{ route('archive.show', $t->id) }}" class="btn btn-xs btn-info">Siapkan</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div></div></div></div>
@endif

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
        <table class="table table-hover datatable dt-responsive nowrap" style="width:100%">
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
<script src="{{ asset('assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js') }}"></script>
<script src="{{ asset('assets/libs/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>$(function () { $('.datatable').DataTable({ pageLength: 10, responsive: true, order: [], language: { emptyTable: 'Belum ada judul di arsip.' } }); });</script>
@endpush
