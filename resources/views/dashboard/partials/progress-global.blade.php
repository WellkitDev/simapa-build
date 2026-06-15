{{-- resources/views/dashboard/partials/progress-global.blade.php --}}
<div class="d-flex justify-content-between align-items-center flex-wrap grid-margin mt-2">
    <h4 class="mb-0">Progres Naskah (Global)</h4>
</div>

<div class="row">
    @php
        $g = [
            ['Total dalam Produksi', $global['total_in_production'], 'primary'],
            ['Lewat Target', $global['lewat_target'], 'danger'],
            ['Jatuh Tempo ≤7 hari', $global['jatuh_tempo_7'], 'warning'],
            ['Selesai Bulan Ini', $global['selesai_bulan_ini'], 'success'],
        ];
    @endphp
    @foreach($g as [$label, $val, $tone])
        <div class="col-md-3 grid-margin stretch-card">
            <div class="card"><div class="card-body">
                <h6 class="card-title mb-0">{{ $label }}</h6>
                <h3 class="mt-2 mb-0 text-{{ $tone }}">{{ $val }}</h3>
            </div></div>
        </div>
    @endforeach
</div>

<div class="row">
    <div class="col-md-5 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Distribusi per Tahap</h6>
            <div id="globalStageChart"></div>
        </div></div>
    </div>
    <div class="col-md-7 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Penyelesaian (30 hari)</h6>
            <div id="globalTrendChart"></div>
        </div></div>
    </div>
</div>

<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Performa per Editor (30 hari)</h6>
            <div class="table-responsive">
                <table class="table table-centered datatable dt-responsive nowrap" style="width:100%">
                    <thead><tr><th>Editor</th><th>On-time</th><th>Selesai</th><th>Antrian Aktif</th></tr></thead>
                    <tbody>
                        @foreach($editors as $row)
                        <tr>
                            <td>{{ $row['name'] }}</td>
                            <td>{{ is_null($row['stats']['on_time_rate']) ? '—' : $row['stats']['on_time_rate'] . '%' }}</td>
                            <td>{{ $row['stats']['completed'] }}</td>
                            <td>{{ $row['stats']['active_queue'] }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div></div>
    </div>
</div>

@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush
@push('plugin-scripts')
    <script src="{{ asset('assets/plugins/apexcharts/apexcharts.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        new ApexCharts(document.querySelector("#globalStageChart"), {
            chart: { type: 'donut', height: 260 },
            series: @json($global['per_stage']['series']),
            labels: @json($global['per_stage']['labels']),
            legend: { position: 'bottom' },
        }).render();

        new ApexCharts(document.querySelector("#globalTrendChart"), {
            chart: { type: 'area', height: 260, toolbar: { show: false } },
            series: [{ name: 'Selesai', data: @json($global['completion_trend']['series']) }],
            xaxis: { categories: @json($global['completion_trend']['labels']), labels: { rotate: -45, style: { fontSize: '9px' } } },
            dataLabels: { enabled: false }, stroke: { curve: 'smooth', width: 2 }, colors: ['#05a34a'],
        }).render();

        $(".datatable").DataTable({ pageLength: 10, searching: true, ordering: true });
    });
</script>
@endpush
