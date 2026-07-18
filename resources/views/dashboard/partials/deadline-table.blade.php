{{-- resources/views/dashboard/partials/deadline-table.blade.php
     Butuh: $rows (collection dari deadlineRows()), $tableId (unik per halaman). --}}
@php $tid = $tableId ?? 'deadlineTable'; @endphp

<div class="col-12 grid-margin stretch-card">
    <div class="card"><div class="card-body">
        @if($rows->isEmpty())
            <p class="text-muted mb-0">Belum ada naskah dengan target tanggal.</p>
        @else
            <ul class="nav nav-pills mb-3" id="{{ $tid }}Tabs">
                <li class="nav-item"><a class="nav-link active" href="#" data-bucket="all">Semua</a></li>
                <li class="nav-item"><a class="nav-link" href="#" data-bucket="overdue">Lewat target</a></li>
                <li class="nav-item"><a class="nav-link" href="#" data-bucket="d7">≤7 hari</a></li>
                <li class="nav-item"><a class="nav-link" href="#" data-bucket="month">Bulan ini</a></li>
            </ul>
            <div class="table-responsive">
                <table class="table table-hover" id="{{ $tid }}" style="width:100%">
                    <thead>
                        <tr>
                            <th>Judul</th><th>Kode Order</th><th>Tahap</th>
                            <th>Target</th><th>Sisa Hari</th><th>Prioritas</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $r)
                            <tr data-overdue="{{ $r['overdue'] }}" data-d7="{{ $r['d7'] }}" data-month="{{ $r['month'] }}">
                                <td>{{ $r['title'] }}</td>
                                <td><a href="{{ route('order.indexJudul.progress', $r['order_detail_id']) }}">{{ $r['code_order'] }}</a></td>
                                <td><span class="badge bg-secondary">{{ $r['stage'] }}</span></td>
                                <td data-order="{{ $r['target_date'] }}">{{ $r['target_label'] }}</td>
                                <td data-order="{{ $r['days'] }}">
                                    <span class="badge {{ $r['overdue'] ? 'bg-danger' : ($r['d7'] ? 'bg-warning' : 'bg-light text-dark') }}">{{ $r['days_label'] }}</span>
                                </td>
                                <td>
                                    @php $pc = ['low' => 'bg-secondary', 'normal' => 'bg-info', 'high' => 'bg-danger'][$r['priority']] ?? 'bg-secondary'; @endphp
                                    <span class="badge {{ $pc }}">{{ $r['priority'] ? ucfirst($r['priority']) : '-' }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div></div>
</div>

@push('custom-scripts')
<script>
$(function () {
    if (!$.fn.DataTable || !document.getElementById('{{ $tid }}')) return;
    var bucket = 'all';
    $.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
        if (settings.nTable.id !== '{{ $tid }}') return true;
        if (bucket === 'all') return true;
        return settings.aoData[dataIndex].nTr.getAttribute('data-' + bucket) === '1';
    });
    var table = $('#{{ $tid }}').DataTable({ pageLength: 10, order: [[4, 'asc']] });
    $('#{{ $tid }}_wrapper .dataTables_length select, #{{ $tid }}_wrapper .dataTables_filter input').addClass('form-control mb-2');
    $('#{{ $tid }}Tabs a').on('click', function (e) {
        e.preventDefault();
        $('#{{ $tid }}Tabs a').removeClass('active');
        $(this).addClass('active');
        bucket = $(this).data('bucket');
        table.draw();
    });
});
</script>
@endpush
