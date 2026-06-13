@extends('layouts.master')
@section('title', 'Data Judul - SiMAPA')

@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card overflow-hidden">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-baseline mb-3">
                    <h6 class="card-title mb-0">Data Judul</h6>
                </div>

                {{-- Search & Filter Form --}}
                <form method="GET" action="{{ route('order.book.indexJudul') }}" class="row g-2 mb-4">
                    <div class="col-md-4">
                        <input type="text" name="search" class="form-control form-control-sm"
                            placeholder="Cari judul atau nama author..."
                            value="{{ request('search') }}">
                    </div>
                    <div class="col-md-2">
                        <select name="tipe" class="form-select form-select-sm">
                            <option value="">Semua Tipe</option>
                            <option value="bk" {{ request('tipe') === 'bk' ? 'selected' : '' }}>Buku</option>
                            <option value="at" {{ request('tipe') === 'at' ? 'selected' : '' }}>Artikel</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">Semua Status</option>
                            @foreach(['menunggu_proses','templating','editing','revisi','submit','loa','publish','layout','proofreading','isbn','cetak','terbit'] as $s)
                                <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>
                                    {{ Str::title(str_replace('_', ' ', $s)) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="tahun" class="form-select form-select-sm">
                            <option value="">Semua Tahun</option>
                            @foreach($tahunList as $tahun)
                                <option value="{{ $tahun }}" {{ request('tahun') == $tahun ? 'selected' : '' }}>{{ $tahun }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-sm btn-primary w-100">Filter</button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-centered datatable dt-responsive nowrap"
                        style="border-collapse: collapse; border-spacing: 0; width: 100%;">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Judul Naskah</th>
                                <th>Tipe</th>
                                <th>Status Progres</th>
                                <th>Handler</th>
                                <th>Update Terakhir</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($judulData as $row)
                            <tr>
                                <td>{{ $loop->iteration }}</td>
                                <td class="text-capitalize"><strong>{{ Str::limit($row->title, 40) }}</strong></td>
                                <td>
                                    <span class="badge bg-info">
                                        {{ in_array($row->type, ['bk_mandiri','bk_kolab']) ? 'Buku' : 'Artikel' }}
                                    </span>
                                </td>
                                <td>
                                    @php
                                        $statusColors = [
                                            'menunggu_proses' => 'secondary',
                                            'templating' => 'warning', 'editing' => 'warning', 'layout' => 'warning',
                                            'revisi' => 'warning', 'proofreading' => 'warning', 'isbn' => 'warning',
                                            'submit' => 'primary', 'cetak' => 'primary', 'loa' => 'primary',
                                            'publish' => 'success', 'terbit' => 'success',
                                        ];
                                        $color = $statusColors[$row->progress_status] ?? 'secondary';
                                    @endphp
                                    <span class="badge bg-{{ $color }}">
                                        {{ Str::title(str_replace('_', ' ', $row->progress_status ?? 'menunggu_proses')) }}
                                    </span>
                                </td>
                                <td><small class="text-muted text-capitalize">{{ $row->progress_role ?? '-' }}</small></td>
                                <td><small>{{ $row->progress_updated_at ? \Carbon\Carbon::parse($row->progress_updated_at)->diffForHumans() : '-' }}</small></td>
                                <td>
                                    <a href="{{ route('order.indexJudul.detail', $row->detail_id) }}"
                                        class="btn btn-sm btn-primary">Detail</a>
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
        $(".datatable").DataTable({ pageLength: 25, searching: false, ordering: false });
    });
</script>
@endpush
