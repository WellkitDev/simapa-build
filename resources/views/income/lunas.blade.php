@extends('layouts.master')
@section('title', 'Laporan Order Selesai - SiMAPA')

@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
<div class="d-flex justify-content-between align-items-center flex-wrap grid-margin">
    <h4 class="mb-0">Laporan Order Selesai (Lunas)</h4>
    <div class="btn-group">
        <a href="{{ route('income.lunas.pdf') }}" target="_blank" class="btn btn-sm btn-outline-danger">Export PDF</a>
        <a href="{{ route('income.lunas.csv') }}" class="btn btn-sm btn-outline-success">Export CSV</a>
    </div>
</div>

<div class="row">
    <div class="col-md-4 grid-margin stretch-card">
        <div class="card"><div class="card-body"><h6 class="card-title mb-0">Jumlah Order Lunas</h6><h4 class="mt-2 mb-0 text-success">{{ $kpi['jumlah'] }}</h4></div></div>
    </div>
    <div class="col-md-4 grid-margin stretch-card">
        <div class="card"><div class="card-body"><h6 class="card-title mb-0">Total Nilai</h6><h4 class="mt-2 mb-0 text-primary">Rp {{ number_format($kpi['nilai'], 0, ',', '.') }}</h4></div></div>
    </div>
</div>

<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Detail Order Selesai</h6>
            <div class="table-responsive">
                <table class="table table-centered datatable dt-responsive nowrap" style="width:100%">
                    <thead><tr><th>Kode Order</th><th>Klien</th><th>Nilai</th><th>Tanggal Lunas</th></tr></thead>
                    <tbody>
                        @foreach($detail as $o)
                        <tr>
                            <td>{{ $o->code_order }}</td>
                            <td>{{ optional($o->details)->title ?? '-' }}<br><small class="text-muted">{{ optional($o->contact)->cp_email }}</small></td>
                            <td>Rp {{ number_format($o->nilai, 0, ',', '.') }}</td>
                            <td data-order="{{ optional($o->tanggal_lunas)->timestamp ?? 0 }}">{{ optional($o->tanggal_lunas)->format('d M Y') ?? '-' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div></div>
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
    $(function () { $(".datatable").DataTable({ pageLength: 25, responsive: true, language: { emptyTable: "Belum ada order selesai." } }); });
</script>
@endpush
