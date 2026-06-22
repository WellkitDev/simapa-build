{{-- resources/views/dashboard/partials/marketing.blade.php --}}
@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

<div class="d-flex justify-content-between align-items-center flex-wrap grid-margin">
    <h4 class="mb-0">Dashboard Marketing</h4>
</div>

<h6 class="text-muted mb-2">Ringkasan Pemasukan</h6>
<div class="row">
    @php
        $income = [
            ['Pemasukan Hari Ini', $mkt['pemasukan_hari_ini'], 'success', 'dollar-sign', $mkt['pemasukan_hari_ini_delta']],
            ['Pemasukan Minggu Ini', $mkt['pemasukan_minggu_ini'], 'primary', 'calendar', $mkt['pemasukan_minggu_ini_delta']],
            ['Pemasukan Tahun Ini', $mkt['pemasukan_tahun_ini'], 'info', 'trending-up', $mkt['pemasukan_tahun_ini_delta']],
        ];
    @endphp
    @foreach($income as [$label, $val, $tone, $icon, $delta])
        <div class="col-md-4 grid-margin stretch-card">
            <div class="card"><div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="card-title mb-0">{{ $label }}</h6>
                        <h4 class="mt-2 mb-0 text-{{ $tone }}">Rp {{ number_format($val, 0, ',', '.') }}</h4>
                    </div>
                    <div class="bg-{{ $tone }} bg-opacity-10 rounded p-2">
                        <i data-feather="{{ $icon }}" class="text-{{ $tone }}"></i>
                    </div>
                </div>
                @include('dashboard.partials.delta', ['delta' => $delta])
            </div></div>
        </div>
    @endforeach
</div>

<h6 class="text-muted mb-2 mt-2">Target Bulan Ini</h6>
<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            @if($mkt['target']['has_target'])
                @php
                    $t = $mkt['target'];
                    $tcls = $t['capaian_persen'] >= 100 ? 'bg-success' : ($t['capaian_persen'] >= 75 ? 'bg-warning' : 'bg-danger');
                @endphp
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span>Capaian: <strong>{{ $t['capaian_persen'] }}%</strong></span>
                    <span class="text-muted">Komisi diperoleh: <strong class="text-success">Rp {{ number_format($t['komisi'], 0, ',', '.') }}</strong></span>
                </div>
                <div class="progress mb-3" style="height:18px">
                    <div class="progress-bar {{ $tcls }}" role="progressbar" style="width: {{ min($t['capaian_persen'], 100) }}%">{{ $t['capaian_persen'] }}%</div>
                </div>
                <div class="row text-center">
                    <div class="col-md-4"><small class="text-muted d-block">Target</small><strong>Rp {{ number_format($t['target'], 0, ',', '.') }}</strong></div>
                    <div class="col-md-4"><small class="text-muted d-block">Realisasi</small><strong class="text-primary">Rp {{ number_format($t['realisasi'], 0, ',', '.') }}</strong></div>
                    <div class="col-md-4"><small class="text-muted d-block">Sisa</small><strong class="text-danger">Rp {{ number_format($t['sisa'], 0, ',', '.') }}</strong></div>
                </div>
            @else
                <p class="text-muted mb-0">Target belum ditetapkan untuk bulan ini.</p>
            @endif
        </div></div>
    </div>
</div>

