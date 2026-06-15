@extends('layouts.master')
@section('title', 'Manuscript Tracker - Log Aktivitas')

@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@include('manuscript.partials.toolbar')

<div class="card overflow-hidden">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-centered datatable dt-responsive nowrap" style="width:100%">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Tanggal</th>
                        <th>Judul</th>
                        <th>Aktivitas</th>
                        <th>Perubahan</th>
                        <th>Oleh</th>
                        <th>Catatan</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($logs as $log)
                        <tr>
                            <td>{{ $loop->iteration }}</td>
                            <td><small>{{ $log->created_at->format('d/m/Y H:i') }}</small></td>
                            <td class="text-capitalize">{{ Str::limit(optional(optional($log->titleProgress)->orderDetail)->title, 45) ?: '—' }}</td>
                            <td>
                                <span class="badge bg-light text-dark border">{{ $log->eventLabel() }}</span>
                                @if($log->is_correction)<span class="badge bg-danger">Koreksi</span>@endif
                            </td>
                            <td>
                                @if($log->from_value || $log->to_value)
                                    <small class="text-muted">{{ $log->from_value ?? '—' }}</small>
                                    <span class="mx-1">→</span>
                                    <strong>{{ $log->to_value ?? '—' }}</strong>
                                @else
                                    <small class="text-muted">—</small>
                                @endif
                            </td>
                            <td>{{ optional($log->changedBy)->name ?? '-' }}</td>
                            <td>{{ $log->note ?? '-' }}</td>
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
            pageLength: 25,
            order: [[1, "desc"]], // terbaru di atas
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
