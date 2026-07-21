@extends('layouts.master')
@section('title', 'Detail Slip Gaji - SiMAPA')

@section('content')
@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp

<div class="row"><div class="col-lg-8">
<div class="card"><div class="card-body">
    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
        <div>
            <h5 class="mb-1">Slip Gaji — {{ $slip->periodLabel() }}</h5>
            <div class="text-muted small">No. Slip: {{ $slip->slip_no }} ·
                <span class="badge {{ $slip->status === 'terbit' ? 'bg-success' : 'bg-secondary' }}">
                    {{ \App\Models\SalarySlip::STATUS[$slip->status] ?? $slip->status }}
                </span>
            </div>
        </div>
        <div class="text-nowrap">
            @can('salary.export')
                <a href="{{ route('salary.slip.pdf', $slip->id) }}" target="_blank" class="btn btn-sm btn-outline-dark">PDF</a>
            @endcan
            @can('salary.send')
                <form method="POST" action="{{ route('salary.slip.send', $slip->id) }}" class="d-inline" data-confirm="Kirim slip ke email karyawan?">
                    @csrf @idempotent
                    <button class="btn btn-sm btn-outline-info">Kirim Email</button>
                </form>
            @endcan
            @can('salary.edit')
                @if ($slip->isDraft())
                    <a href="{{ route('salary.slip.edit', $slip->id) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                @endif
            @endcan
        </div>
    </div>

    <table class="table table-sm">
        <tr><th style="width:180px">Karyawan</th><td>{{ $slip->employee_name }}</td></tr>
        <tr><th>Jabatan</th><td>{{ $slip->employee_position ?? '-' }}</td></tr>
    </table>

    <h6 class="mt-3">Rincian Penghasilan</h6>
    <table class="table table-sm">
        @foreach ($slip->earnings as $e)
            <tr><td>{{ $e->label }}</td><td class="text-end">{{ $rp($e->amount) }}</td></tr>
        @endforeach
        <tr class="fw-bold"><td>Subtotal</td><td class="text-end">{{ $rp($slip->total_earnings) }}</td></tr>
    </table>

    <h6 class="mt-3">Rincian Potongan</h6>
    <table class="table table-sm">
        @forelse ($slip->deductions as $d)
            <tr><td>{{ $d->label }}</td><td class="text-end">{{ $rp($d->amount) }}</td></tr>
        @empty
            <tr><td class="text-muted" colspan="2">Tidak ada.</td></tr>
        @endforelse
        <tr class="fw-bold"><td>Subtotal</td><td class="text-end">{{ $rp($slip->total_deductions) }}</td></tr>
    </table>

    <div class="alert alert-primary d-flex justify-content-between align-items-center mt-3">
        <span class="fw-bold">GAJI BERSIH / TAKE HOME PAY</span>
        <span class="fw-bold fs-5">{{ $rp($slip->net_pay) }}</span>
    </div>

    @if($slip->note)<div class="text-muted small">Catatan: {{ $slip->note }}</div>@endif

    <a href="{{ route('salary.slip.index') }}" class="btn btn-outline-secondary mt-2">&larr; Kembali</a>
</div></div>
</div></div>
@endsection
