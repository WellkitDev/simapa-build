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
                        <h6 class="card-title mb-0">
                            {{ $trashed ? 'Order Dibatalkan' : 'Manajemen Order' }}
                        </h6>
                        @if ($trashed)
                            <a href="{{ route('order.book.index') }}" class="btn btn-sm btn-outline-secondary">
                                ← Kembali ke order aktif
                            </a>
                        @else
                            <a href="{{ route('order.book.index', ['trashed' => 1]) }}"
                                class="btn btn-sm btn-outline-secondary">
                                Tampilkan order dibatalkan
                            </a>
                        @endif
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
                                            <td>{{ $order->details ? Str::limit($order->details->title, 30) : '-' }}</td>
                                            <td class="dt-judul">
                                                @foreach ($order->details?->authors ?? [] as $author)
                                                    <span class="badge border text-dark fw-normal bg-light me-1 mb-1">
                                                        <i class="fa fa-user size-10"></i> {{ $author->name }}
                                                    </span>
                                                @endforeach
                                            </td>
                                            <td>
                                                @switch($order->details?->type)
                                                    @case('bk_mandiri')
                                                    @case('bk_kolab')
                                                        Buku
                                                    @break

                                                    @case('at_mandiri')
                                                    @case('at_kolab')
                                                        Artikel
                                                    @break

                                                    @default
                                                        —
                                                @endswitch
                                            </td>
                                            <td>
                                                @if ($order->isCancelled())
                                                    <span class="badge bg-secondary">Dibatalkan</span>
                                                @elseif ($order->status == 'pending')
                                                    <span class="badge bg-warning text-dark">Menunggu</span>
                                                @else
                                                    <span class="badge bg-success">Diproses</span>
                                                @endif
                                            </td>
                                            <td>
                                                @php
                                                    $isJournal = in_array($order->details?->type, ['at_mandiri', 'at_kolab'], true);
                                                    $editUrl   = $isJournal
                                                        ? route('order.journal.edit', $order->code_order)
                                                        : route('order.book.edit', $order->code_order);
                                                    $hasPayment = $order->payments->isNotEmpty();
                                                @endphp

                                                @if ($order->isCancelled())
                                                    {{-- Dibatalkan: hanya-baca + Pulihkan (manager/superadmin) --}}
                                                    <a href="{{ route('order.book.show', $order->code_order) }}"
                                                        class="btn btn-icon btn-outline-secondary" title="Lihat">
                                                        <i data-feather="eye"></i>
                                                    </a>
                                                    @can('order.restore')
                                                        <form action="{{ route('order.restore', $order->code_order) }}"
                                                            method="POST" class="d-inline m-0">
                                                            @csrf
                                                            <button class="btn btn-icon btn-outline-success" title="Pulihkan">
                                                                <i data-feather="rotate-ccw"></i>
                                                            </button>
                                                        </form>
                                                    @endcan
                                                @else
                                                    @if (!$hasPayment)
                                                        <a href="{{ route('payment.create', $order->code_order) }}"
                                                            class="btn btn-icon btn-primary" title="Pembayaran">
                                                            <i data-feather="credit-card"></i>
                                                        </a>
                                                    @else
                                                        <a href="{{ route('order.book.show', $order->code_order) }}"
                                                            class="btn btn-icon btn-primary" title="Lihat">
                                                            <i data-feather="eye"></i>
                                                        </a>
                                                    @endif

                                                    @can('order.edit')
                                                        <a href="{{ $editUrl }}" class="btn btn-icon btn-outline-primary" title="Edit">
                                                            <i data-feather="edit"></i>
                                                        </a>
                                                    @endcan

                                                    @if ($order->isCancellable())
                                                        @can('order.cancel')
                                                            <button type="button" class="btn btn-icon btn-outline-danger"
                                                                data-bs-toggle="modal" data-bs-target="#cancelOrder{{ $order->id }}"
                                                                title="Batalkan order">
                                                                <i data-feather="x-octagon"></i>
                                                            </button>
                                                        @endcan
                                                    @endif

                                                    {{-- Syarat refund SENGAJA mengikuti RefundController::paidIn() persis:
                                                         status 'paid' saja, tanpa melihat approval. Payment sudah 'paid'
                                                         sejak disubmit (approval-nya menyusul), dan RefundController
                                                         memang mengizinkan refund pada keadaan itu — menyaringnya dengan
                                                         approval di sini akan menyembunyikan tombol untuk aksi yang
                                                         sebenarnya masih sah. --}}
                                                    @can('order.refund')
                                                        @php
                                                            $paidIn   = $order->payments->where('status', 'paid')->where('payment_type', '!=', 'refund')->sum('amount');
                                                            $refunded = $order->payments->where('payment_type', 'refund')->isNotEmpty();
                                                        @endphp
                                                        @if ($refunded)
                                                            <a href="{{ route('order.refund.pdf', $order->code_order) }}" target="_blank"
                                                                class="btn btn-icon btn-outline-secondary" title="Bukti Refund">
                                                                <i data-feather="file-text"></i>
                                                            </a>
                                                        @elseif ($paidIn > 0)
                                                            <a href="{{ route('order.refund.form', $order->code_order) }}"
                                                                class="btn btn-icon btn-outline-warning" title="Refund">
                                                                <i data-feather="corner-up-left"></i>
                                                            </a>
                                                        @endif
                                                    @endcan
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {{-- Modal ditaruh DI LUAR <table>: DataTables + responsive memindahkan
                         DOM baris, dan modal yang bersarang di <td> bisa ikut tersembunyi. --}}
                    @foreach ($orders as $order)
                        @if ($order->isCancellable())
                            @can('order.cancel')
                            <div class="modal fade" id="cancelOrder{{ $order->id }}" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog">
                                    <form class="modal-content" method="POST"
                                        action="{{ route('order.cancel', $order->code_order) }}">
                                        @csrf
                                        @method('DELETE')
                                        <div class="modal-header">
                                            <h5 class="modal-title">Batalkan Order</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                                        </div>
                                        <div class="modal-body">
                                            <p class="mb-2">Order berikut akan dibatalkan:</p>
                                            <ul class="mb-3">
                                                <li>Kode: <strong>{{ $order->code_order }}</strong></li>
                                                <li>Judul: {{ $order->details?->title ?? '—' }}</li>
                                                <li>Total biaya: Rp {{ number_format((int) ($order->details?->cost_amount ?? 0), 0, ',', '.') }}</li>
                                            </ul>
                                            <div class="mb-2">
                                                <label class="form-label">Alasan pembatalan <span class="text-muted">(opsional)</span></label>
                                                <textarea name="cancel_reason" class="form-control" rows="3"
                                                    placeholder="Mis. salah input harga, klien membatalkan"></textarea>
                                            </div>
                                            <p class="small text-muted mb-0">
                                                Order tidak dihapus permanen — nomor order tetap tercatat dan bisa dipulihkan
                                                oleh manager/superadmin.
                                            </p>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Kembali</button>
                                            <button type="submit" class="btn btn-sm btn-danger">Ya, batalkan order</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            @endcan
                        @endif
                    @endforeach

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
                language: {
                    // Daftar order dibatalkan lazim kosong — pesan bawaan DataTables
                    // berbahasa Inggris dan tidak menjelaskan apa-apa di konteks ini.
                    emptyTable: @json($trashed ? 'Belum ada order yang dibatalkan.' : 'Belum ada order.')
                }
            });
            $(".dataTables_length select, .dataTables_filter input").addClass("form-control mb-2");
            $('.custom-select').select2();
        });
    </script>
@endpush
