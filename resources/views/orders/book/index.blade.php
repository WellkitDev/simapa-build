@extends('layouts.master')
@section('title', 'Daftar Order - SiMAPA')

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
                        <h6 class="card-title mb-0">Manajemen Order</h6>
                    </div>

                    <div class="row mt-4">
                        <div class="table-responsive">
                            <table class="table table-centered datatable dt-responsive nowrap"
                                style="border-collapse: collapse; border-spacing: 0; width: 100%;">
                                <thead>
                                    <tr>
                                        <th>Kode Order</th>
                                        <th>Judul</th>
                                        <th>Penulis</th>
                                        <th>Jenis</th>
                                        <th>Status Order</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($orders as $order)
                                        <tr>
                                            <td>{{ $order->code_order }}</td>
                                            <td>{{ Str::title(Str::limit($order->details->title, 30)) ?? '-' }}</td>
                                            <td class="dt-judul">
                                                @foreach ($order->details->authors as $author)
                                                    <span class="badge border text-dark fw-normal bg-light me-1 mb-1">
                                                        <i class="fa fa-user size-10"></i> {{ $author->name }}
                                                    </span>
                                                @endforeach
                                            </td>
                                            <td>
                                                @switch($order->details->type)
                                                    @case('bk_mandiri')
                                                        Buku
                                                    @break

                                                    @case('bk_kolab')
                                                        Buku
                                                    @break

                                                    @case('at_mandiri')
                                                        Artikel
                                                    @break

                                                    @case('at_kolab')
                                                        Artikel
                                                    @break
                                                @endswitch
                                            </td>
                                            <td>
                                                @if ($order->status == 'pending')
                                                    <span class="badge bg-warning text-dark">Menunggu</span>
                                                @else
                                                    <span class="badge bg-success">Diproses</span>
                                                @endif
                                            </td>
                                            <td>
                                                @php
                                                    // Cek apakah ada pembayaran yang sudah disetujui (paid)
                                                    $hasApprovedPayment = $order->payments
                                                        ->where('status', 'paid')
                                                        ->isNotEmpty();
                                                @endphp

                                                {{-- KONDISI 1: Jika status pending DAN belum ada data payment sama sekali --}}
                                                @if ($order->status == 'pending' && $order->payments->isEmpty())
                                                    <a href="{{ route('payment.create', $order->code_order) }}"
                                                        class="btn btn-icon btn-primary">
                                                        <i class="" data-feather="credit-card"></i>
                                                    </a>

                                                    {{-- KONDISI 2: Sudah upload pembayaran tapi belum di-approve (masih pending di tb_payments) --}}
                                                @elseif($order->status == 'pending' && !$hasApprovedPayment)
                                                    <div class="btn-group">
                                                        <a href="{{ route('order.book.show', $order->code_order) }}"
                                                            class="btn btn-icon btn-outline-primary">
                                                            <i class="" data-feather="check"></i>
                                                        </a>

                                                    </div>

                                                    {{-- KONDISI 3: Pembayaran sudah di-approve (status sudah 'paid' atau order status berubah) --}}
                                                @else
                                                    <a href="{{ route('order.book.show', $order->code_order) }}"
                                                        class="btn btn-icon btn-primary">
                                                        <i class="" data-feather="eye"></i>
                                                    </a>
                                                    @if (in_array($order->details->type, ['at_mandiri', 'at_kolab']))
                                                    <a href="{{ route('order.journal.edit', $order->code_order) }}"
                                                        class="btn btn-icon btn-outline-primary">
                                                        <i class="" data-feather="edit"></i>
                                                    </a>
                                                    @else
                                                    <a href="{{ route('order.book.edit', $order->code_order) }}"
                                                        class="btn btn-icon btn-outline-primary">
                                                        <i class="" data-feather="edit"></i>
                                                    </a>
                                                    @endif
                                                    @role('superadmin')
                                                        @php
                                                            $paidIn = $order->payments->where('status','paid')->where('payment_type','!=','refund')->sum('amount');
                                                            $refunded = $order->payments->where('payment_type','refund')->isNotEmpty();
                                                        @endphp
                                                        @if($refunded)
                                                            <a href="{{ route('order.refund.pdf', $order->code_order) }}" target="_blank" class="btn btn-icon btn-outline-secondary" title="Bukti Refund"><i class="" data-feather="file-text"></i></a>
                                                        @elseif($paidIn > 0)
                                                            <a href="{{ route('order.refund.form', $order->code_order) }}" class="btn btn-icon btn-outline-warning" title="Refund"><i class="" data-feather="corner-up-left"></i></a>
                                                        @endif
                                                    @endrole
                                                @endif
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
                responsive: true,
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
