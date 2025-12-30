@extends('layouts.master')
@section('title', 'Payment Order Full - SiMAPA')

@push('plugin-styles')
    <link href="{{ asset('assets/plugins/select2/select2.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}"
        rel="stylesheet" />
    <style>
        .bg-light-success {
            background-color: #e8f5e9;
        }

        .bg-light-warning {
            background-color: #fffde7;
        }

        .bg-light-secondary {
            background-color: #f5f5f5;
        }
    </style>
@endpush

@section('content')
    <div class="row">
        <div class="col-12 col-xl-12 grid-margin stretch-card">
            <div class="card overflow-hidden">
                <div class="card-body">

                    <div class="d-flex justify-content-between align-items-baseline mb-md-4">
                        <h6 class="card-title mb-0">Payment Order Full</h6>
                        <div class="btn-group" role="group">
                            <a href="#" class="btn btn-primary">Trash</a>
                            <a href="#" class="btn btn-outline-primary">Export</a>
                            <a href="#" class="btn btn-primary">Create</a>
                        </div>
                    </div>

                    <div class="row mt-4">
                        <div class="table-responsive">
                            <table class="table table-centered datatable dt-responsive nowrap"
                                style="border-collapse: collapse; border-spacing: 0; width: 100%;">
                                <thead>
                                    <tr>
                                        <th>Invoice & Order</th>
                                        <th>Pelanggan</th>
                                        <th>Total Bayar</th>
                                        <th>Status Approval</th>
                                        <th>Update Terakhir</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($orders as $order)
                                        @php
                                            $latestPayment = $order->payments->last();
                                            $approval = $latestPayment->approval ?? null;
                                        @endphp
                                        <tr>
                                            <td>
                                                <strong>{{ $order->invoices->first()->invoice_no ?? 'N/A' }}</strong><br>
                                                <small class="text-muted">{{ $order->code_order }}</small>
                                            </td>
                                            <td>
                                                {{ $order->contact->cp_name }}<br>
                                                <small class="text-muted">{{ $order->contact->cp_email }}</small>
                                            </td>
                                            <td>
                                                <span class="fw-bold">Rp
                                                    {{ number_format($order->payments->where('status', 'paid')->sum('amount'), 0, ',', '.') }}</span><br>
                                                <small class="text-success text-uppercase"
                                                    style="font-size: 10px;">Lunas</small>
                                            </td>
                                            <td>
                                                @if ($approval && $approval->status == 'approved')
                                                    <span
                                                        class="badge bg-light-success text-success border border-success px-3">
                                                        <i class="fa fa-user-check me-1"></i> Terverifikasi
                                                    </span>
                                                @elseif($approval && $approval->status == 'pending')
                                                    <span
                                                        class="badge bg-light-warning text-warning border border-warning px-3">
                                                        <i class="fa fa-spinner fa-spin me-1"></i> Menunggu Review
                                                    </span>
                                                @else
                                                    <span class="badge bg-light-secondary text-secondary px-3">Tanpa
                                                        Approval</span>
                                                @endif
                                            </td>
                                            <td>
                                                <small>{{ $latestPayment->updated_at->format('d M Y H:i') }}</small>
                                            </td>
                                            <td class="text-center">
                                                <div class="btn-group">
                                                    <a href="{{ route('order.book.show', $order->code_order) }}"
                                                        class="btn btn-sm btn-info text-white" title="Lihat Detail">
                                                        <i class="fa fa-eye"></i>
                                                    </a>
                                                    @if ($order->invoices->first() && $order->invoices->first()->pdf_drive_id)
                                                        <a href="{{ $order->invoices->first()->pdf_drive_id }}"
                                                            target="_blank" class="btn btn-sm btn-outline-danger"
                                                            title="Unduh Invoice PDF">
                                                            <i class="fa fa-file-pdf"></i>
                                                        </a>
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="text-center py-5 text-muted">Belum ada pesanan yang
                                                lunas.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
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
    <script src="{{ asset('assets/plugins/select2/select2.min.js') }}"></script>
@endpush

@push('custom-scripts')
    <script>
        $(function() {
            $(".datatable").DataTable({
                pageLength: 10,
                order: [
                    [1, "asc"]
                ],
                // language: {
                //     search: "Cari:",
                //     lengthMenu: "Tampilkan _MENU_"
                // }
            });
            $(".dataTables_length select, .dataTables_filter input").addClass("form-control mb-2");
            $('.custom-select').select2();
        });
    </script>
@endpush
