@extends('layouts.master')
@section('title', 'Pelacak Naskah - SiMAPA')

@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@php
    $statusBadge = [
        'menunggu_proses' => 'secondary',
        'templating' => 'warning', 'editing' => 'warning', 'layout' => 'warning',
        'revisi' => 'warning', 'proofreading' => 'warning', 'isbn' => 'warning',
        'submit' => 'primary', 'cetak' => 'primary', 'loa' => 'primary',
        'publish' => 'success', 'terbit' => 'success',
    ];
    $prioBadge = ['high' => 'danger', 'normal' => 'secondary', 'low' => 'info'];
@endphp
@include('manuscript.partials.toolbar')

<div class="card overflow-hidden">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-centered datatable dt-responsive nowrap" style="width:100%">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Judul Naskah</th>
                            <th>Order</th>
                            <th>Stage</th>
                            <th>Editor</th>
                            <th>Prioritas</th>
                            <th>Target</th>
                            <th>Update</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($groups as $detail)
                            @php $p = $detail->titleProgress; @endphp
                            <tr>
                                <td>{{ $loop->iteration }}</td>
                                <td>
                                    <strong class="text-capitalize">{{ Str::limit($detail->title, 50) }}</strong>
                                    <small class="text-muted d-block">{{ $detail->group_author_count ?? $detail->authors->count() }} penulis</small>
                                </td>
                                <td><span class="badge bg-secondary">{{ $detail->group_order_count ?? 1 }}</span></td>
                                <td>
                                    <span class="badge bg-{{ $statusBadge[$p->status] ?? 'secondary' }}">{{ Str::title(str_replace('_', ' ', $p->status)) }}</span>
                                    @if($detail->group_is_mixed ?? false)<span class="badge bg-light text-muted">beragam</span>@endif
                                </td>
                                <td>{{ optional($p->assignedUser)->name ?? '—' }}</td>
                                <td><span class="badge bg-{{ $prioBadge[$p->priority] ?? 'secondary' }}">{{ ucfirst($p->priority) }}</span></td>
                                <td>
                                    @php $od = $p->target_date && $p->target_date->lt(today()) && ! in_array($p->status, ['terbit','publish'], true); @endphp
                                    @if($p->target_date)
                                        <span class="badge bg-{{ $od ? 'danger' : 'light text-dark border' }}" title="Target terbit/publish">{{ $p->target_date->format('d M Y') }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td><small>{{ optional($p->started_at)->diffForHumans() }}</small></td>
                                <td>
                                    <a href="{{ route('order.indexJudul.detail', $detail->id) }}" class="btn btn-sm btn-outline-primary">Detail</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
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
        var table = $(".datatable").DataTable({
            pageLength: 10,
            responsive: true,
            order: [], // pertahankan urutan server (overdue → prioritas → target)
            // Kolom No (index 0) tidak ikut diurut/dicari; dinomori ulang via event di bawah.
            columnDefs: [
                { targets: 0, orderable: false, searchable: false }
            ],
        });

        table.on('order.dt search.dt', function () {
            var i = 1;
            table.cells(null, 0, { search: 'applied', order: 'applied' }).every(function () {
                this.data(i++);
            });
        }).draw();

        $(".dataTables_length select, .dataTables_filter input").addClass("form-control mb-2");
    });
</script>
@endpush
