@extends('layouts.master')
@section('title', 'Distribusi Profit - SiMAPA')

@section('content')
@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Distribusi Profit</h5>
    <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
        <input type="number" name="year" value="{{ $year }}" class="form-control form-control-sm" style="width:90px">
        <select name="month" class="form-select form-select-sm" style="width:130px">
            @for($m = 1; $m <= 12; $m++)<option value="{{ $m }}" {{ $month === $m ? 'selected' : '' }}>{{ \Carbon\Carbon::create()->month($m)->translatedFormat('F') }}</option>@endfor
        </select>
        <input type="text" name="profit" value="{{ (int) $profit }}" class="form-control form-control-sm money-mask" inputmode="numeric" style="width:150px" placeholder="Profit (Rp)" title="Kosongkan untuk pakai laba kas bulan">
        <button class="btn btn-sm btn-outline-secondary">Hitung</button>
    </form>
</div>

@include('accounting.partials.expense-warning')

<div class="row"><div class="col-md-8 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <p class="mb-2">Profit dihitung: <strong>{{ $rp($result['profit']) }}</strong> · Anggota tim: <strong>{{ $result['members'] }}</strong>
        <small class="text-muted">(kosongkan input profit untuk pakai laba kas bulan)</small></p>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr><th>Pos</th><th>Tipe</th><th class="text-end">Nilai</th><th class="text-end">Alokasi</th><th class="text-end">Per Orang</th></tr></thead>
            <tbody>
                @foreach($result['lines'] as $l)
                    <tr>
                        <td>{{ $l['name'] }}</td>
                        <td><span class="badge {{ $l['type'] === 'percent' ? 'bg-info' : 'bg-secondary' }}">{{ \App\Models\CashDistribution::TYPES[$l['type']] ?? $l['type'] }}</span></td>
                        <td class="text-end">{{ $l['type'] === 'percent' ? rtrim(rtrim(number_format($l['value'], 2), '0'), '.') . '%' : $rp($l['value']) }}</td>
                        <td class="text-end">{{ $rp($l['amount']) }}</td>
                        <td class="text-end">{{ $l['perPerson'] !== null ? $rp($l['perPerson']) : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="fw-bold"><td colspan="3">Total Teralokasi</td><td class="text-end">{{ $rp($result['totalAllocated']) }}</td><td></td></tr>
                <tr class="{{ $result['remainder'] < 0 ? 'text-danger' : 'text-muted' }}"><td colspan="3">Sisa / Selisih</td><td class="text-end">{{ $rp($result['remainder']) }}</td><td></td></tr>
            </tfoot>
        </table>
    </div>
</div></div></div></div>

<div class="row"><div class="col-md-8 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#distConfig">Kelola Aturan & Anggota</button>
    <div class="collapse mt-3" id="distConfig">
        <form method="POST" action="{{ route('accounting.distribution.settings') }}" class="d-flex gap-2 align-items-end mb-3">
            @csrf @method('PUT')
            <div><label class="form-label small mb-1">Jumlah Anggota Tim</label><input type="number" name="team_members" value="{{ $setting->team_members }}" min="1" class="form-control form-control-sm" style="width:120px"></div>
            <button class="btn btn-sm btn-primary">Simpan Anggota</button>
        </form>

        <div class="text-muted small fw-semibold mb-1">Aturan Distribusi</div>
        <p class="text-muted small mb-2">
            <strong>Persen</strong> = % dari profit (bila <em>per anggota</em>, total dibagi jumlah anggota).
            <strong>Flat + per anggota</strong> = nominal <em>per orang</em> (mis. gaji pokok) → total = nominal × jumlah anggota.
            <strong>Flat</strong> tanpa per anggota = nominal tetap.
        </p>
        @foreach($rules as $r)
            <div class="d-flex gap-2 align-items-center mb-1 flex-wrap">
                <form method="POST" action="{{ route('accounting.distribution.rule.update', $r->id) }}" class="d-flex gap-2 align-items-center flex-wrap flex-grow-1 m-0">
                    @csrf @method('PUT')
                    <input name="name" value="{{ $r->name }}" class="form-control form-control-sm" style="max-width:180px">
                    <select name="type" class="form-select form-select-sm" style="max-width:110px">@foreach(\App\Models\CashDistribution::TYPES as $tk => $tl)<option value="{{ $tk }}" {{ $r->type === $tk ? 'selected' : '' }}>{{ $tl }}</option>@endforeach</select>
                    <input type="number" step="0.01" name="value" value="{{ (float) $r->value }}" class="form-control form-control-sm" style="max-width:110px">
                    <label class="small mb-0"><input type="checkbox" name="per_member" value="1" {{ $r->per_member ? 'checked' : '' }}> per anggota</label>
                    <label class="small mb-0"><input type="checkbox" name="active" value="1" {{ $r->active ? 'checked' : '' }}> aktif</label>
                    <button class="btn btn-xs btn-outline-primary">Simpan</button>
                </form>
                <form method="POST" action="{{ route('accounting.distribution.rule.destroy', $r->id) }}" data-confirm="Hapus aturan ini?" class="m-0">@csrf @method('DELETE')<button class="btn btn-xs btn-outline-danger">×</button></form>
            </div>
        @endforeach

        <form method="POST" action="{{ route('accounting.distribution.rule.store') }}" class="d-flex gap-2 align-items-center flex-wrap mt-2">
            @csrf
            <input name="name" placeholder="Pos baru…" class="form-control form-control-sm" style="max-width:180px">
            <select name="type" class="form-select form-select-sm" style="max-width:110px"><option value="percent">Persen</option><option value="flat">Flat</option></select>
            <input type="number" step="0.01" name="value" placeholder="Nilai" class="form-control form-control-sm" style="max-width:110px">
            <label class="small mb-0"><input type="checkbox" name="per_member" value="1"> per anggota</label>
            <button class="btn btn-xs btn-outline-success">+ Tambah</button>
        </form>
    </div>
</div></div></div></div>
@include('accounting.partials.money-mask')
@endsection
