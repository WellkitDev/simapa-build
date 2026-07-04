@extends('layouts.master')
@section('title', 'Anggaran & Target - SiMAPA')

@section('content')
@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Anggaran & Target</h5>
    <form method="GET" class="d-flex gap-2 align-items-center">
        <input type="number" name="year" value="{{ $year }}" class="form-control form-control-sm" style="width:100px">
        <button class="btn btn-sm btn-outline-secondary">Tahun</button>
    </form>
</div>

<div class="row">
    <div class="col-md-7 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
        <h6 class="card-title">Set Target Perusahaan (per Bulan)</h6>
        <form method="POST" action="{{ route('accounting.target.update') }}" class="d-flex gap-2 align-items-end flex-wrap">
            @csrf @method('PUT')
            <div><label class="form-label small mb-1">Target Operasional (Rp/bln)</label><input type="text" name="target_operasional" value="{{ (int) $setting->target_operasional }}" min="0" class="form-control form-control-sm money-mask" inputmode="numeric" style="width:180px"></div>
            <div><label class="form-label small mb-1">Target Order (Rp/bln)</label><input type="text" name="target_order" value="{{ (int) $setting->target_order }}" min="0" class="form-control form-control-sm money-mask" inputmode="numeric" style="width:180px"></div>
            <button class="btn btn-sm btn-primary">Simpan Target</button>
        </form>
        <p class="text-muted small mb-0 mt-2">Total Biaya Tetap/bln (Asumsi): <strong>{{ $rp($fixedMonthly) }}</strong> · Target order ≈ operasional ÷ 40%.</p>
    </div></div></div>
    <div class="col-md-5 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
        <h6 class="card-title">Skenario Order/Bulan</h6>
        <table class="table table-sm mb-0">
            @foreach($scenarios as $label => $amount)
                <tr><td>{{ $label }}</td><td class="text-end">{{ $rp($amount) }}</td></tr>
            @endforeach
        </table>
    </div></div></div>
</div>

<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <h6 class="card-title">Realisasi vs Target {{ $year }}</h6>
    <div class="table-responsive">
        <table class="table table-sm table-hover">
            <thead><tr><th>Bulan</th><th class="text-end">Pemasukan Kas (Realisasi)</th><th class="text-end">Target Operasional</th><th class="text-end">% Pencapaian</th><th>Status</th></tr></thead>
            <tbody>
                @foreach($monthly as $r)
                    <tr>
                        <td>{{ $r['label'] }}</td>
                        <td class="text-end">{{ $rp($r['realisasi']) }}</td>
                        <td class="text-end">{{ $rp($r['target']) }}</td>
                        <td class="text-end"><span class="badge {{ $r['achieved'] ? 'bg-success' : ($r['pct'] > 0 ? 'bg-warning text-dark' : 'bg-light text-muted border') }}">{{ $r['pct'] }}%</span></td>
                        <td>{{ $r['achieved'] ? '✓ Tercapai' : ($r['target'] > 0 ? 'Kurang' : '—') }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot><tr class="fw-bold">
                <td>YTD</td>
                <td class="text-end">{{ $rp($ytdRealisasi) }}</td>
                <td class="text-end">{{ $rp($ytdTarget) }}</td>
                <td class="text-end">{{ $ytdTarget > 0 ? (int) round($ytdRealisasi / $ytdTarget * 100) : 0 }}%</td>
                <td></td>
            </tr></tfoot>
        </table>
    </div>
</div></div></div></div>
@include('accounting.partials.money-mask')
@endsection
