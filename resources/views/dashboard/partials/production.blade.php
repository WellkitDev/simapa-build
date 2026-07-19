{{-- resources/views/dashboard/partials/production.blade.php --}}
@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

<div class="d-flex justify-content-between align-items-center flex-wrap grid-margin">
    <h4 class="mb-0">Dashboard Produksi</h4>
</div>

<div class="row">
    @php
        $cards = [
            ['Antrian Saya', $prod['antrian_saya'], 'list', 'primary'],
            ['Belum Diambil', $prod['belum_diambil'], 'inbox', 'secondary'],
            ['Lewat Target', $prod['lewat_target'], 'alert-triangle', 'danger'],
            ['Jatuh Tempo ≤7 hari', $prod['jatuh_tempo_7'], 'clock', 'warning'],
            ['Selesai Bulan Ini', $prod['selesai_bulan_ini'], 'check-circle', 'success'],
            ['Total Selesai', $prod['total_selesai'], 'check-square', 'info'],
        ];
    @endphp
    @foreach($cards as [$label, $val, $icon, $tone])
        <div class="col-md-4 col-xl grid-margin stretch-card">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="card-title mb-0">{{ $label }}</h6>
                        <i data-feather="{{ $icon }}" class="icon-sm text-{{ $tone }}"></i>
                    </div>
                    <h3 class="mt-2 mb-0 text-{{ $tone }}">{{ $val }}</h3>
                    @if($label === 'Selesai Bulan Ini')
                        @include('dashboard.partials.delta', ['delta' => $prod['selesai_bulan_ini_delta']])
                    @endif
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="row">
    <div class="col-md-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Performa Saya</h6>
            <div id="prodPerfChart"></div>
            <div class="text-center text-muted" style="font-size:12px">
                Selesai {{ $perf['completed'] }} · Antrian {{ $perf['active_queue'] }} ·
                Tepat waktu {{ $perf['on_time_rate'] === null ? '—' : $perf['on_time_rate'] . '%' }}
            </div>
        </div></div>
    </div>
    <div class="col-md-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="card-title mb-0">Aktivitas Saya</h6>
                <div class="btn-group btn-group-sm" id="prodRangeToggle">
                    <button type="button" class="btn btn-outline-primary" data-range="7">7 hari</button>
                    <button type="button" class="btn btn-primary active" data-range="30">30 hari</button>
                    <button type="button" class="btn btn-outline-primary" data-range="90">90 hari</button>
                </div>
            </div>
            <div id="prodActivityChart"></div>
        </div></div>
    </div>
</div>

<h6 class="text-muted mb-2 mt-2">Naskah Saya per Tahap</h6>
@include('dashboard.partials.stage-donuts', ['stats' => $stageStats, 'idPrefix' => 'prod'])

<h6 class="text-muted mb-2 mt-2">Naskah Saya Mendekati Deadline</h6>
<div class="row">
    @include('dashboard.partials.deadline-table', ['rows' => $prod['deadline_rows'], 'tableId' => 'prodDeadline'])
</div>

@push('plugin-scripts')
    <script src="{{ asset('assets/plugins/apexcharts/apexcharts.min.js') }}"></script>
    <script src="{{ asset('assets/js/dashboard-charts.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var rate = @json($perf['on_time_rate']);
        new ApexCharts(document.querySelector("#prodPerfChart"), {
            chart: { type: 'radialBar', height: 220 },
            series: [rate === null ? 0 : rate],
            labels: ['On-time'],
            plotOptions: { radialBar: { dataLabels: { value: { formatter: function(){ return rate === null ? '—' : rate + '%'; } } } } },
            colors: ['#05a34a'],
        }).render();

        var C = window.SimapaCharts;
        var fullActivity = { labels: @json($prod['activity_trend']['labels']), series: @json($prod['activity_trend']['series']) };
        var n0 = 30;
        var sliced = C.slice(fullActivity, n0);
        var activityChart = C.render('#prodActivityChart', C.area('Aktivitas', sliced, C.PALETTE.primary, false), sliced.series);
        C.rangeToggle('#prodRangeToggle', [{ chart: activityChart, key: 'activity' }], { activity: fullActivity });
    });
</script>
@endpush
