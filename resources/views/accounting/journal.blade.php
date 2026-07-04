@extends('layouts.master')
@section('title', 'Jurnal Kas - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Jurnal Kas</h5>
    <form method="GET" class="d-flex gap-2 align-items-center">
        <input type="number" name="year" value="{{ $year }}" class="form-control form-control-sm" style="width:90px">
        <select name="month" class="form-select form-select-sm" style="width:130px">
            <option value="all" {{ $month === null ? 'selected' : '' }}>Semua bulan</option>
            @for($m = 1; $m <= 12; $m++)
                <option value="{{ $m }}" {{ $month === $m ? 'selected' : '' }}>{{ \Carbon\Carbon::create()->month($m)->translatedFormat('F') }}</option>
            @endfor
        </select>
        <select name="jenis" class="form-select form-select-sm" style="width:130px">
            <option value="">Semua jenis</option>
            <option value="pemasukan" {{ $jenis === 'pemasukan' ? 'selected' : '' }}>Pemasukan</option>
            <option value="pengeluaran" {{ $jenis === 'pengeluaran' ? 'selected' : '' }}>Pengeluaran</option>
        </select>
        <button class="btn btn-sm btn-outline-secondary">Filter</button>
    </form>
</div>

<div class="row">
    <div class="col-md-4 col-12 grid-margin stretch-card"><div class="card"><div class="card-body py-3"><div class="text-muted small">Total Pemasukan</div><div class="h5 mb-0 text-success">{{ $rp($totalIn) }}</div></div></div></div>
    <div class="col-md-4 col-12 grid-margin stretch-card"><div class="card"><div class="card-body py-3"><div class="text-muted small">Total Pengeluaran</div><div class="h5 mb-0 text-danger">{{ $rp($totalOut) }}</div></div></div></div>
    <div class="col-md-4 col-12 grid-margin stretch-card"><div class="card"><div class="card-body py-3"><div class="text-muted small">Saldo Akhir</div><div class="h5 mb-0">{{ $rp($saldoAkhir) }}</div></div></div></div>
</div>

<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="text-muted small">Saldo awal periode: {{ $rp($opening) }}</span>
        <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#entryForm">+ Tambah Transaksi</button>
    </div>

    <div class="collapse mb-3" id="entryForm">
        <form method="POST" action="{{ route('accounting.entry.store') }}" class="border rounded p-3">
            @csrf
            <div class="row g-2">
                <div class="col-md-2"><label class="form-label small mb-1">Tanggal</label><input type="date" name="tanggal" value="{{ now()->format('Y-m-d') }}" class="form-control form-control-sm" required></div>
                <div class="col-md-2"><label class="form-label small mb-1">Jenis</label><select name="jenis" class="form-select form-select-sm"><option value="pemasukan">Pemasukan</option><option value="pengeluaran">Pengeluaran</option></select></div>
                <div class="col-md-3"><label class="form-label small mb-1">Kategori</label>
                    <select name="cash_category_id" class="form-select form-select-sm">
                        <option value="">—</option>
                        @foreach($categories as $c)<option value="{{ $c->id }}" data-jenis="{{ $c->jenis }}">{{ $c->name }} ({{ \App\Models\CashCategory::JENIS[$c->jenis] }})</option>@endforeach
                    </select>
                </div>
                <div class="col-md-2"><label class="form-label small mb-1">Nominal</label><input type="number" name="amount" class="form-control form-control-sm" min="0" required></div>
                <div class="col-md-3"><label class="form-label small mb-1">Produk</label><select name="produk" class="form-select form-select-sm"><option value="">—</option>@foreach(\App\Models\CashEntry::PRODUK as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                <div class="col-md-4"><label class="form-label small mb-1">Keterangan</label><input name="keterangan" class="form-control form-control-sm" required></div>
                <div class="col-md-3"><label class="form-label small mb-1">Ref (INV/Order)</label><input name="ref" class="form-control form-control-sm"></div>
                <div class="col-md-5"><label class="form-label small mb-1">Catatan</label><input name="catatan" class="form-control form-control-sm"></div>
            </div>
            <button class="btn btn-sm btn-primary mt-2">Simpan</button>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover table-sm datatable" style="width:100%">
            <thead><tr><th>Tgl</th><th>Kode</th><th>Keterangan</th><th>Kategori</th><th>Produk</th><th class="text-end">Pemasukan</th><th class="text-end">Pengeluaran</th><th class="text-end">Saldo</th><th>Ref</th><th>Aksi</th></tr></thead>
            <tbody>
                @foreach($entries as $e)
                    <tr>
                        <td>{{ optional($e->tanggal)->format('d/m/y') }}</td>
                        <td>{{ $e->kode }}</td>
                        <td>{{ $e->keterangan }}</td>
                        <td>{{ $e->category?->name ?? '—' }}</td>
                        <td>{{ \App\Models\CashEntry::PRODUK[$e->produk] ?? '—' }}</td>
                        <td class="text-end">{{ $e->isPemasukan() ? $rp($e->amount) : '' }}</td>
                        <td class="text-end">{{ ! $e->isPemasukan() ? $rp($e->amount) : '' }}</td>
                        <td class="text-end">{{ $rp($e->saldo ?? 0) }}</td>
                        <td>{{ $e->ref ?? '—' }}</td>
                        <td><form method="POST" action="{{ route('accounting.entry.destroy', $e->id) }}" data-confirm="Hapus transaksi ini?" class="m-0">@csrf @method('DELETE')<button class="btn btn-xs btn-outline-danger">×</button></form></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-3">
        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#catForm">Kelola Kategori</button>
        <div class="collapse mt-2" id="catForm">
            @foreach(\App\Models\CashCategory::JENIS as $jk => $jl)
                <div class="text-muted small fw-semibold mt-2">{{ $jl }}</div>
                @foreach($allCategories->where('jenis', $jk) as $c)
                    <form method="POST" action="{{ route('accounting.category.update', $c->id) }}" class="d-flex gap-1 mb-1 align-items-center">
                        @csrf @method('PUT')
                        <input type="hidden" name="jenis" value="{{ $c->jenis }}">
                        <input name="name" value="{{ $c->name }}" class="form-control form-control-sm" style="max-width:280px">
                        <label class="small mb-0"><input type="checkbox" name="active" value="1" {{ $c->active ? 'checked' : '' }}> aktif</label>
                        <button class="btn btn-xs btn-outline-primary">Simpan</button>
                    </form>
                @endforeach
                <form method="POST" action="{{ route('accounting.category.store') }}" class="d-flex gap-1 mt-1">
                    @csrf
                    <input type="hidden" name="jenis" value="{{ $jk }}">
                    <input name="name" placeholder="Kategori {{ $jl }} baru…" class="form-control form-control-sm" style="max-width:280px">
                    <button class="btn btn-xs btn-outline-success">+ Tambah</button>
                </form>
            @endforeach
        </div>
    </div>
</div></div></div>
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
<script src="{{ asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>$(function () { $('.datatable').DataTable({ pageLength: 25, order: [], language: { emptyTable: 'Belum ada transaksi.' } }); });</script>
@endpush
