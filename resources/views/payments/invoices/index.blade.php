@extends('layouts.master')
@section('title', 'Daftar Invoice - SiMAPA')

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
                    <h6 class="card-title mb-0">Daftar Invoice</h6>
                </div>

                {{-- Filter --}}
                <form method="GET" class="row g-2 mb-3">
                    <div class="col-md-3">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">Semua Status</option>
                            @foreach(['draft','diterbitkan','jatuh_tempo','lunas','dibatalkan','refund'] as $s)
                                <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>
                                    {{ Str::title(str_replace('_',' ',$s)) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="type" class="form-select form-select-sm">
                            <option value="">Semua Tipe</option>
                            <option value="proforma" {{ request('type')==='proforma' ? 'selected':'' }}>Proforma</option>
                            <option value="regular"  {{ request('type')==='regular'  ? 'selected':'' }}>Regular</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-sm btn-outline-secondary w-100">Filter</button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-centered datatable dt-responsive nowrap"
                        style="border-collapse: collapse; border-spacing: 0; width: 100%;">
                        <thead>
                            <tr>
                                <th>No Invoice</th><th>Order</th><th>Tipe</th>
                                <th>Status</th><th>Nominal</th><th>Jatuh Tempo</th><th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($invoices as $inv)
                            @php
                                $statusColors = [
                                    'draft'       => 'secondary',
                                    'diterbitkan' => 'primary',
                                    'jatuh_tempo' => 'warning',
                                    'lunas'       => 'success',
                                    'dibatalkan'  => 'danger',
                                    'refund'      => 'info',
                                ];
                                $color = $statusColors[$inv->status] ?? 'secondary';
                            @endphp
                            <tr>
                                <td><strong>{{ $inv->invoice_no }}</strong></td>
                                <td>
                                    <small class="text-muted">{{ $inv->order->code_order }}</small><br>
                                    {{ Str::limit($inv->order->details->title ?? '-', 30) }}
                                </td>
                                <td>
                                    <span class="badge bg-{{ $inv->type === 'proforma' ? 'secondary' : 'info' }}">
                                        {{ ucfirst($inv->type) }}
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-{{ $color }}">
                                        {{ Str::title(str_replace('_',' ',$inv->status)) }}
                                    </span>
                                </td>
                                <td>
                                    @if($inv->payment)
                                        Rp {{ number_format($inv->payment->amount, 0, ',', '.') }}
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td><small>{{ $inv->due_at ? $inv->due_at->format('d/m/Y') : '-' }}</small></td>
                                <td>
                                    <a href="{{ route('invoice.show', $inv->id) }}" class="btn btn-xs btn-primary">Detail</a>
                                    @if(in_array($inv->status, ['diterbitkan','lunas']))
                                        <a href="{{ route('invoice.pdf', $inv->id) }}" target="_blank" class="btn btn-xs btn-outline-secondary">Download</a>
                                    @endif
                                    @hasanyrole('manager|superadmin')
                                        <a href="{{ route('invoice.edit', $inv->id) }}" class="btn btn-xs btn-outline-secondary">Edit</a>
                                    @endhasanyrole
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
        $(".datatable").DataTable({
            pageLength: 25,
            responsive: true,
            columnDefs: [{ orderable: false, targets: 6 }],
            language: { emptyTable: "Belum ada invoice." }
        });
    });
</script>
@endpush
