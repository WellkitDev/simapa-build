@extends('layouts.master')
@section('title', 'Laporan Bulanan - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0">Report Bulanan</h5>
        <small class="text-muted">{{ $owner->name }} · {{ $month->translatedFormat('F Y') }}</small>
    </div>
    <form method="GET" class="d-flex gap-1">
        @if($owner->id !== auth()->id())<input type="hidden" name="user_id" value="{{ $owner->id }}">@endif
        <input type="month" name="month" value="{{ $month->format('Y-m') }}" class="form-control form-control-sm" style="width:160px">
        <button class="btn btn-sm btn-primary">Lihat</button>
    </form>
</div>

<div class="row g-2 mb-3">
    @php
        $kpis = [
            ['Selesai', $recap['selesai'], ''],
            ['Tepat waktu', $recap['tepat_waktu'], 'text-success'],
            ['Telat', $recap['telat'], 'text-danger'],
            ['On-time %', $recap['on_time_rate'] === null ? '—' : $recap['on_time_rate'] . '%', ''],
            ['Hari dilaporkan', $recap['dilaporkan'], ''],
        ];
    @endphp
    @foreach($kpis as [$lbl, $val, $cls])
        <div class="col"><div class="card"><div class="card-body py-2 text-center">
            <div class="text-muted" style="font-size:11px">{{ $lbl }}</div>
            <div class="fw-bold {{ $cls }}" style="font-size:20px">{{ $val }}</div>
        </div></div></div>
    @endforeach
</div>

<div class="card"><div class="card-body">
    <div class="table-responsive">
        <table class="table table-hover datatable" style="width:100%">
            <thead><tr><th>Tanggal</th><th>Selesai</th><th>Status</th><th>Catatan</th><th></th></tr></thead>
            <tbody>
                @php
                    $start = $month->copy()->startOfMonth();
                    $days = $month->copy()->endOfMonth()->day;
                @endphp
                @for($d = 1; $d <= $days; $d++)
                    @php
                        $cur = $start->copy()->day($d);
                        $key = $cur->toDateString();
                        $rep = $recap['reports']->get($key);
                        $selesai = $recap['per_hari']->get($key, 0);
                    @endphp
                    @if($selesai > 0 || $rep)
                        <tr>
                            <td>{{ $cur->translatedFormat('d M (D)') }}</td>
                            <td>{{ $selesai }}</td>
                            <td>@if($rep && $rep->isSubmitted())<span class="badge bg-success">Terkirim</span>@elseif($rep)<span class="badge bg-secondary">Draf</span>@else<span class="text-muted">—</span>@endif</td>
                            <td><small>{{ \Illuminate\Support\Str::limit($rep->note ?? '', 60) }}</small></td>
                            <td><a href="{{ route('report.daily', array_filter(['user_id' => $owner->id !== auth()->id() ? $owner->id : null, 'date' => $key])) }}" class="btn btn-xs btn-outline-primary">Buka</a></td>
                        </tr>
                    @endif
                @endfor
            </tbody>
        </table>
    </div>
</div></div>
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
<script src="{{ asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>$(function () { $('.datatable').DataTable({ pageLength: 31, order: [], language: { emptyTable: 'Belum ada aktivitas bulan ini.' } }); });</script>
@endpush
