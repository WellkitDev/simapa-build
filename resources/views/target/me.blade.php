@extends('layouts.master')
@section('title', 'Target Saya - SiMAPA')

@section('content')
@php
    $berjalan = $rows->whereIn('status', ['aktif', 'akan_datang'])->values();
    $history  = $rows->where('status', 'berakhir')->values();
@endphp

<div class="d-flex justify-content-between align-items-center flex-wrap grid-margin">
    <h4 class="mb-0">Target Saya</h4>
</div>

<h6 class="text-muted mb-2">Berjalan</h6>
<div class="row">
    @forelse($berjalan as $t)
        @php $ccls = $t['capaian_persen'] >= 100 ? 'bg-success' : ($t['capaian_persen'] >= 75 ? 'bg-warning text-dark' : 'bg-danger'); @endphp
        <div class="col-md-6 grid-margin stretch-card">
            <div class="card"><div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-1 flex-wrap">
                    <span><strong>{{ \Illuminate\Support\Carbon::parse($t['start_date'])->format('d M') }} – {{ \Illuminate\Support\Carbon::parse($t['end_date'])->format('d M Y') }}</strong>
                        <span class="badge bg-info">{{ $t['status'] === 'aktif' ? 'Aktif' : 'Akan datang' }}</span></span>
                    <span class="text-muted">Komisi: <strong class="text-success">Rp {{ number_format($t['komisi'], 0, ',', '.') }}</strong></span>
                </div>
                <div class="progress mb-2" style="height:16px">
                    <div class="progress-bar {{ $ccls }}" style="width: {{ min($t['capaian_persen'], 100) }}%">{{ $t['capaian_persen'] }}%</div>
                </div>
                <div class="d-flex justify-content-between small">
                    <span class="text-muted">Target: Rp {{ number_format($t['target'], 0, ',', '.') }}</span>
                    <span class="text-primary">Realisasi: Rp {{ number_format($t['realisasi'], 0, ',', '.') }}</span>
                    <span class="{{ $t['sisa'] > 0 ? 'text-danger' : 'text-success' }}">Sisa: Rp {{ number_format($t['sisa'], 0, ',', '.') }}</span>
                </div>
            </div></div>
        </div>
    @empty
        <div class="col-12 grid-margin"><p class="text-muted">Tidak ada target berjalan.</p></div>
    @endforelse
</div>

<h6 class="text-muted mb-2 mt-2">History</h6>
<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead><tr><th>Periode</th><th>Target</th><th>Realisasi</th><th>Capaian</th><th>Komisi</th><th>Status Komisi</th></tr></thead>
                    <tbody>
                        @forelse($history as $t)
                            <tr @if($t['tertunggak']) class="table-warning" @endif>
                                <td><small>{{ \Illuminate\Support\Carbon::parse($t['start_date'])->format('d/m/y') }} – {{ \Illuminate\Support\Carbon::parse($t['end_date'])->format('d/m/y') }}</small></td>
                                <td>Rp {{ number_format($t['target'], 0, ',', '.') }}</td>
                                <td>Rp {{ number_format($t['realisasi'], 0, ',', '.') }}</td>
                                <td>{{ $t['capaian_persen'] }}%</td>
                                <td>Rp {{ number_format($t['komisi'], 0, ',', '.') }}</td>
                                <td>
                                    @if($t['commission_paid'])
                                        <span class="badge bg-success">Dibayar</span>
                                    @elseif($t['tertunggak'])
                                        <span class="badge bg-danger">Belum (tertunggak)</span>
                                    @else
                                        <span class="badge bg-secondary">Belum dibayar</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-3">Belum ada target berakhir.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div></div>
    </div>
</div>
@endsection
