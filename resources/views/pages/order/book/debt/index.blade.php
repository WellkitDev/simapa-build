@extends('layouts.master')
<!-- Title pages active -->
@section('title', 'Debit Payment Order Book - SiMAPA')

@push('plugin-styles')
    <!-- Plugin css import here -->
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
                        <h6 class="card-title mb-0">Debit Payment Books</h6>
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
                                        <th>Kode Order</th>
                                        <th>Book Title</th>
                                        <th>INV</th>
                                        <th>Type</th>
                                        <th>Authors</th>
                                        <th>Chapters</th>
                                        <th>Total Service</th>
                                        <th>Total Payment</th>
                                        <th>Marketing</th>
                                        <th>Payment Status</th>
                                        <th>Approval Status</th>
                                        {{-- <th>Approval Action</th> --}}
                                        {{-- <th>Actions</th> --}}
                                    </tr>
                                </thead>
                                <tbody>

                                    <!-- ROW SAMPLE -->
                                    @forelse ($orders as $order)
                                        <tr>
                                            <td>
                                                {{ $order->code_order }}
                                            </td>
                                            <!-- Judul Buku -->
                                            <td>
                                                <a href="" class="text-decoration-none">
                                                    <strong>{{ Str::limit($order->title, 50) }}</strong>
                                                </a>
                                            </td>

                                            <!-- INV -->
                                            <td>
                                                @foreach ($order->invoices as $inv)
                                                    <small class="text-muted">{{ $inv->inv_no }}</small>
                                                @endforeach
                                            </td>
                                            <!-- Type -->
                                            <td>
                                                <span class="badge bg-primary">
                                                    {{ $order->type == 'bk_mandiri' ? 'Mandiri' : 'Kolaborasi' }}
                                                </span>
                                                <br>
                                                <small>Naskah {{ ucfirst($order->naskah_type) }}</small>
                                            </td>
                                            <!-- Authors -->
                                            <td>
                                                @foreach ($order->authors->sortBy('pivot.possition')->take(3) as $author)
                                                    {{ $author->name }}<br>
                                                @endforeach
                                                @if ($order->count_authors > 3)
                                                    <small class="text-muted">+{{ $order->count_authors - 3 }}
                                                        lainnya</small>
                                                @endif
                                            </td>
                                            <!-- Chapters -->
                                            <td class="text-center">
                                                {{ $order->chapters ?? '-' }}
                                            </td>
                                            <!-- Total Payment (Biaya) -->
                                            <td>
                                                Rp {{ number_format($order->cost_amount, 0, ',', '.') }}
                                            </td>
                                            <!-- Total Payment (pay) -->
                                            <td>
                                                Rp {{ number_format($order->pay_amount, 0, ',', '.') }}
                                            </td>
                                            <!-- Marketing -->
                                            <td>
                                                {{ $order->users->username }}
                                            </td>
                                            <!-- Payment Status -->
                                            <td>
                                                @if ($order->debit_amount <= 0)
                                                    <span class="badge bg-success">LUNAS</span>
                                                @elseif($order->pay_amount > 0)
                                                    <span class="badge bg-warning">DP</span>
                                                @else
                                                    <span class="badge bg-danger">BELUM BAYAR</span>
                                                @endif
                                            </td>
                                            <!-- Approval Status -->
                                            <td>
                                                <span
                                                    class="badge
                                            {{ $order->status == 'completed' ? 'bg-success' : ($order->status == 'pending' ? 'bg-warning' : 'bg-info') }}">
                                                    {{ ucwords(str_replace('_', ' ', $order->status)) }}
                                                </span>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="10" class="text-center text-muted py-4">
                                                Belum ada order buku.
                                            </td>
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
    <!-- Plugin js import here -->
    <!-- Plugin js import here -->
    <script src="{{ URL::asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js') }}"></script>
    <script src="{{ asset('assets/plugins/select2/select2.min.js') }}"></script>
@endpush

@push('custom-scripts')
    <!-- Custom js here -->
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
