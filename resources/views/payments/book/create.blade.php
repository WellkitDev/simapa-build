@extends('layouts.master')
@section('title', 'Buat Pembayaran - SiMAPA')

@push('plugin-styles')
@endpush

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h5 class="mb-0">Payment Order {{ $order->code_order }}</h5>
        </div>
        <div>
            <a href="" class="btn btn-sm btn-outline-secondary">Kembali</a>
        </div>
    </div>

    <form method="POST" action="{{ route('payment.store', $order->code_order) }}" enctype="multipart/form-data">
        @csrf
        <!-- Section: Informasi Payment -->
        <div class="row">
            <div class="col-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-title">Informasi Payment</h6>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Paid Date <span class="text-danger"></span></label>
                                <input type="datetime-local" class="form-control" name="issued_at" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Due Date <span class="text-danger">*</span></label>
                                <input type="datetime-local" class="form-control" name="dued_at" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 sm-12 mb-3">
                                <label class="form-label">Total Biaya (Rp) <span class="text-danger">*</span></label>
                                <input type="text" name="cost_amount" class="form-control" disabled
                                    value="Rp. {{ number_format($totalCost, 0, ',', '.') }}">
                            </div>
                            <div class="col-md-6 sm-12 mb-3">
                                <label class="form-label">
                                    {{ $alreadyPaid > 0 ? 'Sisa Tagihan' : 'Tagihan' }}
                                </label>
                                <input type="text" name="cost_amount" class="form-control" disabled
                                    value="Rp. {{ number_format($remainingBalance, 0, ',', '.') }}">
                                @if ($alreadyPaid > 0)
                                    <span class="text-success">Sudah terbayar: Rp
                                        {{ number_format($alreadyPaid, 0, ',', '.') }}</span>
                                @endif
                            </div>
                            <div class="col-md-6 sm-12 mb-3">
                                <label class="form-label">Total Pembayaran (Rp) <span class="text-danger">*</span></label>
                                <input type="number" name="pay_amount" class="form-control" required min="1000"
                                    max="{{ $remainingBalance }}" value="{{ $remainingBalance }}" step="1000">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 sm-12 mb-3">
                                <label class="form-label">Status Pembayaran</label>
                                <select name="status" class="form-select" required>
                                    <option value="dp">Down Payment/DP</option>
                                    <option value="lunas">Full Payment/Lunas</option>
                                    <option value="pelunasan">Paydown/Pelunasan</option>
                                </select>
                            </div>
                            <div class="col-md-6 sm-12 mb-3">
                                <label class="form-label">Struk Pembayaran</label>
                                <input type="file" class="form-control" name="proof_url" accept="image/*,.pdf" required>
                            </div>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="send_invoice_email" value="1"
                                id="sendInvoice">
                            <label class="form-check-label" for="sendInvoice">
                                Kirim invoice otomatis via email ke author utama setelah order disimpan
                            </label>
                        </div>

                        <div class="text-start">
                            <button type="submit" class="btn btn-sm btn-primary">Simpan Order</button>
                            <a href="" class="btn btn-sm btn-outline-secondary ms-2">Batal</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
@endsection

@push('plugin-scripts')
@endpush

@push('custom-scripts')
@endpush
