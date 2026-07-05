@extends('layouts.master')
@section('title', 'Refund Invoice - SiMAPA')

@section('content')
@php $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp
<div class="row"><div class="col-lg-8">
<div class="card"><div class="card-body">
    <h5 class="mb-3">Proses Refund — Invoice {{ $invoice->invoice_no }}</h5>
    <div class="mb-3 text-muted small">
        Order: {{ optional($invoice->order)->code_order }} ·
        Customer: {{ optional(optional($invoice->order)->contact)->cp_name ?? '-' }} ·
        <strong>Total sudah dibayar: {{ $rp($paidIn) }}</strong>
    </div>
    <form method="POST" action="{{ route('invoice.refund', $invoice->id) }}" onsubmit="return confirm('Proses refund ini? Dana akan tercatat sebagai pengeluaran.')">
        @csrf
        <div class="mb-3">
            <label class="form-label">Nominal Refund (Rp)</label>
            <input type="number" name="amount" value="{{ old('amount', $paidIn) }}" min="1" max="{{ $paidIn }}" class="form-control @error('amount') is-invalid @enderror" required>
            @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <small class="text-muted">Maksimal {{ $rp($paidIn) }} (total yang sudah dibayar).</small>
        </div>
        <div class="mb-3">
            <label class="form-label">Metode</label>
            <select name="method" class="form-select" required>
                <option value="transfer">Transfer Bank</option>
                <option value="tunai">Tunai</option>
                <option value="lainnya">Lainnya</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Rekening / Tujuan (opsional)</label>
            <input type="text" name="account" value="{{ old('account') }}" class="form-control" placeholder="mis. BCA 1234567890 a.n. ...">
        </div>
        <div class="mb-3">
            <label class="form-label">Tanggal</label>
            <input type="date" name="tanggal" value="{{ old('tanggal', now()->format('Y-m-d')) }}" class="form-control" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Alasan Refund</label>
            <textarea name="reason" rows="3" class="form-control @error('reason') is-invalid @enderror" required>{{ old('reason') }}</textarea>
            @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <button class="btn btn-warning">Proses Refund</button>
        <a href="{{ route('invoice.show', $invoice->id) }}" class="btn btn-outline-secondary">Batal</a>
    </form>
</div></div>
</div></div>
@endsection
