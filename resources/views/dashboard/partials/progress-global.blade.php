{{-- resources/views/dashboard/partials/progress-global.blade.php --}}
<div class="d-flex justify-content-between align-items-center flex-wrap grid-margin mt-2">
    <h4 class="mb-0">Progres Naskah (Global)</h4>
</div>

<div class="row">
    @php
        $g = [
            ['Total dalam Produksi', $global['total_in_production'], 'primary', 'layers'],
            ['Lewat Target', $global['lewat_target'], 'danger', 'alert-triangle'],
            ['Jatuh Tempo ≤7 hari', $global['jatuh_tempo_7'], 'warning', 'clock'],
            ['Selesai Bulan Ini', $global['selesai_bulan_ini'], 'success', 'check-circle'],
        ];
    @endphp
    @foreach($g as [$label, $val, $tone, $icon])
        <div class="col-md-3 grid-margin stretch-card">
            <div class="card"><div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="card-title mb-0">{{ $label }}</h6>
                        <h3 class="mt-2 mb-0 text-{{ $tone }}">{{ $val }}</h3>
                    </div>
                    <div class="bg-{{ $tone }} bg-opacity-10 rounded p-2">
                        <i data-feather="{{ $icon }}" class="text-{{ $tone }}"></i>
                    </div>
                </div>
            </div></div>
        </div>
    @endforeach
</div>

@include('dashboard.partials.stage-donuts', ['stats' => $stageStats, 'idPrefix' => 'co'])

<div class="row">
    <div class="col-12 grid-margin stretch-card">
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

{{--
    Partial ini hanya di-include dari dashboard/partials/company.blade.php, yang sudah
    memuat ApexCharts + DataTables (core, bs4, responsive, responsive-bs4). Push di sini
    dihapus supaya asset-nya tidak termuat dua kali. Stack 'custom-scripts' di bawah
    dirender setelah seluruh 'plugin-scripts', jadi semuanya sudah tersedia.
--}}
@push('custom-scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        new ApexCharts(document.querySelector("#globalTrendChart"), {
            chart: { type: 'area', height: 260, toolbar: { show: false } },
            series: [{ name: 'Selesai', data: @json($global['completion_trend']['series']) }],
            xaxis: {
                categories: @json($global['completion_trend']['labels']),
                tickAmount: 10, tickPlacement: 'on',
                labels: { rotate: -45, hideOverlappingLabels: true, style: { fontSize: '11px' } },
                axisTicks: { show: false },
            },
            dataLabels: { enabled: false }, stroke: { curve: 'smooth', width: 2 }, colors: ['#05a34a'],
            fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.1 } },
            grid: { borderColor: '#f1f1f1', strokeDashArray: 4 },
        }).render();

        $(".datatable").DataTable({ pageLength: 10, searching: true, ordering: true });
    });
</script>
@endpush