<h6 class="text-muted mb-2 mt-2">Statistik Order &amp; Tagihan</h6>
<div class="row">
    <div class="col-md-3 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <h6 class="card-title mb-0">Jumlah Order (bulan ini)</h6>
                    <h4 class="mt-2 mb-0 text-primary">{{ $mkt['jumlah_order_bulan_ini'] }}</h4>
                </div>
                <div class="bg-primary bg-opacity-10 rounded p-2">
                    <i data-feather="shopping-cart" class="text-primary"></i>
                </div>
            </div>
            @include('dashboard.partials.delta', ['delta' => $mkt['jumlah_order_bulan_ini_delta']])
        </div></div>
    </div>
    <div class="col-md-3 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <h6 class="card-title mb-0">Jumlah Order (tahun ini)</h6>
                    <h4 class="mt-2 mb-0 text-dark">{{ $mkt['jumlah_order_tahun_ini'] }}</h4>
                </div>
                <div class="bg-dark bg-opacity-10 rounded p-2">
                    <i data-feather="shopping-bag" class="text-dark"></i>
                </div>
            </div>
            @include('dashboard.partials.delta', ['delta' => $mkt['jumlah_order_delta']])
        </div></div>
    </div>
    <div class="col-md-3 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <h6 class="card-title mb-0">Total Piutang</h6>
                    <h4 class="mt-2 mb-0 text-warning">Rp {{ number_format($mkt['total_piutang'], 0, ',', '.') }}</h4>
                </div>
                <div class="bg-warning bg-opacity-10 rounded p-2">
                    <i data-feather="alert-circle" class="text-warning"></i>
                </div>
            </div>
            <small class="text-muted mt-2 d-block">Sisa tagihan order belum lunas</small>
        </div></div>
    </div>
    <div class="col-md-3 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <h6 class="card-title mb-0">Rata-rata Nilai Order</h6>
                    <h4 class="mt-2 mb-0 text-dark">Rp {{ number_format($mkt['rata_rata_order'], 0, ',', '.') }}</h4>
                </div>
                <div class="bg-info bg-opacity-10 rounded p-2">
                    <i data-feather="bar-chart-2" class="text-info"></i>
                </div>
            </div>
            <small class="text-muted mt-2 d-block">Tahun ini</small>
        </div></div>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mt-2 mb-2">
    <h6 class="text-muted mb-0">Traffic</h6>
    <div class="btn-group btn-group-sm" role="group" id="mktRangeToggle">
        <button type="button" class="btn btn-outline-primary" data-range="7">7 hari</button>
        <button type="button" class="btn btn-primary active" data-range="30">30 hari</button>
        <button type="button" class="btn btn-outline-primary" data-range="90">90 hari</button>
    </div>
</div>
<div class="row">
    <div class="col-md-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Tren Pemasukan</h6>
            <div id="mktIncomeChart"></div>
        </div></div>
    </div>
    <div class="col-md-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Tren Jumlah Order</h6>
            <div id="mktOrderChart"></div>
        </div></div>
    </div>
</div>

<h6 class="text-muted mb-2 mt-2">Progres Naskah Saya</h6>
<div class="row">
    @php
        $progCards = [
            ['Naskah Aktif', $mkt['naskah_aktif'], 'primary'],
            ['Belum Diproses', $mkt['belum_diproses'], 'secondary'],
            ['Lewat Target', $mkt['lewat_target'], 'danger'],
            ['Jatuh Tempo ≤7 hari', $mkt['jatuh_tempo_7'], 'warning'],
            ['Selesai Bulan Ini', $mkt['selesai_bulan_ini'], 'success'],
            ['Total Selesai', $mkt['total_selesai'], 'info'],
        ];
    @endphp
    @foreach($progCards as [$label, $val, $tone])
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

<h6 class="text-muted mb-2 mt-2">Naskah Mendekati Deadline</h6>
<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <ul class="nav nav-pills mb-3" id="deadlineTabs">
                <li class="nav-item"><a class="nav-link active" href="#" data-bucket="all">Semua</a></li>
                <li class="nav-item"><a class="nav-link" href="#" data-bucket="overdue">Lewat target</a></li>
                <li class="nav-item"><a class="nav-link" href="#" data-bucket="d7">≤7 hari</a></li>
                <li class="nav-item"><a class="nav-link" href="#" data-bucket="month">Bulan ini</a></li>
            </ul>
            <div class="table-responsive">
                <table class="table table-hover" id="deadlineTable" style="width:100%">
                    <thead>
                        <tr>
                            <th>Judul</th><th>Kode Order</th><th>Tahap</th>
                            <th>Target</th><th>Sisa Hari</th><th>Prioritas</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($mkt['deadline_rows'] as $r)
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
        </div></div>
    </div>
</div>

