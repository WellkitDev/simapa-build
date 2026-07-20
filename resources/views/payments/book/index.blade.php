@extends('layouts.master')
@section('title', 'Persetujuan Pembayaran - SiMAPA')

@push('plugin-styles')
    <link href="{{ asset('assets/plugins/select2/select2.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}"
        rel="stylesheet" />
@endpush

@section('content')
    @php
        $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
        $bukti = function ($p) {
            return $p->proof_url;
        };
    @endphp

    {{-- ============ PERLU DISETUJUI (pending) ============ --}}
    <div class="row">
        <div class="col-12 grid-margin stretch-card">
            <div class="card overflow-hidden">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-baseline mb-md-4">
                        <h6 class="card-title mb-0">Pembayaran Perlu Disetujui
                            <span class="badge bg-warning text-dark ms-1">{{ $pending->count() }}</span>
                        </h6>
                    </div>
                    <div class="table-responsive mt-3">
                        <table class="table table-centered datatable dt-responsive nowrap" style="width:100%">
                            <thead>
                                <tr>
                                    <th>No. Invoice</th>
                                    <th>Jenis</th>
                                    <th>Nominal</th>
                                    <th>Bukti</th>
                                    <th>Pemesan</th>
                                    @role(['superadmin', 'manager'])<th>Aksi</th>@endrole
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($pending as $payment)
                                    <tr>
                                        <td>{{ optional($payment->invoice)->invoice_no ?? '-' }}</td>
                                        <td><span class="badge bg-secondary">{{ strtoupper($payment->payment_type) }}</span></td>
                                        <td>{{ $rp($payment->amount) }}</td>
                                        <td>
                                            @if ($payment->proof_url)
                                                <a href="{{ $payment->proof_url }}" target="_blank" class="btn btn-icon btn-outline-secondary"><i data-feather="eye"></i></a>
                                            @else
                                                <span class="text-muted">Tanpa Bukti</span>
                                            @endif
                                        </td>
                                        <td>{{ optional(optional($payment->order)->user)->name ?? '-' }}</td>
                                        @role(['superadmin', 'manager'])
                                            <td>
                                                <div class="btn-group">
                                                    <form action="{{ route('payment.approve', $payment->id) }}" method="POST" data-confirm="Setujui pembayaran ini?">
                                                        @csrf
                                                        <button type="submit" class="btn btn-icon btn-xs btn-primary me-2" title="Setujui"><i data-feather="check"></i></button>
                                                    </form>
                                                    <form action="{{ route('payment.reject', $payment->id) }}" method="POST" data-confirm="Tolak pembayaran ini?">
                                                        @csrf
                                                        <button type="submit" class="btn btn-icon btn-xs btn-danger" title="Tolak"><i data-feather="x"></i></button>
                                                    </form>
                                                </div>
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

    {{-- ============ DISETUJUI (approved) ============ --}}
    <div class="row">
        <div class="col-12 grid-margin stretch-card">
            <div class="card overflow-hidden">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-baseline mb-md-4">
                        <h6 class="card-title mb-0">Pembayaran Disetujui
                            <span class="badge bg-success ms-1">{{ $approved->count() }}</span>
                        </h6>
                    </div>
                    <div class="table-responsive mt-3">
                        <table class="table table-centered datatable dt-responsive nowrap" style="width:100%">
                            <thead>
                                <tr>
                                    <th>No. Invoice</th>
                                    <th>Jenis</th>
                                    <th>Nominal</th>
                                    <th>Bukti</th>
                                    <th>Pemesan</th>
                                    <th>Disetujui</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($approved as $payment)
                                    <tr>
                                        <td>{{ optional($payment->invoice)->invoice_no ?? '-' }}</td>
                                        <td><span class="badge bg-secondary">{{ strtoupper($payment->payment_type) }}</span></td>
                                        <td>{{ $rp($payment->amount) }}</td>
                                        <td>
                                            @if ($payment->proof_url)
                                                <a href="{{ $payment->proof_url }}" target="_blank" class="btn btn-icon btn-outline-secondary"><i data-feather="eye"></i></a>
                                            @else
                                                <span class="text-muted">Tanpa Bukti</span>
                                            @endif
                                        </td>
                                        <td>{{ optional(optional($payment->order)->user)->name ?? '-' }}</td>
                                        <td>{{ optional(optional($payment->approval)->approved_at)->format('d/m/Y H:i') ?? '-' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ============ DITOLAK (rejected) ============ --}}
    <div class="row">
        <div class="col-12 grid-margin stretch-card">
            <div class="card overflow-hidden">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-baseline mb-md-4">
                        <h6 class="card-title mb-0">Pembayaran Ditolak
                            <span class="badge bg-danger ms-1">{{ $rejected->count() }}</span>
                        </h6>
                    </div>
                    <div class="table-responsive mt-3">
                        <table class="table table-centered datatable dt-responsive nowrap" style="width:100%">
                            <thead>
                                <tr>
                                    <th>No. Invoice</th>
                                    <th>Jenis</th>
                                    <th>Nominal</th>
                                    <th>Pemesan</th>
                                    <th>Catatan</th>
                                    <th>Tanggal</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rejected as $payment)
                                    <tr>
                                        <td>{{ optional($payment->invoice)->invoice_no ?? '-' }}</td>
                                        <td><span class="badge bg-secondary">{{ strtoupper($payment->payment_type) }}</span></td>
                                        <td>{{ $rp($payment->amount) }}</td>
                                        <td>{{ optional(optional($payment->order)->user)->name ?? '-' }}</td>
                                        <td>{{ optional($payment->approval)->note ?? '-' }}</td>
                                        <td>{{ optional(optional($payment->approval)->approved_at)->format('d/m/Y H:i') ?? '-' }}</td>
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
    <script src="{{ asset('assets/plugins/select2/select2.min.js') }}"></script>
@endpush

@push('custom-scripts')
    <script>
        $(function() {
            $(".datatable").DataTable({
                pageLength: 10,
                responsive: true,
                order: []
            });
            $(".dataTables_length select, .dataTables_filter input").addClass("form-control mb-2");
        });
    </script>
@endpush
