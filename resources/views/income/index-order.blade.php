@extends('layouts.master')
@section('title', 'Report Income Order - SiMAPA')

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
                        <h6 class="card-title mb-0">Report Order Yearly</h6>
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
                                        <th>Tahun</th>
                                        <th>Total Order</th>
                                        <th>Total Income</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($yearlyIncome as $row)
                                        <tr>
                                            <td>{{ $loop->iteration }}</td>
                                            <td><span class="badge bg-primary">{{ $row->year }}</span></td>
                                            <td>{{ $row->total_order }} Order</td>
                                            <td>Rp {{ number_format($row->total_income, 0, ',', '.') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-xl-12 grid-margin stretch-card">
            <div class="card overflow-hidden">
                <div class="card-body">

                    <div class="d-flex justify-content-between align-items-baseline mb-md-4">
                        <h6 class="card-title mb-0">Report Order Monthly</h6>
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
                                        <th>Bulan</th>
                                        <th>Total Order</th>
                                        <th>Total Income</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($monthlyIncome as $row)
                                        <tr>
                                            <td>{{ $loop->iteration }}</td>
                                            <td>
                                                {{ \Carbon\Carbon::create()->month($row->month)->translatedFormat('F') }}
                                                <strong>{{ $row->year }}</strong>
                                            </td>
                                            <td>{{ $row->total_order }}</td>
                                            <td class="text-success fw-bold">Rp
                                                {{ number_format($row->total_income, 0, ',', '.') }}</td>
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
