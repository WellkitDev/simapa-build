@extends('layouts.master')
@section('title', 'Invoice Layanan - SiMAPA')

@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@php
    $workColors = ['belum' => 'secondary', 'proses' => 'warning', 'selesai' => 'success', 'batal' => 'danger'];
    $payColors  = ['belum' => 'secondary', 'dp' => 'info', 'lunas' => 'success'];
@endphp
<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card overflow-hidden">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-baseline mb-3">
                    <h6 class="card-title mb-0">Invoice Layanan</h6>
                    @can('service_invoice.create')
                        <a href="{{ route('service.invoice.create') }}" class="btn btn-sm btn-primary">+ Buat Invoice</a>
                    @endcan
                </div>

                <form method="GET" class="row g-2 mb-3">
                    <div class="col-md-2">
                        <select name="work_status" class="form-select form-select-sm">
                            <option value="">Semua Status Kerja</option>
                            @foreach(\App\Models\ServiceInvoice::WORK_STATUS as $key => $label)
                                <option value="{{ $key }}" {{ request('work_status') === $key ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="payment_status" class="form-select form-select-sm">
                            <option value="">Semua Status Bayar</option>
                            @foreach(\App\Models\ServiceInvoice::PAYMENT_STATUS as $key => $label)
                                <option value="{{ $key }}" {{ request('payment_status') === $key ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <input type="date" name="from" value="{{ request('from') }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-2">
                        <input type="date" name="to" value="{{ request('to') }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-sm btn-outline-secondary w-100">Filter</button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-centered datatable dt-responsive nowrap" style="width:100%;">
                        <thead>
                            <tr>
                                <th>No Invoice</th><th>Klien</th><th>Total</th><th>Sisa</th>
                                <th>Kerja</th><th>Bayar</th><th>Jatuh Tempo</th><th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($invoices as $inv)
                            <tr>
                                <td><strong>{{ $inv->invoice_no }}</strong></td>
                                <td>
                                    {{ $inv->client_name }}
                                    @if($inv->client_institution)
                                        <br><small class="text-muted">{{ $inv->client_institution }}</small>
                                    @endif
                                </td>
                                <td>Rp {{ number_format($inv->total, 0, ',', '.') }}</td>
                                <td>
                                    @if($inv->isOverpaid())
                                        <span class="text-info">Lebih Rp {{ number_format($inv->overpaidAmount(), 0, ',', '.') }}</span>
                                    @else
                                        Rp {{ number_format($inv->remaining, 0, ',', '.') }}
                                    @endif
                                </td>
                                <td><span class="badge bg-{{ $workColors[$inv->work_status] ?? 'secondary' }}">{{ $inv->workStatusLabel() }}</span></td>
                                <td><span class="badge bg-{{ $payColors[$inv->payment_status] ?? 'secondary' }}">{{ $inv->paymentStatusLabel() }}</span></td>
                                <td>
                                    <small class="{{ $inv->isOverdue() ? 'text-danger fw-bold' : '' }}">
                                        {{ $inv->due_at ? $inv->due_at->format('d/m/Y') : '-' }}
                                    </small>
                                </td>
                                <td><a href="{{ route('service.invoice.show', $inv->id) }}" class="btn btn-xs btn-primary">Detail</a></td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('plugin-scripts')
    <script src="{{ URL::asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js') }}"></script>
@endpush

@push('custom-scripts')
<script>
    $(function () {
        $(".datatable").DataTable({
            pageLength: 25, responsive: true,
            columnDefs: [{ orderable: false, targets: 7 }],
            language: { emptyTable: "Belum ada invoice layanan." }
        });
    });
</script>
@endpush
