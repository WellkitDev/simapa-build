@extends('layouts.master')
@section('title', 'Arsip Judul - SiMAPA')

@push('plugin-styles')
    <link href="{{ asset('assets/plugins/select2/select2.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card overflow-hidden">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-baseline mb-3">
                    <h6 class="card-title mb-0">Arsip Judul</h6>
                </div>

                <div class="table-responsive">
                    <table class="table table-centered datatable dt-responsive nowrap"
                        style="border-collapse: collapse; border-spacing: 0; width: 100%;">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Judul</th>
                                <th>Buku</th>
                                <th>Total Author</th>
                                <th>Status Progres</th>
                                <th>Handler</th>
                                <th>Update Terakhir</th>
                                <th>Target</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php
                                $statusColors = [
                                    'menunggu_proses' => 'secondary',
                                    'templating' => 'warning', 'editing' => 'warning', 'layout' => 'warning',
                                    'revisi' => 'warning', 'proofreading' => 'warning', 'isbn' => 'warning',
                                    'submit' => 'primary', 'cetak' => 'primary', 'loa' => 'primary',
                                    'publish' => 'success', 'terbit' => 'success',
                                ];
                            @endphp
                            @foreach ($judulData as $row)
                            <tr>
                                <td>{{ $loop->iteration }}</td>
                                <td class="text-capitalize"><strong>{{ Str::limit($row->title, 40) }}</strong></td>
                                <td><span class="badge bg-info">{{ $row->type_label }}</span></td>
                                <td>{{ $row->total_author }}</td>
                                <td>
                                    <span class="badge bg-{{ $statusColors[$row->bottleneck_status] ?? 'secondary' }}">
                                        {{ Str::title(str_replace('_', ' ', $row->bottleneck_status)) }}
                                    </span>
                                    @if ($row->is_mixed)
                                        <span class="badge bg-light text-muted">beragam</span>
                                    @endif
                                </td>
                                <td><small class="text-muted text-capitalize">{{ $row->handler ?? '-' }}</small></td>
                                <td><small>{{ $row->last_update ? \Carbon\Carbon::parse($row->last_update)->diffForHumans() : '-' }}</small></td>
                                <td data-order="{{ $row->target_date ? \Carbon\Carbon::parse($row->target_date)->timestamp : 0 }}">
                                    @if($row->target_date)
                                        <span class="badge bg-{{ $row->is_overdue ? 'danger' : 'light text-dark border' }}">
                                            {{ \Carbon\Carbon::parse($row->target_date)->format('d M Y') }}{{ $row->is_overdue ? ' · lewat' : '' }}
                                        </span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    <a href="{{ route('order.indexJudul.detail', $row->detail_id_repr) }}"
                                        class="btn btn-sm btn-primary">Detail</a>
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
        var table = $(".datatable").DataTable({
            pageLength: 10,
            order: [[1, "asc"]],
            // Kolom No (index 0) tidak ikut diurut/dicari; dinomori ulang via event di bawah.
            columnDefs: [
                { targets: 0, orderable: false, searchable: false }
            ],
        });

        // Nomor urut selalu 1..n mengikuti hasil filter & urutan (menyambung antar halaman).
        table.on('order.dt search.dt', function () {
            var i = 1;
            table.cells(null, 0, { search: 'applied', order: 'applied' }).every(function () {
                this.data(i++);
            });
        }).draw();

        $(".dataTables_length select, .dataTables_filter input").addClass("form-control mb-2");
        $('.custom-select').select2();
    });
</script>
@endpush
