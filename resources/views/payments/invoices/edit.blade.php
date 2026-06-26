@extends('layouts.master')
@section('title', 'Edit Invoice - SiMAPA')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h5 class="mb-0">Edit Invoice — {{ $invoice->invoice_no }}</h5>
        </div>
        <div>
            <a href="{{ route('invoice.show', $invoice->id) }}" class="btn btn-sm btn-outline-secondary">Kembali</a>
        </div>
    </div>

    <div class="row">
        <div class="col-md-8 col-12 grid-margin stretch-card">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-title">Informasi Invoice</h6>

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

                        @if($invoice->payment)
                            <hr>
                            <h6 class="card-title">Pembayaran Terkait</h6>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Total Biaya</label>
                                    <input type="text" class="form-control" disabled value="Rp {{ number_format($totalCost, 0, ',', '.') }}">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Sudah Terbayar</label>
                                    <input type="text" class="form-control" disabled value="Rp {{ number_format($alreadyPaid, 0, ',', '.') }}">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Sisa</label>
                                    <input type="text" class="form-control" disabled value="Rp {{ number_format($remainingBalance, 0, ',', '.') }}">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Total Pembayaran (Rp) <span class="text-danger">*</span></label>
                                    <input type="number" name="amount" class="form-control @error('amount') is-invalid @enderror"
                                        min="1" step="1000" value="{{ old('amount', $invoice->payment->amount) }}" required>
                                    @error('amount') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Status Pembayaran <span class="text-danger">*</span></label>
                                    <select name="payment_type" class="form-select" required>
                                        @foreach(['dp' => 'DP', 'lunas' => 'Lunas', 'pelunasan' => 'Pelunasan'] as $val => $lbl)
                                            <option value="{{ $val }}" {{ old('payment_type', $invoice->payment->payment_type) === $val ? 'selected' : '' }}>{{ $lbl }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <small class="text-muted d-block mb-2">Mengubah nominal/jenis akan menghitung ulang status order &amp; tercatat di log invoice.</small>
                        @else
                            <hr>
                            <p class="text-muted">Belum ada pembayaran terkait untuk invoice ini.</p>
                        @endif

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
