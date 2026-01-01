@extends('layouts.master')
@section('title', 'Report Pending Order - SiMAPA')

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
                        <h6 class="card-title mb-0">Report Order Pending</h6>
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
                                        <th>No</th>
                                        <th>Code Order</th>
                                        <th>Total Harga</th>
                                        <th>Sudah Bayar</th>
                                        <th>Sisa Hutang</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($pendingOrders as $order)
                                        @php
                                            $cost = $order->details->cost_amount;
                                            $paid = $order->total_paid ?? 0;
                                            $debt = $cost - $paid;
                                        @endphp
                                        <tr>
                                            <td>{{ $loop->iteration }}</td>
                                            <td>{{ $order->code_order }}</td>
                                            <td>Rp {{ number_format($cost, 0, ',', '.') }}</td>
                                            <td class="text-success">Rp
                                                {{ number_format($paid ?? 0, 0, ',', '.') }}</td>
                                            <td class="text-danger">Rp
                                                {{ number_format($debt, 0, ',', '.') }}
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
