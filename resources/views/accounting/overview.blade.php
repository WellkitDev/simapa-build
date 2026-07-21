@extends('layouts.master')
@section('title', 'Ringkasan Keuangan - SiMAPA')

@section('content')
@php
    $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
    $purposeBadge = ['pemasukan' => 'bg-success', 'operational' => 'bg-primary', 'harta' => 'bg-warning text-dark', 'umum' => 'bg-secondary'];
@endphp
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Ringkasan Keuangan</h5>
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <form method="GET" class="d-flex gap-2 align-items-center">
            <input type="number" name="year" value="{{ $year }}" class="form-control form-control-sm" style="width:100px">
            <button class="btn btn-sm btn-outline-secondary">Tahun</button>
        </form>
        @can('accounting.recap.export')
        <a href="{{ route('accounting.recap.export.csv', ['year' => $year]) }}" class="btn btn-sm btn-outline-success">Export CSV</a>
        <a href="{{ route('accounting.recap.export.pdf', ['year' => $year]) }}" class="btn btn-sm btn-outline-danger">Export PDF</a>
        @endcan
    </div>
</div>

@include('accounting.partials.expense-warning')

<div class="row">
    <div class="col-md-3 col-6 grid-margin stretch-card"><div class="card bg-dark text-white"><div class="card-body py-3"><div class="small text-white-50">Total Saldo Semua Akun</div><div class="h5 mb-0">{{ $rp($balances['total']) }}</div></div></div></div>
    <div class="col-md-3 col-6 grid-margin stretch-card"><div class="card"><div class="card-body py-3"><div class="text-muted small">Pemasukan YTD</div><div class="h5 mb-0 text-success">{{ $rp($ytd['totalIn']) }}</div></div></div></div>
    <div class="col-md-3 col-6 grid-margin stretch-card"><div class="card"><div class="card-body py-3"><div class="text-muted small">Pengeluaran YTD</div><div class="h5 mb-0 text-danger">{{ $rp($ytd['totalOut']) }}</div></div></div></div>
    <div class="col-md-3 col-6 grid-margin stretch-card"><div class="card"><div class="card-body py-3"><div class="text-muted small">Laba YTD</div><div class="h5 mb-0">{{ $rp($ytd['laba']) }}</div></div></div></div>
</div>

<div class="row">
    <div class="col-lg-7 grid-margin stretch-card"><div class="card"><div class="card-body">
        <h6 class="mb-3">Saldo per Akun</h6>
        <div class="row">
            @foreach($balances['rows'] as $row)
                <div class="col-md-6 mb-2">
                    <div class="border rounded p-2 d-flex justify-content-between align-items-center">
                        <span>{{ $row['account']->name }}
                            @if($row['account']->purpose)<span class="badge {{ $purposeBadge[$row['account']->purpose] ?? 'bg-secondary' }}">{{ \App\Models\CashAccount::PURPOSES[$row['account']->purpose] ?? $row['account']->purpose }}</span>@endif
                        </span>
                        <strong>{{ $rp($row['saldo']) }}</strong>
                    </div>
                </div>
            @endforeach
        </div>
    </div></div></div>
    <div class="col-lg-5 grid-margin stretch-card"><div class="card"><div class="card-body">
        <h6 class="mb-3">Target & Biaya</h6>
        <div class="d-flex justify-content-between mb-2"><span class="text-muted">Realisasi YTD</span><strong>{{ $rp($ytdRealisasi) }}</strong></div>
        <div class="d-flex justify-content-between mb-2"><span class="text-muted">Target YTD</span><strong>{{ $rp($ytdTarget) }}</strong></div>
        <div class="d-flex justify-content-between mb-2"><span class="text-muted">Pencapaian</span><span class="badge {{ $pct >= 100 ? 'bg-success' : 'bg-warning text-dark' }}">{{ $pct }}%</span></div>
        <div class="d-flex justify-content-between"><span class="text-muted">Total Biaya Tetap / bln</span><strong>{{ $rp($fixedMonthly) }}</strong></div>
    </div></div></div>
</div>

<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <h6 class="mb-3">Pintasan</h6>
    <div class="d-flex gap-2 flex-wrap">
        <a href="{{ route('accounting.journal') }}" class="btn btn-sm btn-outline-primary">Jurnal Kas</a>
        <a href="{{ route('accounting.dashboard') }}" class="btn btn-sm btn-outline-primary">Dashboard</a>
        <a href="{{ route('accounting.distribution') }}" class="btn btn-sm btn-outline-primary">Distribusi Profit</a>
        <a href="{{ route('accounting.assumption') }}" class="btn btn-sm btn-outline-primary">Asumsi</a>
        <a href="{{ route('accounting.target') }}" class="btn btn-sm btn-outline-primary">Anggaran & Target</a>
    </div>
</div></div></div>
@endsection
