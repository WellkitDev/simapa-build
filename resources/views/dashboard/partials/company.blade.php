{{-- resources/views/dashboard/partials/company.blade.php --}}
@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

<div class="d-flex justify-content-between align-items-center flex-wrap grid-margin">
    <h4 class="mb-0">Dashboard Perusahaan</h4>
    <form method="GET" action="{{ route('dashboard') }}" class="d-flex align-items-center">
        <label class="text-muted me-2 mb-0" style="font-size:13px">Marketing</label>
        <select name="marketing" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
            <option value="">Semua marketing</option>
            @foreach($marketers as $m)
                <option value="{{ $m->id }}" @selected($filterId === $m->id)>{{ $m->name }}</option>
            @endforeach
        </select>
    </form>
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

<h6 class="text-muted mb-2 mt-2">Target Tim</h6>
<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            @if($filterId && $mkt['target'])
                @php
                    $t = $mkt['target'];
                    $tcls = $t['capaian_persen'] >= 100 ? 'bg-success' : ($t['capaian_persen'] >= 75 ? 'bg-warning' : 'bg-danger');
                @endphp
                <div class="d-flex justify-content-between align-items-center mb-1 flex-wrap">
                    <span>Capaian: <strong>{{ $t['capaian_persen'] }}%</strong></span>
                    <span class="text-muted">Periode: <strong>{{ \Illuminate\Support\Carbon::parse($t['start_date'])->format('d M') }} – {{ \Illuminate\Support\Carbon::parse($t['end_date'])->format('d M Y') }}</strong></span>
                </div>
                <div class="progress mb-3" style="height:18px">
                    <div class="progress-bar {{ $tcls }}" style="width: {{ min($t['capaian_persen'], 100) }}%">{{ $t['capaian_persen'] }}%</div>
                </div>
                <div class="row text-center">
                    <div class="col-md-3"><small class="text-muted d-block">Target</small><strong>Rp {{ number_format($t['target'], 0, ',', '.') }}</strong></div>
                    <div class="col-md-3"><small class="text-muted d-block">Realisasi</small><strong class="text-primary">Rp {{ number_format($t['realisasi'], 0, ',', '.') }}</strong></div>
                    <div class="col-md-3"><small class="text-muted d-block">Sisa</small><strong class="{{ $t['sisa'] > 0 ? 'text-danger' : 'text-success' }}">Rp {{ number_format($t['sisa'], 0, ',', '.') }}</strong></div>
                    <div class="col-md-3"><small class="text-muted d-block">Komisi</small><strong class="text-success">Rp {{ number_format($t['komisi'], 0, ',', '.') }}</strong></div>
                </div>
            @elseif($filterId)
                <p class="text-muted mb-0">Marketing ini tidak punya target berjalan.</p>
            @elseif($teamTargets->isEmpty())
                <p class="text-muted mb-0">Belum ada target berjalan untuk tim.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-hover dt-responsive nowrap" id="teamTargetTable" style="width:100%">
                        <thead>
                            <tr>
                                <th>Marketing</th><th>Periode</th><th>Target</th>
                                <th>Realisasi</th><th>Capaian</th><th>Komisi</th><th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($teamTargets as $t)
                                @php
                                    $badge = $t['capaian_persen'] >= 100 ? 'bg-success' : ($t['capaian_persen'] >= 75 ? 'bg-warning' : 'bg-danger');
                                @endphp
                                <tr>
                                    <td>{{ $t['name'] }}</td>
                                    <td>{{ \Illuminate\Support\Carbon::parse($t['start_date'])->format('d M') }} – {{ \Illuminate\Support\Carbon::parse($t['end_date'])->format('d M Y') }}</td>
                                    <td>Rp {{ number_format($t['target'], 0, ',', '.') }}</td>
                                    <td>Rp {{ number_format($t['realisasi'], 0, ',', '.') }}</td>
                                    <td data-order="{{ $t['capaian_persen'] }}"><span class="badge {{ $badge }}">{{ $t['capaian_persen'] }}%</span></td>
                                    <td>Rp {{ number_format($t['komisi'], 0, ',', '.') }}</td>
                                    <td>
                                        <span class="badge {{ $t['commission_paid'] ? 'bg-success' : ($t['tertunggak'] ? 'bg-danger' : 'bg-secondary') }}">
                                            {{ $t['commission_paid'] ? 'Komisi dibayar' : ($t['tertunggak'] ? 'Tertunggak' : 'Berjalan') }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div></div>
    </div>
</div>

<h6 class="text-muted mb-2 mt-2">Statistik Order &amp; Tagihan</h6>
<div class="row">
    <div class="col-md-3 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title mb-0">Jumlah Order (bulan ini)</h6>
            <h4 class="mt-2 mb-0 text-primary">{{ $mkt['jumlah_order_bulan_ini'] }}</h4>
            @include('dashboard.partials.delta', ['delta' => $mkt['jumlah_order_bulan_ini_delta']])
        </div></div>
    </div>
    <div class="col-md-3 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title mb-0">Jumlah Order (tahun ini)</h6>
            <h4 class="mt-2 mb-0 text-dark">{{ $mkt['jumlah_order_tahun_ini'] }}</h4>
            @include('dashboard.partials.delta', ['delta' => $mkt['jumlah_order_delta']])
        </div></div>
    </div>
    <div class="col-md-3 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title mb-0">Total Piutang</h6>
            <h4 class="mt-2 mb-0 text-warning">Rp {{ number_format($mkt['total_piutang'], 0, ',', '.') }}</h4>
            <small class="text-muted mt-2 d-block">Sisa tagihan order belum lunas</small>
        </div></div>
    </div>
    <div class="col-md-3 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title mb-0">Rata-rata Nilai Order</h6>
            <h4 class="mt-2 mb-0 text-dark">Rp {{ number_format($mkt['rata_rata_order'], 0, ',', '.') }}</h4>
            <small class="text-muted mt-2 d-block">Tahun ini</small>
        </div></div>
    </div>
</div>

<h6 class="text-muted mb-2 mt-2">Perbandingan per Marketing (tahun ini)</h6>
<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Pemasukan &amp; Refund (atas) · Jumlah Order (bawah)</h6>
            <div id="coMarketingCompare" style="height:420px"></div>
        </div></div>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mt-2 mb-2">
    <h6 class="text-muted mb-0">Traffic</h6>
    <div class="btn-group btn-group-sm" id="coRangeToggle">
        <button type="button" class="btn btn-outline-primary" data-range="7">7 hari</button>
        <button type="button" class="btn btn-primary active" data-range="30">30 hari</button>
        <button type="button" class="btn btn-outline-primary" data-range="90">90 hari</button>
    </div>
</div>
<div class="row">
    <div class="col-md-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Tren Pemasukan</h6><div id="coIncomeChart" style="height:260px"></div>
        </div></div>
    </div>
    <div class="col-md-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Tren Jumlah Order</h6><div id="coOrderChart" style="height:260px"></div>
        </div></div>
    </div>
</div>

<h6 class="text-muted mb-2 mt-2">Produksi Global</h6>
@include('dashboard.partials.progress-global')

<h6 class="text-muted mb-2 mt-2">Ketepatan Produksi (30 hari)</h6>
<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">On-time % (atas) · Jumlah Selesai (bawah) per staf produksi</h6>
            <div id="coProdAccuracy" style="height:420px"></div>
        </div></div>
    </div>
</div>

<h6 class="text-muted mb-2 mt-2">Naskah Mendekati Deadline</h6>
<div class="row">
    @include('dashboard.partials.deadline-table', ['rows' => $mkt['deadline_rows'], 'tableId' => 'coDeadline'])
</div>

@if($cash)
    @include('dashboard.partials.cash-block')
@elseif(auth()->user()->hasRole('superadmin'))
    <div class="card grid-margin"><div class="card-body">
        <h6 class="card-title mb-1">Kas</h6>
        <p class="text-muted mb-0">Data kas tidak tersedia saat ini.</p>
    </div></div>
@endif

@push('plugin-scripts')
    <script src="{{ asset('assets/plugins/apexcharts/apexcharts.min.js') }}"></script>
    <script src="{{ asset('assets/js/dashboard-charts.js') }}"></script>
    <script src="{{ asset('assets/plugins/echarts/echarts.min.js') }}"></script>
    <script src="{{ asset('assets/js/simapa-echarts.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var E = window.SimapaECharts;

    // ---- (1) Perbandingan per marketing — satu dataset, dua grid berbagi ----
    var marketing = @json($perMarketing);
    E.init('#coMarketingCompare',
        E.sharedDualGrid(marketing, 'name',
            { title: 'Rp', money: true, series: [
                { name: 'Pemasukan', dim: 'pemasukan', color: E.PALETTE.success },
                { name: 'Refund',    dim: 'refund',    color: E.PALETTE.danger },
            ] },
            { title: 'Order', money: false, series: [
                { name: 'Jumlah Order', dim: 'order', color: E.PALETTE.primary },
            ] }
        ), marketing, ['pemasukan', 'refund', 'order']);

    // ---- (2) Ketepatan produksi — satu dataset, dua grid berbagi ----
    var editors = @json($editors).map(function (e) {
        return { name: e.name, on_time: e.stats.on_time_rate == null ? 0 : e.stats.on_time_rate, selesai: e.stats.completed };
    }).sort(function (a, b) { return b.on_time - a.on_time; });
    E.init('#coProdAccuracy',
        E.sharedDualGrid(editors, 'name',
            { title: '%', money: false, max: 100, series: [
                { name: 'On-time %', dim: 'on_time', color: E.PALETTE.success },
            ] },
            { title: 'Selesai', money: false, series: [
                { name: 'Jumlah Selesai', dim: 'selesai', color: E.PALETTE.info },
            ] }
        ), editors, ['on_time', 'selesai']);

    // ---- (3) Traffic (restyle ECharts) + toggle 7/30/90 ----
    var full = {
        inc: { labels: @json($mkt['income_trend']['labels']), series: @json($mkt['income_trend']['series']) },
        ord: { labels: @json($mkt['order_trend']['labels']),  series: @json($mkt['order_trend']['series']) },
    };
    function sliceArr(a, n) { return a.slice(-n); }
    var incChart = E.init('#coIncomeChart', E.areaTrend(sliceArr(full.inc.labels, 30), sliceArr(full.inc.series, 30), E.PALETTE.success, true));
    var ordChart = E.init('#coOrderChart',  E.areaTrend(sliceArr(full.ord.labels, 30), sliceArr(full.ord.series, 30), E.PALETTE.primary, false));

    document.querySelectorAll('#coRangeToggle [data-range]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var n = +this.dataset.range;
            if (incChart) incChart.setOption(E.areaTrend(sliceArr(full.inc.labels, n), sliceArr(full.inc.series, n), E.PALETTE.success, true), true);
            if (ordChart) ordChart.setOption(E.areaTrend(sliceArr(full.ord.labels, n), sliceArr(full.ord.series, n), E.PALETTE.primary, false), true);
            document.querySelectorAll('#coRangeToggle [data-range]').forEach(function (b) {
                b.classList.remove('btn-primary', 'active'); b.classList.add('btn-outline-primary');
            });
            this.classList.remove('btn-outline-primary'); this.classList.add('btn-primary', 'active');
        });
    });
});
$(function () {
    if ($.fn.DataTable && document.getElementById('teamTargetTable')) {
        $('#teamTargetTable').DataTable({ pageLength: 10, responsive: true, order: [[4, 'desc']] });
        $('#teamTargetTable_wrapper .dataTables_length select, #teamTargetTable_wrapper .dataTables_filter input').addClass('form-control mb-2');
    }
});
</script>
@endpush
