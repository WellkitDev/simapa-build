@extends('layouts.master')
@section('title', 'Daftar Tagihan - SiMAPA')

@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@php
    $statusColors = [
        'diajukan' => 'secondary', 'disetujui' => 'success', 'ditolak' => 'danger',
        'jadi_order' => 'primary', 'dibatalkan' => 'dark',
    ];
@endphp
<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card overflow-hidden">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-baseline mb-3">
                    <h6 class="card-title mb-0">Daftar Tagihan</h6>
                    <a href="{{ route('tagihan.create') }}" class="btn btn-sm btn-primary">+ Buat Tagihan</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-centered datatable dt-responsive nowrap" style="width:100%">
                        <thead>
                            <tr>
                                <th>No Tagihan</th><th>Klien</th><th>Judul</th><th>Tipe</th>
                                <th>Nominal</th><th>Status</th><th>Jatuh Tempo</th><th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($tagihan as $t)
                            <tr>
                                <td><strong>{{ $t->tagihan_no }}</strong></td>
                                <td>{{ $t->client_name }}</td>
                                <td>{{ Str::limit($t->title, 30) }}</td>
                                <td><span class="badge bg-{{ in_array($t->type, ['at_mandiri','at_kolab','jurnal']) ? 'info' : 'secondary' }}">{{ $t->type_label }}</span></td>
                                <td>Rp {{ number_format($t->amount, 0, ',', '.') }}</td>
                                <td><span class="badge bg-{{ $statusColors[$t->status] ?? 'secondary' }}">{{ Str::title(str_replace('_',' ',$t->status)) }}</span></td>
                                <td data-order="{{ $t->due_at ? $t->due_at->timestamp : 0 }}">{{ $t->due_at ? $t->due_at->format('d M Y') : '-' }}</td>
                                <td>
                                    <a href="{{ route('tagihan.show', $t->id) }}" class="btn btn-xs btn-primary">Detail</a>
                                    @if($t->isDownloadable())
                                        <a href="{{ route('tagihan.pdf', $t->id) }}" class="btn btn-xs btn-outline-secondary" target="_blank">PDF</a>
                                    @endif
                                    @if($t->canConvert() && $t->created_by === auth()->id())
                                        <a href="{{ route('tagihan.buatOrder', $t->id) }}" class="btn btn-xs btn-success">Buat Order</a>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('plugin-scripts')
    <script src="{{ URL::asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
    $(function () {
        $(".datatable").DataTable({
            pageLength: 25,
            responsive: true,
            columnDefs: [{ orderable: false, targets: 7 }],
            language: { emptyTable: "Belum ada tagihan." }
        });
    });
</script>
@endpush
