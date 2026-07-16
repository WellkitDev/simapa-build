@extends('layouts.master')
@section('title', 'Dashboard Keuangan - SiMAPA')

@section('content')
@php
    $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
    $pctArtikel = ($ytd['incomeArtikel'] + $ytd['incomeBuku']) > 0 ? round($ytd['incomeArtikel'] / ($ytd['incomeArtikel'] + $ytd['incomeBuku']) * 100) : 0;
    $chartLabels = array_column($recap, 'label');
    $chartIn   = array_map(fn ($r) => (int) $r['totalIn'], $recap);
    $chartOut  = array_map(fn ($r) => (int) $r['totalOut'], $recap);
    $chartLaba = array_map(fn ($r) => (int) $r['laba'], $recap);
    $expLabels = array_keys($ytd['expenseByCategory']);
    $expVals   = array_map('intval', array_values($ytd['expenseByCategory']));
@endphp
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Dashboard Keuangan</h5>
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <form method="GET" class="d-flex gap-2 align-items-center">
            <input type="number" name="year" value="{{ $year }}" class="form-control form-control-sm" style="width:100px">
            <button class="btn btn-sm btn-outline-secondary">Tahun</button>
        </form>
        <a href="{{ route('accounting.recap.export.csv', ['year' => $year]) }}" class="btn btn-sm btn-outline-success">Export Rekap CSV</a>
        <a href="{{ route('accounting.recap.export.pdf', ['year' => $year]) }}" class="btn btn-sm btn-outline-danger">Export Rekap PDF</a>
    </div>
</div>

@include('accounting.partials.expense-warning')

<div class="row">
    <div class="col-md-3 col-6 grid-margin stretch-card"><div class="card"><div class="card-body py-3"><div class="text-muted small">Total Pemasukan</div><div class="h5 mb-0 text-success">{{ $rp($ytd['totalIn']) }}</div></div></div></div>
    <div class="col-md-3 col-6 grid-margin stretch-card"><div class="card"><div class="card-body py-3"><div class="text-muted small">Total Pengeluaran</div><div class="h5 mb-0 text-danger">{{ $rp($ytd['totalOut']) }}</div></div></div></div>
    <div class="col-md-3 col-6 grid-margin stretch-card"><div class="card"><div class="card-body py-3"><div class="text-muted small">Laba Bersih (Kas)</div><div class="h5 mb-0">{{ $rp($ytd['laba']) }}</div></div></div></div>
    <div class="col-md-3 col-6 grid-margin stretch-card"><div class="card"><div class="card-body py-3"><div class="text-muted small">Saldo Terakhir</div><div class="h5 mb-0">{{ $rp($ytd['saldoAkhir']) }}</div></div></div></div>
</div>
<div class="row">
    <div class="col-md-4 col-6 grid-margin stretch-card"><div class="card"><div class="card-body py-3"><div class="text-muted small">Rata² Laba/Bulan</div><div class="h6 mb-0">{{ $rp($ytd['avgLaba']) }}</div></div></div></div>
    <div class="col-md-4 col-6 grid-margin stretch-card"><div class="card"><div class="card-body py-3"><div class="text-muted small">Artikel vs Buku</div><div class="h6 mb-0">{{ $pctArtikel }}% Artikel</div></div></div></div>
    <div class="col-md-4 col-12 grid-margin stretch-card"><div class="card"><div class="card-body py-3"><div class="text-muted small">Bulan Terbaik (Laba)</div><div class="h6 mb-0">{{ $ytd['bestMonthLabel'] ?? '—' }}</div></div></div></div>
</div>

<div class="row">
    <div class="col-md-7 col-12 grid-margin stretch-card"><div class="card"><div class="card-body"><h6 class="card-title">Tren Bulanan</h6><div id="cashTrendChart"></div></div></div></div>
    <div class="col-md-5 col-12 grid-margin stretch-card"><div class="card"><div class="card-body"><h6 class="card-title">Breakdown Pengeluaran</h6><div id="cashExpenseChart"></div></div></div></div>
</div>

<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <h6 class="card-title">Rekap Bulanan {{ $year }}</h6>
    <div class="table-responsive">
        <table class="table table-sm table-hover">
            <thead><tr><th>Bulan</th><th class="text-end">Pemasukan Artikel</th><th class="text-end">Pemasukan Buku</th><th class="text-end">Total Pemasukan</th><th class="text-end">Total Pengeluaran</th><th class="text-end">Laba</th><th class="text-end">Saldo Akhir</th></tr></thead>
            <tbody>
                @foreach($recap as $r)
                    <tr>
                        <td>{{ $r['label'] }}</td>
                        <td class="text-end">{{ $rp($r['inArtikel']) }}</td>
                        <td class="text-end">{{ $rp($r['inBuku']) }}</td>
                        <td class="text-end">{{ $rp($r['totalIn']) }}</td>
                        <td class="text-end">{{ $rp($r['totalOut']) }}</td>
                        <td class="text-end {{ $r['laba'] < 0 ? 'text-danger' : '' }}">{{ $rp($r['laba']) }}</td>
                        <td class="text-end">{{ $rp($r['saldoAkhir']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot><tr class="fw-bold">
                <td>TOTAL YTD</td>
                <td class="text-end">{{ $rp($ytd['incomeArtikel']) }}</td>
                <td class="text-end">{{ $rp($ytd['incomeBuku']) }}</td>
                <td class="text-end">{{ $rp($ytd['totalIn']) }}</td>
                <td class="text-end">{{ $rp($ytd['totalOut']) }}</td>
                <td class="text-end">{{ $rp($ytd['laba']) }}</td>
                <td class="text-end">{{ $rp($ytd['saldoAkhir']) }}</td>
            </tr></tfoot>
        </table>
    </div>
</div></div></div>
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/plugins/apexcharts/apexcharts.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
(function () {
    var labels = @json($chartLabels);
    var pemasukan = @json($chartIn);
    var pengeluaran = @json($chartOut);
    var laba = @json($chartLaba);
    var expLabels = @json($expLabels);
    var expVals = @json($expVals);

    if (window.ApexCharts) {
        new ApexCharts(document.querySelector('#cashTrendChart'), {
            chart: { height: 300, type: 'line', toolbar: { show: false } },
            series: [
                { name: 'Pemasukan', type: 'column', data: pemasukan },
                { name: 'Pengeluaran', type: 'column', data: pengeluaran },
                { name: 'Laba', type: 'line', data: laba },
            ],
            xaxis: { categories: labels },
            stroke: { width: [0, 0, 3] },
            colors: ['#22c55e', '#ef4444', '#3b82f6'],
        }).render();

        new ApexCharts(document.querySelector('#cashExpenseChart'), {
            chart: { height: 300, type: 'donut' },
            series: expVals.length ? expVals : [1],
            labels: expLabels.length ? expLabels : ['Tidak ada'],
            legend: { position: 'bottom' },
        }).render();
    }
})();
</script>
@endpush
