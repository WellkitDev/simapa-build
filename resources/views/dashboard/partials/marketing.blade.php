{{-- resources/views/dashboard/partials/marketing.blade.php --}}
<div class="d-flex justify-content-between align-items-center flex-wrap grid-margin">
    <h4 class="mb-0">Dashboard Marketing</h4>
</div>

<h6 class="text-muted mb-2">Ringkasan Pemasukan</h6>
<div class="row">
    @php
        $income = [
            ['Pemasukan Hari Ini', $mkt['pemasukan_hari_ini'], 'success'],
            ['Pemasukan Minggu Ini', $mkt['pemasukan_minggu_ini'], 'primary'],
            ['Pemasukan Tahun Ini', $mkt['pemasukan_tahun_ini'], 'info'],
        ];
    @endphp
    @foreach($income as [$label, $val, $tone])
        <div class="col-md-3 grid-margin stretch-card">
            <div class="card"><div class="card-body">
                <h6 class="card-title mb-0">{{ $label }}</h6>
                <h4 class="mt-2 mb-0 text-{{ $tone }}">Rp {{ number_format($val, 0, ',', '.') }}</h4>
            </div></div>
        </div>
    @endforeach
    <div class="col-md-3 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title mb-0">Jumlah Order (tahun ini)</h6>
            <h4 class="mt-2 mb-0 text-dark">{{ $mkt['jumlah_order_tahun_ini'] }}</h4>
        </div></div>
    </div>
</div>

<div class="row">
    <div class="col-md-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Tren Pemasukan (30 hari)</h6>
            <div id="mktIncomeChart"></div>
        </div></div>
    </div>
    <div class="col-md-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Tren Jumlah Order (30 hari)</h6>
            <div id="mktOrderChart"></div>
        </div></div>
    </div>
</div>

<h6 class="text-muted mb-2 mt-2">Progres Naskah Saya</h6>
<div class="row">
    @php
        $prog = [
            ['Naskah Aktif', $mkt['naskah_aktif'], 'primary'],
            ['Belum Diproses', $mkt['belum_diproses'], 'secondary'],
            ['Lewat Target', $mkt['lewat_target'], 'danger'],
            ['Jatuh Tempo ≤7 hari', $mkt['jatuh_tempo_7'], 'warning'],
            ['Selesai Bulan Ini', $mkt['selesai_bulan_ini'], 'success'],
            ['Total Selesai', $mkt['total_selesai'], 'info'],
        ];
    @endphp
    @foreach($prog as [$label, $val, $tone])
        <div class="col-md-4 col-xl-2 grid-margin stretch-card">
            <div class="card"><div class="card-body">
                <h6 class="card-title mb-0" style="font-size:12px">{{ $label }}</h6>
                <h3 class="mt-2 mb-0 text-{{ $tone }}">{{ $val }}</h3>
            </div></div>
        </div>
    @endforeach
</div>

<div class="row">
    <div class="col-md-5 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Naskah Saya per Tahap</h6>
            <div id="mktStageChart"></div>
        </div></div>
    </div>
    <div class="col-md-7 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Terbit/Publish (30 hari)</h6>
            <div id="mktCompletionChart"></div>
        </div></div>
    </div>
</div>

@push('plugin-scripts')
    <script src="{{ asset('assets/plugins/apexcharts/apexcharts.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var areaOpts = function (id, name, data, cats, color) {
            return {
                chart: { type: 'area', height: 240, toolbar: { show: false } },
                series: [{ name: name, data: data }],
                xaxis: { categories: cats, labels: { rotate: -45, style: { fontSize: '9px' } } },
                dataLabels: { enabled: false }, stroke: { curve: 'smooth', width: 2 }, colors: [color],
            };
        };
        new ApexCharts(document.querySelector("#mktIncomeChart"), areaOpts('inc', 'Pemasukan', @json($mkt['income_trend']['series']), @json($mkt['income_trend']['labels']), '#05a34a')).render();
        new ApexCharts(document.querySelector("#mktOrderChart"), areaOpts('ord', 'Order', @json($mkt['order_trend']['series']), @json($mkt['order_trend']['labels']), '#6571ff')).render();
        new ApexCharts(document.querySelector("#mktCompletionChart"), areaOpts('cmp', 'Terbit/Publish', @json($mkt['completion_trend']['series']), @json($mkt['completion_trend']['labels']), '#fbbc06')).render();

        new ApexCharts(document.querySelector("#mktStageChart"), {
            chart: { type: 'donut', height: 260 },
            series: @json($mkt['per_stage']['series']),
            labels: @json($mkt['per_stage']['labels']),
            legend: { position: 'bottom' },
        }).render();
    });
</script>
@endpush
