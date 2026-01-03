@extends('layouts.master')
@section('title', 'Payment Order Book - SiMAPA')

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
                        <h6 class="card-title mb-0">Management Order Books</h6>
                        @role(['marketing'])
                            <div class="btn-group" role="group">
                                <a href="#" class="btn btn-primary">Trash</a>
                                <a href="#" class="btn btn-outline-primary">Export</a>
                                <a href="#" class="btn btn-primary">Create</a>
                            </div>
                        @endrole
                    </div>

                    <div class="row mt-4">
                        <div class="table-responsive">
                            <table class="table table-centered datatable dt-responsive nowrap"
                                style="border-collapse: collapse; border-spacing: 0; width: 100%;">
                                <thead>
                                    <tr>

                                        <th>INV</th>
                                        <th>Type</th>
                                        <th>Amount</th>
                                        <th>Proof</th>
                                        <th>Payment Status</th>
                                        <th>User</th>
                                        <th>Approval Status</th>
                                        @role(['superadmin', 'manager'])
                                            <th>Action</th>
                                        @endrole
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($payments as $payment)
                                        <tr>

                                            <td>{{ $payment->invoice->invoice_no }}</td>
                                            <td><span
                                                    class="badge bg-secondary">{{ strtoupper($payment->payment_type) }}</span>
                                            </td>
                                            <td>Rp {{ number_format($payment->amount, 0, ',', '.') }}</td>
                                            <td>
                                                @if ($payment->proof_url)
                                                    <a href="{{ $payment->proof_url }}" target="_blank"
                                                        class="btn btn-icon btn-outline-secondary">
                                                        <i class="" data-feather="eye"></i>
                                                    </a>
                                                @else
                                                    <span class="text-muted">No Proof</span>
                                                @endif
                                            </td>
                                            <td>
                                                <span
                                                    class="badge {{ $payment->status == 'paid' ? 'bg-success' : 'bg-warning' }}">
                                                    {{ ucfirst($payment->status) }}
                                                </span>
                                            </td>
                                            <td>
                                                {{ $payment->order->user->name }}
                                            </td>
                                            <td>
                                                @if ($payment->approval)
                                                    @php
                                                        $badgeColor =
                                                            [
                                                                'pending' => 'bg-warning',
                                                                'approved' => 'bg-success',
                                                                'rejected' => 'bg-danger',
                                                            ][$payment->approval->status] ?? 'bg-secondary';
                                                    @endphp
                                                    <span class="badge {{ $badgeColor }}">
                                                        {{ ucfirst($payment->approval->status) }}
                                                    </span>
                                                @else
                                                    <span class="badge bg-dark">No Approval Data</span>
                                                @endif
                                            </td>
                                            @role(['superadmin', 'manager'])
                                                <td>
                                                    @if ($payment->approval && $payment->approval->status == 'pending')
                                                        <div class="btn-group">
                                                            <form action="{{ route('payment.approve', $payment->id) }}"
                                                                method="POST">
                                                                @csrf
                                                                <button type="submit"
                                                                    class="btn btn-icon btn-xs btn-primary me-2"><i
                                                                        class="" data-feather="check"></i></button>
                                                            </form>
                                                            <form action="{{ route('payment.reject', $payment->id) }}"
                                                                method="POST">
                                                                @csrf
                                                                <button type="submit" class="btn btn-icon btn-xs btn-danger"><i
                                                                        class="" data-feather="x"></i></button>
                                                            </form>
                                                        </div>
                                                    @elseif($payment->approval && $payment->approval->status == 'approved')
                                                        <span class="text-success small">
                                                            Completed</span>
                                                    @else
                                                        ---
                                                    @endif
                                                </td>
                                            @endrole
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
