@extends('layouts.master')
@section('title', 'Riwayat Perubahan - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Riwayat Perubahan Kas</h5>
    <form method="GET" class="d-flex gap-2 align-items-center">
        <input type="number" name="year" value="{{ $year }}" class="form-control form-control-sm" style="width:90px">
        <button class="btn btn-sm btn-primary">Tampilkan</button>
    </form>
</div>

<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <p class="text-muted small mb-3">
        Jejak setiap perubahan entri kas dan penguncian periode. Perubahan bertanda
        <span class="badge bg-warning text-dark">periode terkunci</span> terjadi lewat sinkron pembayaran —
        satu-satunya jalur yang menembus kunci (jurnal adalah cerminan pembayaran, bukan sumbernya).
    </p>
    <div class="table-responsive">
        <table class="table table-sm table-hover datatable">
            <thead><tr><th>Waktu</th><th>Aksi</th><th>Entri</th><th>Pelaku</th><th>Perubahan</th><th>Catatan</th></tr></thead>
            <tbody>
                @foreach($logs as $log)
                    <tr>
                        <td>{{ $log->created_at->format('d/m/y H:i') }}</td>
                        <td>{{ $log->actionLabel() }}</td>
                        <td>{{ $log->cash_entry_id ? '#' . $log->cash_entry_id : '—' }}</td>
                        <td>{{ $log->actorName() }}</td>
                        <td class="small text-muted" style="max-width:420px;word-break:break-word">
                            {{ $log->changes ? json_encode($log->changes, JSON_UNESCAPED_UNICODE) : '—' }}
                        </td>
                        <td>@if($log->note)<span class="badge bg-warning text-dark">{{ $log->note }}</span>@endif</td>
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
<script>$(function () { $('.datatable').DataTable({ pageLength: 25, order: [[0, 'desc']], language: { emptyTable: 'Belum ada perubahan tercatat.' } }); });</script>
@endpush
