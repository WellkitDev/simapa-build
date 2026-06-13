@extends('layouts.master')
@section('title', 'Edit Invoice - SiMAPA')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header bg-transparent border-bottom">
                <h5 class="mb-0">Edit Invoice — {{ $invoice->invoice_no }}</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('invoice.update', $invoice->id) }}">
                    @csrf @method('PUT')

                    <div class="mb-3">
                        <label class="form-label">Order</label>
                        <p class="form-control-plaintext">{{ $invoice->order->code_order }}</p>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Nomor Invoice <span class="text-danger">*</span></label>
                        <input type="text" name="invoice_no" class="form-control @error('invoice_no') is-invalid @enderror"
                            value="{{ old('invoice_no', $invoice->invoice_no) }}" required>
                        @error('invoice_no') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Payment Terkait <small class="text-muted">(opsional)</small></label>
                        <select name="payment_id" class="form-select">
                            <option value="">-- Tanpa Payment --</option>
                            @foreach($payments as $pay)
                                <option value="{{ $pay->id }}"
                                    {{ old('payment_id', $invoice->payment_id) == $pay->id ? 'selected' : '' }}>
                                    {{ $pay->order->code_order }} — Rp {{ number_format($pay->amount,0,',','.') }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Tanggal Terbit <span class="text-danger">*</span></label>
                            <input type="date" name="issued_at" class="form-control"
                                value="{{ old('issued_at', $invoice->issued_at?->toDateString()) }}" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Tanggal Jatuh Tempo <span class="text-danger">*</span></label>
                            <input type="date" name="due_at" class="form-control"
                                value="{{ old('due_at', $invoice->due_at?->toDateString()) }}" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Catatan</label>
                        <textarea name="note" class="form-control" rows="2">{{ old('note', $invoice->note) }}</textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                        <a href="{{ route('invoice.show', $invoice->id) }}" class="btn btn-outline-secondary">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
