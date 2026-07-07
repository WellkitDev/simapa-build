@extends('layouts.master')
@section('title', 'Laporan Pemasukan - SiMAPA')

@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
<div class="d-flex justify-content-between align-items-center flex-wrap grid-margin">
    <h4 class="mb-0">Laporan Pemasukan (Kas Masuk)</h4>
    <div class="btn-group">
        <a href="{{ route('income.pemasukan.pdf') }}" target="_blank" class="btn btn-sm btn-outline-danger">Export PDF</a>
        <a href="{{ route('income.pemasukan.csv') }}" class="btn btn-sm btn-outline-success">Export CSV</a>
    </div>
</div>

<div class="row">
    @php
        $cards = [
            ['Total Kas Masuk (tahun ini)', 'Rp ' . number_format($kpi['total'], 0, ',', '.'), 'success'],
            ['Jumlah Pembayaran', $kpi['pembayaran'], 'primary'],
            ['Jumlah Order', $kpi['order'], 'info'],
        ];
    @endphp
    @foreach($cards as [$label, $val, $tone])
        <div class="col-md-4 grid-margin stretch-card">
            <div class="card"><div class="card-body">
                <h6 class="card-title mb-0">{{ $label }}</h6>
                <h4 class="mt-2 mb-0 text-{{ $tone }}">{{ $val }}</h4>
            </div></div>
        </div>
    @endforeach
</div>

<div class="row">
    <div class="col-md-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Rekap per Tahun</h6>
            <table class="table table-sm">
                <thead><tr><th>Tahun</th><th class="text-end">Order</th><th class="text-end">Total</th></tr></thead>
                <tbody>
                    @forelse($yearly as $row)
                        <tr><td>{{ $row->year }}</td><td class="text-end">{{ $row->order_count }}</td><td class="text-end">Rp {{ number_format($row->total, 0, ',', '.') }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="text-center text-muted">—</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div></div>
    </div>
    <div class="col-md-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Rekap per Bulan</h6>
            <table class="table table-sm">
                <thead><tr><th>Bulan</th><th class="text-end">Order</th><th class="text-end">Total</th></tr></thead>
                <tbody>
                    @forelse($monthly as $row)
                        <tr><td>{{ $row->month }}/{{ $row->year }}</td><td class="text-end">{{ $row->order_count }}</td><td class="text-end">Rp {{ number_format($row->total, 0, ',', '.') }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="text-center text-muted">—</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div></div>
    </div>
</div>

<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Detail Pembayaran</h6>
            <div class="table-responsive">
                <table class="table table-centered datatable dt-responsive nowrap" style="width:100%">
                    <thead>
                        <tr><th>Tanggal</th><th>Kode Order</th><th>Klien</th><th>Tipe</th><th>Nominal</th><th>No Invoice</th></tr>
                    </thead>
                    <tbody>
                        @foreach($detail as $p)
                        <tr>
                            <td data-order="{{ optional($p->paid_at)->timestamp ?? 0 }}">{{ optional($p->paid_at)->format('d M Y') ?? '-' }}</td>
                            <td>{{ optional($p->order)->code_order }}</td>
                            <td class="dt-judul">{{ optional(optional($p->order)->details)->title ?? '-' }}<br><small class="text-muted">{{ optional(optional($p->order)->contact)->cp_email }}</small></td>
                            <td>{{ ucfirst($p->payment_type) }}</td>
                            <td>Rp {{ number_format($p->amount, 0, ',', '.') }}</td>
                            <td>{{ optional($p->invoice)->invoice_no ?? '—' }}</td>
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
    $(function () {
        $(".datatable").DataTable({ pageLength: 25, responsive: true, order: [[0, 'desc']], language: { emptyTable: "Belum ada pemasukan." } });
    });
</script>
@endpush
