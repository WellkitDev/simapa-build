@extends('layouts.master')
@section('title', 'Detail Author - SiMAPA')

@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
    @php
        $wa = function ($phone) {
            $p = preg_replace('/\D/', '', (string) $phone);
            if ($p !== '' && str_starts_with($p, '0')) {
                $p = '62' . substr($p, 1);
            }
            return $p;
        };
        $jenis = fn ($type) => \Illuminate\Support\Str::startsWith((string) $type, 'bk_') ? 'Buku'
            : (\Illuminate\Support\Str::startsWith((string) $type, 'at_') ? 'Artikel' : ($type ?: '-'));
    @endphp

    <div class="row">
        <div class="col-lg-4 grid-margin stretch-card">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <h5 class="mb-0">{{ $author->name }}</h5>
                        <a href="{{ route('author.index') }}" class="btn btn-xs btn-outline-secondary">Kembali</a>
                    </div>
                    <table class="table table-sm mb-3">
                        <tr><td class="text-muted" style="width:110px">Affiliasi</td><td>{{ $author->affiliation ?: '-' }}</td></tr>
                        <tr><td class="text-muted">Email</td><td>{{ $author->email ?: '-' }}</td></tr>
                        <tr><td class="text-muted">Telp</td><td>{{ $author->phone ?: '-' }}</td></tr>
                        <tr><td class="text-muted">Jml Order</td><td><span class="badge bg-primary">{{ $author->order_details_count }}</span></td></tr>
                    </table>
                    <div class="d-flex gap-2">
                        @if ($author->phone)
                            <a href="https://wa.me/{{ $wa($author->phone) }}" target="_blank" class="btn btn-sm btn-outline-success"><i data-feather="message-circle" class="icon-sm me-1"></i>WhatsApp</a>
                        @endif
                        @if ($author->email)
                            <a href="mailto:{{ $author->email }}" class="btn btn-sm btn-outline-secondary"><i data-feather="mail" class="icon-sm me-1"></i>Email</a>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-8 grid-margin stretch-card">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-title">Riwayat Order</h6>
                    <div class="table-responsive mt-3">
                        <table class="table table-hover table-sm datatable" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Kode Order</th>
                                    <th>Judul</th>
                                    <th>Jenis</th>
                                    <th>Tanggal</th>
                                    <th>Status</th>
                                    <th class="text-center">Posisi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($author->orderDetails as $d)
                                    <tr>
                                        <td>{{ optional($d->order)->code_order ?? '-' }}</td>
                                        <td>{{ $d->title ?: '-' }}</td>
                                        <td>{{ $jenis($d->type) }}</td>
                                        <td>{{ optional($d->order)->ordered_at ? \Carbon\Carbon::parse($d->order->ordered_at)->format('d/m/Y') : '-' }}</td>
                                        <td><span class="badge bg-secondary">{{ ucfirst(optional($d->order)->status ?? '-') }}</span></td>
                                        <td class="text-center">{{ optional($d->pivot)->position ?? '-' }}</td>
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
@endpush

@push('custom-scripts')
    <script>
        $(function() {
            $(".datatable").DataTable({ pageLength: 10, order: [], language: { emptyTable: 'Belum ada riwayat order.' } });
        });
    </script>
@endpush
