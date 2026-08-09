@extends('layouts.master')
@section('title', 'Pelacakan Naskah - SiMAPA')

@push('plugin-styles')
    <link href="{{ asset('assets/plugins/datatables.net-bs4/dataTables.bootstrap4.css') }}" rel="stylesheet" />
    <link href="{{ asset('assets/plugins/datatables.net-responsive-bs4/responsive.bootstrap4.css') }}" rel="stylesheet" />
@endpush

@section('content')
@php
    $totalKartu = $kartu->flatten(1)->count();
@endphp

<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <h4 class="mb-1">Pelacakan Naskah</h4>
        <p class="text-muted mb-0 small">
            {{ $totalKartu }} judul aktif · naskah selesai pindah ke Arsip (tidak pernah hilang)
        </p>
    </div>
</div>

{{-- Toolbar: tab jenis · filter PJ/Pelaksana/prioritas/cari · pilihan tampilan --}}
<form method="GET" class="card mb-3"><div class="card-body py-3">
    <div class="row g-2 align-items-end">
        <div class="col-auto">
            <div class="btn-group btn-group-sm" role="group">
                <a href="{{ route('naskah.pelacakan', ['tipe' => 'artikel', 'view' => $tampil]) }}"
                   class="btn {{ $tipe === 'artikel' ? 'btn-primary' : 'btn-outline-secondary' }}">Artikel</a>
                <a href="{{ route('naskah.pelacakan', ['tipe' => 'buku', 'view' => $tampil]) }}"
                   class="btn {{ $tipe === 'buku' ? 'btn-primary' : 'btn-outline-secondary' }}">Buku</a>
            </div>
        </div>
        <input type="hidden" name="tipe" value="{{ $tipe }}">
        <input type="hidden" name="view" value="{{ $tampil }}">

        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">PJ</label>
            <select name="pj" class="form-select form-select-sm">
                <option value="">Semua</option>
                @foreach ($orang['pj'] as $u)
                    <option value="{{ $u->id }}" @selected(($filter['pj'] ?? null) == $u->id)>{{ $u->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">Pelaksana</label>
            <select name="pelaksana" class="form-select form-select-sm">
                <option value="">Semua</option>
                @foreach ($orang['pelaksana'] as $u)
                    <option value="{{ $u->id }}" @selected(($filter['pelaksana'] ?? null) == $u->id)>{{ $u->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">Prioritas</label>
            <select name="prioritas" class="form-select form-select-sm">
                <option value="">Semua</option>
                @foreach (['high' => 'High', 'normal' => 'Normal', 'low' => 'Low'] as $k => $v)
                    <option value="{{ $k }}" @selected(($filter['prioritas'] ?? null) === $k)>{{ $v }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">Cari</label>
            <input type="search" name="cari" value="{{ $filter['cari'] ?? '' }}"
                   class="form-control form-control-sm" placeholder="Kode order / judul…">
        </div>
        <div class="col-auto">
            <button class="btn btn-sm btn-primary">Terapkan</button>
        </div>
        <div class="col-auto ms-md-auto">
            <div class="btn-group btn-group-sm" role="group">
                @foreach (['papan' => 'Papan', 'daftar' => 'Daftar', 'riwayat' => 'Riwayat'] as $k => $v)
                    <a href="{{ route('naskah.pelacakan', array_merge($filter, ['tipe' => $tipe, 'view' => $k])) }}"
                       class="btn {{ $tampil === $k ? 'btn-primary' : 'btn-outline-secondary' }}">{{ $v }}</a>
                @endforeach
            </div>
        </div>
    </div>
</div></form>

@if ($tampil === 'papan')
    @include('naskah.partials.papan', ['zona' => $zona, 'kartu' => $kartu])
    <div class="alert alert-info small mt-3 mb-0">
        Klik kartu untuk membuka <strong>Detail Naskah</strong> — semua aksi ada di sana. Papan ini hanya-baca.
    </div>
@elseif ($tampil === 'daftar')
    @include('naskah.partials.daftar', ['kartu' => $kartu])
@else
    @include('naskah.partials.riwayat-tabel', ['riwayat' => $riwayat])
@endif

@endsection

@push('plugin-scripts')
    <script src="{{ asset('assets/plugins/datatables.net/jquery.dataTables.js') }}"></script>
    <script src="{{ asset('assets/plugins/datatables.net-bs4/dataTables.bootstrap4.js') }}"></script>
    <script src="{{ asset('assets/plugins/datatables.net-responsive/dataTables.responsive.min.js') }}"></script>
    <script src="{{ asset('assets/plugins/datatables.net-responsive-bs4/responsive.bootstrap4.min.js') }}"></script>
@endpush

@push('custom-scripts')
<script>
    $(function () {
        $('.datatable').DataTable({
            responsive: true,
            columnDefs: [{ orderable: false, targets: 0 }],
            language: { search: 'Cari:', lengthMenu: 'Tampil _MENU_ baris', zeroRecords: 'Tidak ada data' },
        }).on('order.dt search.dt draw.dt', function () {
            $(this).DataTable().column(0, { search: 'applied', order: 'applied' })
                .nodes().each(function (cell, i) { cell.innerHTML = i + 1; });
        }).draw();
    });
</script>
@endpush