@push('plugin-scripts')
    <script src="{{ asset('assets/plugins/apexcharts/apexcharts.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // ---- Traffic: data 90 hari, ditampilkan per slice di sisi klien ----
        var full = {
            inc: { labels: @json($mkt['income_trend']['labels']), series: @json($mkt['income_trend']['series']) },
            ord: { labels: @json($mkt['order_trend']['labels']),  series: @json($mkt['order_trend']['series']) },
        };
        function slice(o, n) { return { labels: o.labels.slice(-n), series: o.series.slice(-n) }; }
        // Batasi jumlah label sumbu-X biar tidak "semut" di rentang panjang.
        function tickFor(n) { return n <= 14 ? n : (n <= 31 ? 10 : 12); }
        function areaOpts(name, d, color, isCurrency) {
            return {
                chart: { type: 'area', height: 240, toolbar: { show: false } },
                series: [{ name: name, data: d.series }],
                xaxis: {
                    categories: d.labels,
                    tickAmount: tickFor(d.series.length),
                    tickPlacement: 'on',
                    labels: { rotate: -45, rotateAlways: false, hideOverlappingLabels: true, trim: false, style: { fontSize: '11px' } },
                    axisTicks: { show: false },
                },
                yaxis: { labels: { formatter: function (v) { return isCurrency ? v.toLocaleString('id-ID') : Math.round(v); } } },
                dataLabels: { enabled: false }, stroke: { curve: 'smooth', width: 2 }, colors: [color],
                fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.1 } },
                markers: { size: 0, hover: { size: 5 } },
                grid: { borderColor: '#f1f1f1', strokeDashArray: 4, padding: { left: 8, right: 8 } },
                tooltip: isCurrency ? { y: { formatter: function (v) { return 'Rp ' + v.toLocaleString('id-ID'); } } } : {},
            };
        }
        var n0 = 30;
        var incChart = new ApexCharts(document.querySelector('#mktIncomeChart'), areaOpts('Pemasukan', slice(full.inc, n0), '#05a34a', true));
        var ordChart = new ApexCharts(document.querySelector('#mktOrderChart'), areaOpts('Order', slice(full.ord, n0), '#6571ff', false));
        incChart.render(); ordChart.render();

        document.querySelectorAll('#mktRangeToggle [data-range]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var n = +this.dataset.range;
                var si = slice(full.inc, n), so = slice(full.ord, n);
                incChart.updateOptions({ xaxis: { categories: si.labels, tickAmount: tickFor(si.series.length) }, series: [{ data: si.series }] });
                ordChart.updateOptions({ xaxis: { categories: so.labels, tickAmount: tickFor(so.series.length) }, series: [{ data: so.series }] });
                document.querySelectorAll('#mktRangeToggle [data-range]').forEach(function (b) {
                    b.classList.remove('btn-primary', 'active'); b.classList.add('btn-outline-primary');
                });
                this.classList.remove('btn-outline-primary'); this.classList.add('btn-primary', 'active');
            });
        });

        // ---- Donut: Naskah per Tahap (total di tengah + persentase) ----
        new ApexCharts(document.querySelector('#mktStageChart'), {
            chart: { type: 'donut', height: 260 },
            series: @json($mkt['per_stage']['series']),
            labels: @json($mkt['per_stage']['labels']),
            legend: { position: 'bottom' },
            dataLabels: { enabled: true, formatter: function (v) { return Math.round(v) + '%'; } },
            plotOptions: { pie: { donut: { labels: { show: true, total: { show: true, label: 'Naskah Aktif' } } } } },
        }).render();

        // ---- Completion (30 hari terakhir) ----
        new ApexCharts(
            document.querySelector('#mktCompletionChart'),
            areaOpts('Terbit/Publish', slice({ labels: @json($mkt['completion_trend']['labels']), series: @json($mkt['completion_trend']['series']) }, n0), '#fbbc06', false)
        ).render();
    });

    // ---- Tabel deadline: DataTables + filter tab ----
    $(function () {
        if (!$.fn.DataTable) return;
        window.deadlineBucket = 'all';
        $.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
            if (settings.nTable.id !== 'deadlineTable') return true;
            if (window.deadlineBucket === 'all') return true;
            var node = settings.aoData[dataIndex].nTr;
            return node.getAttribute('data-' + window.deadlineBucket) === '1';
        });
        var table = $('#deadlineTable').DataTable({ pageLength: 10, order: [[4, 'asc']] });
        $('.dataTables_length select, .dataTables_filter input').addClass('form-control mb-2');
        $('#deadlineTabs a').on('click', function (e) {
            e.preventDefault();
            $('#deadlineTabs a').removeClass('active');
            $(this).addClass('active');
            window.deadlineBucket = $(this).data('bucket');
            table.draw();
        });
    });
</script>
@endpush
