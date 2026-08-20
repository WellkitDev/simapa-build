@extends('layouts.master')
@section('title', 'Direktori Author - SiMAPA')

@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}" rel="stylesheet" />
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
    @endphp
    <div class="row">
        <div class="col-12 grid-margin stretch-card">
            <div class="card overflow-hidden">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-baseline mb-md-4">
                        <h6 class="card-title mb-0">Direktori Author
                            <span class="badge bg-primary ms-1">{{ $authors->count() }}</span>
                        </h6>
                    </div>
                    <div class="table-responsive mt-3">
                        <table class="table table-centered datatable dt-responsive nowrap" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Nama</th>
                                    <th>Affiliasi</th>
                                    <th>Email</th>
                                    <th>Telp</th>
                                    <th class="text-center">Jml Order</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($authors as $author)
                                    <tr>
                                        <td>{{ $author->name }}</td>
                                        <td class="dt-afiliasi">{{ $author->affiliation ?: '-' }}</td>
                                        <td>
                                            @if ($author->email)
                                                <a href="mailto:{{ $author->email }}">{{ $author->email }}</a>
                                            @else
                                                <span class="text-muted">-</span>
                                            @endif
                                        </td>
                                        <td>{{ $author->phone ?: '-' }}</td>
                                        <td class="text-center">
                                            <span class="badge bg-light text-dark border">{{ $author->order_details_count }}</span>
                                        </td>
                                        <td>
                                            <a href="{{ route('author.show', $author->id) }}" class="btn btn-icon btn-xs btn-outline-primary me-1" title="Detail"><i data-feather="eye"></i></a>
                                            @if ($author->phone)
                                                <a href="https://wa.me/{{ $wa($author->phone) }}" target="_blank" class="btn btn-icon btn-xs btn-outline-success me-1" title="WhatsApp"><i data-feather="message-circle"></i></a>
                                            @endif
                                            @if ($author->email)
                                                <a href="mailto:{{ $author->email }}" class="btn btn-icon btn-xs btn-outline-secondary" title="Email"><i data-feather="mail"></i></a>
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
@endsection

@push('plugin-scripts')
    <script src="{{ URL::asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js') }}"></script>
@endpush

@push('custom-scripts')
    <script>
        $(function() {
            $(".datatable").DataTable({ pageLength: 25, responsive: true, order: [[0, "asc"]] });
            $(".dataTables_length select, .dataTables_filter input").addClass("form-control mb-2");
        });
    </script>
@endpush
