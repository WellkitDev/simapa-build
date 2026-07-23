@extends('layouts.master')
@section('title', 'Analisa Profit - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
<link href="{{ asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Analisa Profit</h5>
    <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
        <input type="number" name="year" value="{{ $year }}" class="form-control form-control-sm" style="width:90px">
        <select name="month" class="form-select form-select-sm" style="width:130px">
            @for($m = 1; $m <= 12; $m++)<option value="{{ $m }}" {{ $month === $m ? 'selected' : '' }}>{{ \Carbon\Carbon::create()->month($m)->translatedFormat('F') }}</option>@endfor
        </select>
        <button class="btn btn-sm btn-primary">Tampilkan</button>
    </form>
</div>

<div class="row">
    <div class="col-md-4 col-12 grid-margin stretch-card"><div class="card"><div class="card-body py-3">
        <div class="text-muted small">Uang Masuk (setelah refund)</div>
        <div class="h5 mb-0">{{ $rp($totalIn) }}</div>
    </div></div></div>
    <div class="col-md-4 col-12 grid-margin stretch-card"><div class="card"><div class="card-body py-3">
        <div class="text-muted small">Cadangan APC</div>
        <div class="h5 mb-0 text-warning">{{ $rp($totalReserve) }}</div>
    </div></div></div>
    <div class="col-md-4 col-12 grid-margin stretch-card"><div class="card bg-success text-white"><div class="card-body py-3">
        <div class="small text-white-50">Siap Dibagi</div>
        <div class="h4 mb-2">{{ $rp($totalMargin) }}</div>
        <a href="{{ route('accounting.distribution', ['year' => $year, 'month' => $month, 'profit' => (int) $totalMargin]) }}" class="btn btn-sm btn-light">Bagi profit ini &rarr;</a>
    </div></div></div>
</div>

@if($unknownTier > 0 || $noOrder > 0)
    <div class="alert alert-warning" role="alert">
        @if($unknownTier > 0)
            <div><strong>{{ $unknownTier }} pembayaran</strong> dari order yang belum punya indeksasi — dihitung memakai <strong>margin terendah (25%)</strong>. Tentukan indeksasinya agar angka ini akurat; angka "siap dibagi" akan menyesuaikan sendiri.</div>
        @endif
        @if($noOrder > 0)
            <div>{{ $noOrder }} pembayaran tanpa detail order tidak dibagi — tak punya jenis/indeksasi, jadi tak ada margin yang bisa dipakai.</div>
        @endif
    </div>
@endif

<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <h6 class="mb-3">Akumulasi {{ $year }}</h6>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead><tr><th>Bulan</th><th class="text-end">Uang Masuk</th><th class="text-end">Cadangan APC</th><th class="text-end">Pendapatan Margin</th></tr></thead>
            <tbody>
                @foreach($yearly as $row)
                    <tr class="{{ $row['month'] === $month ? 'table-active fw-bold' : '' }}">
                        <td>{{ $row['label'] }}</td>
                        <td class="text-end">{{ $rp($row['totalIn']) }}</td>
                        <td class="text-end">{{ $rp($row['totalReserve']) }}</td>
                        <td class="text-end">{{ $rp($row['totalMargin']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot><tr class="fw-bold">
                <td>TOTAL</td>
                <td class="text-end">{{ $rp(array_sum(array_column($yearly, 'totalIn'))) }}</td>
                <td class="text-end">{{ $rp(array_sum(array_column($yearly, 'totalReserve'))) }}</td>
                <td class="text-end">{{ $rp(array_sum(array_column($yearly, 'totalMargin'))) }}</td>
            </tr></tfoot>
        </table>
    </div>
</div></div></div></div>

<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <h6 class="mb-1">Rincian per Pembayaran</h6>
    <p class="text-muted small mb-3">
        Angka ini berbasis <strong>margin asumsi</strong> (<a href="{{ route('accounting.assumption') }}">Asumsi &rarr; Margin per Produk</a>), bukan biaya APC yang sesungguhnya —
        sistem belum bisa menautkan pengeluaran ke order tertentu. <strong>Pemasukan manual</strong> di Jurnal Kas ikut dihitung: produk Artikel/Buku dibagi sesuai margin, selain itu 100% siap dibagi.
    </p>
    <div class="table-responsive">
        <table class="table table-sm table-hover datatable dt-responsive nowrap" style="width:100%">
            <thead><tr><th>Tgl</th><th>Order</th><th>Judul</th><th>Jenis</th><th>Indeksasi</th><th class="text-end">Margin</th><th class="text-end">Masuk</th><th class="text-end">Cadangan APC</th><th class="text-end">Siap Dibagi</th></tr></thead>
            <tbody>
                @foreach($rows as $r)
                    <tr>
                        <td>{{ $r['tanggal'] }}</td>
                        <td>
                            {{ $r['code_order'] ?? '—' }}
                            @if($r['manual'] ?? false)<span class="badge bg-secondary" title="Pemasukan input manual di Jurnal Kas">manual</span>@endif
                        </td>
                        <td class="dt-judul">{{ $r['judul'] }}</td>
                        <td>{{ $r['type'] }}</td>
                        <td>
                            {{ $r['indexation'] ?? '—' }}
                            @if($r['unknownTier'])<span class="badge bg-warning text-dark" title="Belum ada indeksasi — dihitung dgn margin terendah">?</span>@endif
                        </td>
                        <td class="text-end">
                            {{ rtrim(rtrim(number_format($r['pct'], 2), '0'), ',.') }}%
                            @if($r['marginMissing'])<span class="badge bg-danger" title="Margin belum diatur di Asumsi">!</span>@endif
                        </td>
                        <td class="text-end">{{ $rp($r['base']) }}</td>
                        <td class="text-end text-warning">{{ $rp($r['reserve']) }}</td>
                        <td class="text-end text-success">{{ $rp($r['margin']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div></div></div></div>
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
<script src="{{ asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
<script src="{{ asset('assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js') }}"></script>
<script src="{{ asset('assets/libs/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js') }}"></script>
@endpush

@push('custom-scripts')
<script>$(function () { $('.datatable').DataTable({ pageLength: 25, responsive: true, order: [], language: { emptyTable: 'Belum ada pembayaran bulan ini.' } }); });</script>
@endpush
