@extends('layouts.master')
@section('title', 'Target Marketing - SiMAPA')

@section('content')
@php
    $bulanNama = [1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];
@endphp
<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
                <h6 class="card-title mb-0">Target Marketing — {{ $bulanNama[$month] }} {{ $year }}</h6>
                <form method="GET" class="d-flex" style="gap:.5rem">
                    <select name="month" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                        @foreach($bulanNama as $m => $nm)
                            <option value="{{ $m }}" {{ $m == $month ? 'selected' : '' }}>{{ $nm }}</option>
                        @endforeach
                    </select>
                    <select name="year" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                        @for($y = now()->year - 1; $y <= now()->year + 1; $y++)
                            <option value="{{ $y }}" {{ $y == $year ? 'selected' : '' }}>{{ $y }}</option>
                        @endfor
                    </select>
                </form>
            </div>

            <form method="POST" action="{{ route('marketing-target.save') }}">
                @csrf
                <input type="hidden" name="year" value="{{ $year }}">
                <input type="hidden" name="month" value="{{ $month }}">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Marketing</th>
                                <th>Target Pemasukan (Rp)</th>
                                <th>Rate Komisi (%)</th>
                                <th>Realisasi</th>
                                <th>Capaian</th>
                                <th>Komisi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rows as $r)
                                @php $cls = $r['capaian_persen'] >= 100 ? 'bg-success' : ($r['capaian_persen'] >= 75 ? 'bg-warning text-dark' : 'bg-danger'); @endphp
                                <tr>
                                    <td>{{ $r['name'] }}</td>
                                    <td><input type="number" min="0" step="1000" name="targets[{{ $r['user_id'] }}][target]" value="{{ $r['has_target'] ? $r['target'] : '' }}" class="form-control form-control-sm" placeholder="0"></td>
                                    <td><input type="number" min="0" max="100" step="0.5" name="targets[{{ $r['user_id'] }}][rate]" value="{{ $r['has_target'] ? $r['rate'] : '' }}" class="form-control form-control-sm" style="width:90px" placeholder="0"></td>
                                    <td>Rp {{ number_format($r['realisasi'], 0, ',', '.') }}</td>
                                    <td><span class="badge {{ $r['has_target'] ? $cls : 'bg-secondary' }}">{{ $r['has_target'] ? $r['capaian_persen'].'%' : '—' }}</span></td>
                                    <td>Rp {{ number_format($r['komisi'], 0, ',', '.') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted py-3">Belum ada user marketing.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <button type="submit" class="btn btn-primary mt-2">Simpan</button>
            </form>
        </div></div>
    </div>
</div>
@endsection
