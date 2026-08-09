@extends('layouts.master')
@section('title', 'Meja Kerja Saya - SiMAPA')
@section('content')

<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <h4 class="mb-1">Meja Kerja Saya</h4>
        <p class="text-muted mb-0 small">
            Tugas aktif milikmu · urutan otomatis: terlambat → deadline terdekat → prioritas
        </p>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card h-100"><div class="card-body py-3">
            <div class="text-muted small">Tugas Aktif</div>
            <div class="h3 fw-bold mb-0">{{ $stat['aktif'] }}</div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100"><div class="card-body py-3">
            <div class="text-muted small">Terlambat</div>
            <div class="h3 fw-bold mb-0 text-danger">{{ $stat['terlambat'] }}</div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100"><div class="card-body py-3">
            <div class="text-muted small">Deadline Minggu Ini</div>
            <div class="h3 fw-bold mb-0">{{ $stat['minggu'] }}</div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100"><div class="card-body py-3">
            <div class="text-muted small">Selesai Bulan Ini</div>
            <div class="h3 fw-bold mb-0">{{ $stat['selesai'] }}</div>
        </div></div>
    </div>
</div>

<h6 class="text-uppercase text-muted small fw-bold mb-2">Tugas Saya ({{ $tugas->count() }})</h6>

@forelse ($tugas as $baris)
    @include('naskah.partials.tugas-baris', [
        'jenis'     => $baris['jenis'],
        'model'     => $baris['model'],
        'terlambat' => $baris['terlambat'],
        'deadline'  => $baris['deadline'],
        'izin'      => $izin,
        'akuId'     => $akuId,
        'antrian'   => false,
    ])
@empty
    <div class="card mb-3"><div class="card-body text-muted small">
        Belum ada tugas aktif untukmu.
    </div></div>
@endforelse

@if ($izin['claim'])
    <h6 class="text-uppercase text-muted small fw-bold mt-4 mb-2">
        Antrian Belum Ditugaskan — bisa diambil
    </h6>
    @forelse ($antrian as $baris)
        @include('naskah.partials.tugas-baris', [
            'jenis'     => $baris['jenis'],
            'model'     => $baris['model'],
            'terlambat' => false,
            'deadline'  => null,
            'izin'      => $izin,
            'akuId'     => $akuId,
            'antrian'   => true,
        ])
    @empty
        <div class="card"><div class="card-body text-muted small">
            Tidak ada naskah yang menunggu pelaksana.
        </div></div>
    @endforelse
@endif

@endsection
