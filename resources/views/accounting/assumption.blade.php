@extends('layouts.master')
@section('title', 'Asumsi - SiMAPA')

@section('content')
@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp
<h5 class="mb-3">Asumsi Keuangan</h5>

<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <h6 class="card-title">Margin per Produk</h6>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead><tr><th>Kode</th><th>Label</th><th style="width:120px">Margin %</th><th style="width:70px">Aktif</th><th style="width:120px">Aksi</th></tr></thead>
            <tbody>
                @foreach($margins as $m)
                    <tr>
                        <td colspan="5" class="p-1">
                            <div class="d-flex gap-2 align-items-center flex-wrap">
                                <form method="POST" action="{{ route('accounting.assumption.margin.update', $m->id) }}" class="d-flex gap-2 align-items-center flex-wrap flex-grow-1 m-0">
                                    @csrf @method('PUT')
                                    <input name="code" value="{{ $m->code }}" class="form-control form-control-sm" style="max-width:120px" placeholder="Kode">
                                    <input name="label" value="{{ $m->label }}" class="form-control form-control-sm" style="max-width:280px">
                                    <input type="number" step="0.01" name="margin_pct" value="{{ (float) $m->margin_pct }}" class="form-control form-control-sm" style="max-width:100px">
                                    <label class="small mb-0"><input type="checkbox" name="active" value="1" {{ $m->active ? 'checked' : '' }}> aktif</label>
                                    <button class="btn btn-xs btn-outline-primary">Simpan</button>
                                </form>
                                <form method="POST" action="{{ route('accounting.assumption.margin.destroy', $m->id) }}" data-confirm="Hapus margin ini?" class="m-0">@csrf @method('DELETE')<button class="btn btn-xs btn-outline-danger">×</button></form>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <form method="POST" action="{{ route('accounting.assumption.margin.store') }}" class="d-flex gap-2 align-items-center flex-wrap mt-2">
        @csrf
        <input name="code" placeholder="Kode" class="form-control form-control-sm" style="max-width:120px">
        <input name="label" placeholder="Label produk…" class="form-control form-control-sm" style="max-width:280px">
        <input type="number" step="0.01" name="margin_pct" placeholder="%" class="form-control form-control-sm" style="max-width:100px">
        <button class="btn btn-xs btn-outline-success">+ Tambah Margin</button>
    </form>
</div></div></div></div>

<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <h6 class="card-title">Biaya Tetap Bulanan</h6>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead><tr><th>Nama</th><th>Periode</th><th class="text-end">Nominal</th><th class="text-end">Per Bulan</th><th style="width:70px">Aktif</th><th style="width:120px">Aksi</th></tr></thead>
            <tbody>
                @foreach($expenses as $e)
                    <tr>
                        <td colspan="6" class="p-1">
                            <div class="d-flex gap-2 align-items-center flex-wrap">
                                <form method="POST" action="{{ route('accounting.assumption.expense.update', $e->id) }}" class="d-flex gap-2 align-items-center flex-wrap flex-grow-1 m-0">
                                    @csrf @method('PUT')
                                    <input name="name" value="{{ $e->name }}" class="form-control form-control-sm" style="max-width:220px">
                                    <select name="period" class="form-select form-select-sm" style="max-width:110px">@foreach(\App\Models\CashFixedExpense::PERIODS as $pk => $pl)<option value="{{ $pk }}" {{ $e->period === $pk ? 'selected' : '' }}>{{ $pl }}</option>@endforeach</select>
                                    <input type="text" name="amount" value="{{ (int) $e->amount }}" class="form-control form-control-sm money-mask" inputmode="numeric" style="max-width:140px">
                                    <span class="text-muted small">= {{ $rp($e->monthlyAmount()) }}/bln</span>
                                    <label class="small mb-0"><input type="checkbox" name="active" value="1" {{ $e->active ? 'checked' : '' }}> aktif</label>
                                    <button class="btn btn-xs btn-outline-primary">Simpan</button>
                                </form>
                                <form method="POST" action="{{ route('accounting.assumption.expense.destroy', $e->id) }}" data-confirm="Hapus biaya ini?" class="m-0">@csrf @method('DELETE')<button class="btn btn-xs btn-outline-danger">×</button></form>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot><tr class="fw-bold"><td colspan="3">Total Biaya Tetap / Bulan</td><td class="text-end">{{ $rp($totalMonthly) }}</td><td colspan="2"></td></tr></tfoot>
        </table>
    </div>
    <form method="POST" action="{{ route('accounting.assumption.expense.store') }}" class="d-flex gap-2 align-items-center flex-wrap mt-2">
        @csrf
        <input name="name" placeholder="Nama biaya…" class="form-control form-control-sm" style="max-width:220px">
        <select name="period" class="form-select form-select-sm" style="max-width:110px"><option value="bulanan">Bulanan</option><option value="tahunan">Tahunan</option></select>
        <input type="text" name="amount" placeholder="Nominal" class="form-control form-control-sm money-mask" inputmode="numeric" style="max-width:140px">
        <button class="btn btn-xs btn-outline-success">+ Tambah Biaya</button>
    </form>
</div></div></div></div>
@include('accounting.partials.money-mask')
@endsection
