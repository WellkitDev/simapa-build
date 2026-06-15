{{-- resources/views/dashboard/partials/production.blade.php --}}
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
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="row">
    <div class="col-md-4 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Performa Saya</h6>
            <div id="prodPerfChart"></div>
            <div class="text-center text-muted" style="font-size:12px">
                Selesai {{ $perf['completed'] }} · Antrian {{ $perf['active_queue'] }}
            </div>
        </div></div>
    </div>
    <div class="col-md-4 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Naskah Saya per Tahap</h6>
            <div id="prodStageChart"></div>
        </div></div>
    </div>
    <div class="col-md-4 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Aktivitas Saya (30 hari)</h6>
            <div id="prodActivityChart"></div>
        </div></div>
    </div>
</div>

@push('plugin-scripts')
    <script src="{{ asset('assets/plugins/apexcharts/apexcharts.min.js') }}"></script>
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

        new ApexCharts(document.querySelector("#prodStageChart"), {
            chart: { type: 'donut', height: 240 },
            series: @json($prod['per_stage']['series']),
            labels: @json($prod['per_stage']['labels']),
            legend: { position: 'bottom' },
        }).render();

        new ApexCharts(document.querySelector("#prodActivityChart"), {
            chart: { type: 'area', height: 240, toolbar: { show: false } },
            series: [{ name: 'Aktivitas', data: @json($prod['activity_30d']['series']) }],
            xaxis: { categories: @json($prod['activity_30d']['labels']), labels: { rotate: -45, style: { fontSize: '9px' } } },
            dataLabels: { enabled: false }, stroke: { curve: 'smooth', width: 2 }, colors: ['#6571ff'],
        }).render();
    });
</script>
@endpush
