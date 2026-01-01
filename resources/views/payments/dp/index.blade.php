@extends('layouts.master')
@section('title', 'Payment Order Dp - SiMAPA')

@push('plugin-styles')
    <link href="{{ asset('assets/plugins/select2/select2.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}"
        rel="stylesheet" />
@endpush

@section('content')
    <div class="row">
        <div class="col-12 col-xl-12 grid-margin stretch-card">
            <div class="card overflow-hidden">
                <div class="card-body">

                    <div class="d-flex justify-content-between align-items-baseline mb-md-4">
                        <h6 class="card-title mb-0">Payment Order DP</h6>
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
                                        <th>No. Invoice</th>
                                        <th>Pelanggan</th>
                                        <th>Judul Buku</th>
                                        <th>Total Biaya</th>
                                        <th>Telah Dibayar (DP)</th>
                                        <th>Sisa Tagihan</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($orders as $order)
                                        @php
                                            $detail = $order->details;
                                            $totalCost = $detail->cost_amount ?? 0;
                                            $alreadyPaid = $order->payments->where('status', 'paid')->sum('amount');
                                            $remaining = $totalCost - $alreadyPaid;
                                        @endphp
                                        <tr>
                                            <td>
                                                @foreach ($order->invoices as $inv)
                                                    <span class="fw-bold">{{ $inv->invoice_no ?? '-' }}</span><br>
                                                    <small class="text-muted">{{ $order->code_order }}</small>
                                                @endforeach

                                            </td>
                                            <td>
                                                {{ $order->contact->cp_name }}<br>
                                                <small class="text-muted">{{ $order->contact->cp_email }}</small>
                                            </td>
                                            <td style="max-width: 250px;" class="text-truncate">
                                                {{ Str::title(Str::limit($detail->title, 50)) ?? 'N/A' }}
                                            </td>
                                            <td>Rp {{ number_format($totalCost, 0, ',', '.') }}</td>
                                            <td class="text-success fw-bold">
                                                Rp {{ number_format($alreadyPaid, 0, ',', '.') }}
                                            </td>
                                            <td class="text-danger fw-bold">
                                                Rp {{ number_format($remaining, 0, ',', '.') }}
                                            </td>
                                            <td>
                                                <div class="btn-group">
                                                    <a href="{{ route('payment.create', $order->code_order) }}"
                                                        class="btn btn-sm btn-outline-secondary" title="Detail">
                                                        <i class="fa fa-eye"></i>
                                                    </a>
                                                    <a href="{{ route('order.book.show', $order->code_order) }}"
                                                        class="btn btn-sm btn-outline-primary" title="Detail">
                                                        <i class="fa fa-eye"></i>
                                                    </a>
                                                    <a href="https://wa.me/{{ $order->contact->cp_phone }}?text=Halo {{ $order->contact->cp_name }}, kami dari Avidpedia ingin mengingatkan tagihan pelunasan untuk buku {{ $detail->title }} sebesar Rp {{ number_format($remaining, 0, ',', '.') }}"
                                                        target="_blank" class="btn btn-sm btn-outline-success"
                                                        title="Kirim WA">
                                                        <i class="fab fa-whatsapp"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
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
