@extends('layouts.master')
@section('title', 'Buat Invoice - SiMAPA')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header bg-transparent border-bottom">
                <h5 class="mb-0">Buat Invoice Baru</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('invoice.store') }}">
                    @csrf

                    <div class="mb-3">
                        <label class="form-label">Order <span class="text-danger">*</span></label>
                        <select name="order_id" class="form-select @error('order_id') is-invalid @enderror" required>
                            <option value="">-- Pilih Order --</option>
                            @foreach($orders as $order)
                                <option value="{{ $order->id }}" {{ old('order_id') == $order->id ? 'selected' : '' }}>
                                    {{ $order->code_order }} — {{ $order->details->title ?? '-' }}
                                </option>
                            @endforeach
                        </select>
                        @error('order_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Tipe Invoice <span class="text-danger">*</span></label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="type" value="proforma"
                                    id="typeProforma" {{ old('type','proforma') === 'proforma' ? 'checked' : '' }}>
                                <label class="form-check-label" for="typeProforma">Proforma</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="type" value="regular"
                                    id="typeRegular" {{ old('type') === 'regular' ? 'checked' : '' }}>
                                <label class="form-check-label" for="typeRegular">Regular</label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Payment Terkait <small class="text-muted">(opsional)</small></label>
                        <select name="payment_id" class="form-select">
                            <option value="">-- Tanpa Payment --</option>
                            @foreach($payments as $pay)
                                <option value="{{ $pay->id }}" {{ old('payment_id') == $pay->id ? 'selected' : '' }}>
                                    {{ $pay->order->code_order }} — Rp {{ number_format($pay->amount,0,',','.') }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Nomor Invoice <span class="text-danger">*</span></label>
                        <input type="text" name="invoice_no" class="form-control @error('invoice_no') is-invalid @enderror"
                            value="{{ old('invoice_no') }}" placeholder="PRF-2026-001" required>
                        @error('invoice_no') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Tanggal Terbit <span class="text-danger">*</span></label>
                            <input type="date" name="issued_at" class="form-control @error('issued_at') is-invalid @enderror"
                                value="{{ old('issued_at', now()->toDateString()) }}" required>
                            @error('issued_at') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Tanggal Jatuh Tempo <span class="text-danger">*</span></label>
                            <input type="date" name="due_at" class="form-control @error('due_at') is-invalid @enderror"
                                value="{{ old('due_at', now()->addDays(14)->toDateString()) }}" required>
                            @error('due_at') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Catatan</label>
                        <textarea name="note" class="form-control" rows="2">{{ old('note') }}</textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Simpan Invoice</button>
                        <a href="{{ route('invoice.index') }}" class="btn btn-outline-secondary">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
