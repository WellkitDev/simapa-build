@extends('layouts.master')
@section('title', 'Arsip Naskah - SiMAPA')

@push('plugin-styles')
    <link href="{{ asset('assets/plugins/datatables.net-bs4/dataTables.bootstrap4.css') }}" rel="stylesheet" />
    <link href="{{ asset('assets/plugins/datatables.net-responsive-bs4/responsive.bootstrap4.css') }}" rel="stylesheet" />
@endpush

@section('content')

<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <h4 class="mb-1">Arsip Naskah</h4>
        <p class="text-muted mb-0 small">
            Naskah yang sudah selesai, dibatalkan, maupun ditarik karena refund —
            tetap tersimpan dan bisa dicari.
        </p>
    </div>
    <div class="btn-group btn-group-sm">
        <a href="{{ route('naskah.arsip') }}"
           class="btn {{ $hanya === 'selesai' ? 'btn-primary' : 'btn-outline-secondary' }}">Selesai</a>
        <a href="{{ route('naskah.arsip', ['hanya' => 'batal']) }}"
           class="btn {{ $hanya === 'batal' ? 'btn-primary' : 'btn-outline-secondary' }}">Dibatalkan</a>
        <a href="{{ route('naskah.arsip', ['hanya' => 'ditarik']) }}"
           class="btn {{ $hanya === 'ditarik' ? 'btn-primary' : 'btn-outline-secondary' }}">Ditarik</a>
    </div>
</div>

<div class="card"><div class="card-body">
    <div class="table-responsive">
        <table class="table table-centered datatable dt-responsive nowrap w-100">
            <thead>
                <tr>
                    <th>No</th>
                    <th>Kode Order</th>
                    <th>Judul</th>
                    <th>Tahap Akhir</th>
                    <th>PJ</th>
                    <th>{{ ['batal' => 'Dibatalkan', 'ditarik' => 'Ditarik'][$hanya] ?? 'Diarsipkan' }}</th>
                    <th>{{ ['batal' => 'Alasan', 'ditarik' => 'Alasan Refund'][$hanya] ?? 'Pelaksana' }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $i => $p)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>
                            <a href="{{ route('naskah.show', $p->order_detail_id) }}" class="fw-bold">
                                {{ $p->orderDetail?->order?->code_order ?? '—' }}
                            </a>
                        </td>
                        <td class="dt-judul">{{ $p->orderDetail?->title ?? '—' }}</td>
                        <td>
                            {{ $p->stageLabelId() }}
                            @if ($p->isWithdrawn())
                                <span class="badge bg-secondary">Ditarik — Refund</span>
                            @endif
                        </td>
                        <td>{{ $p->pj?->name ?? '—' }}</td>
                        <td>
                            @if ($hanya === 'batal')
                                {{ $p->cancelled_at?->translatedFormat('j M Y') ?? '—' }}
                                <div class="text-muted small">oleh {{ $p->cancelledBy?->name ?? '—' }}</div>
                            @elseif ($hanya === 'ditarik')
                                {{ $p->withdrawn_at?->translatedFormat('j M Y') ?? '—' }}
                            @else
                                {{ $p->archived_at?->translatedFormat('j M Y') ?? '—' }}
                            @endif
                        </td>
                        <td class="dt-judul">
                            @switch($hanya)
                                @case('batal')     {{ $p->cancel_reason ?? '—' }} @break
                                @case('ditarik')   {{ $p->withdrawn_reason ?? '—' }} @break
                                @default           {{ $p->pelaksana?->name ?? '—' }}
                            @endswitch
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div></div>

@endsection

@push('plugin-scripts')
    <script src="{{ asset('assets/plugins/datatables.net/jquery.dataTables.js') }}"></script>
    <script src="{{ asset('assets/plugins/datatables.net-bs4/dataTables.bootstrap4.js') }}"></script>
    <script src="{{ asset('assets/plugins/datatables.net-responsive/dataTables.responsive.min.js') }}"></script>
    <script src="{{ asset('assets/plugins/datatables.net-responsive-bs4/responsive.bootstrap4.min.js') }}"></script>
@endpush

@push('custom-scripts')
<script>
    $(function () {
        $('.datatable').DataTable({
            responsive: true,
            columnDefs: [{ orderable: false, targets: 0 }],
            language: { search: 'Cari:', lengthMenu: 'Tampil _MENU_ baris', zeroRecords: 'Tidak ada data' },
        }).on('order.dt search.dt draw.dt', function () {
            $(this).DataTable().column(0, { search: 'applied', order: 'applied' })
                .nodes().each(function (cell, i) { cell.innerHTML = i + 1; });
        }).draw();
    });
</script>
@endpush
