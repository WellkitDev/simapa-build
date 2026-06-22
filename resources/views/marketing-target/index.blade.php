@extends('layouts.master')
@section('title', 'Target Marketing - SiMAPA')

@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@php
    $statusBadge = ['aktif' => 'bg-info', 'berakhir' => 'bg-secondary', 'akan_datang' => 'bg-light text-dark'];
@endphp
<div class="row">
    <div class="col-md-4 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Buat Target</h6>
            <form method="POST" action="{{ route('marketing-target.store') }}">
                @csrf
                <div class="mb-2">
                    <label class="form-label">Cakupan</label>
                    <select name="scope" id="scopeSelect" class="form-select form-select-sm">
                        <option value="individual">Individu</option>
                        <option value="all">Semua marketing</option>
                    </select>
                </div>
                <div class="mb-2" id="userWrap">
                    <label class="form-label">Marketing</label>
                    <select name="user_ids[]" class="form-select form-select-sm" multiple size="4">
                        @foreach($marketers as $m)
                            <option value="{{ $m->id }}">{{ $m->name }}</option>
                        @endforeach
                    </select>
                    <small class="text-muted">Ctrl/Cmd untuk pilih beberapa.</small>
                </div>
                <div class="mb-2"><label class="form-label">Target Pemasukan (Rp)</label>
                    <input type="number" name="target_amount" min="0" step="1000" class="form-control form-control-sm" required></div>
                <div class="mb-2"><label class="form-label">Rate Komisi (%)</label>
                    <input type="number" name="commission_rate" min="0" max="100" step="0.5" class="form-control form-control-sm" required></div>
                <div class="row">
                    <div class="col-6 mb-2"><label class="form-label">Mulai</label>
                        <input type="date" name="start_date" class="form-control form-control-sm" value="{{ now()->toDateString() }}" required></div>
                    <div class="col-6 mb-2"><label class="form-label">Selesai</label>
                        <input type="date" name="end_date" class="form-control form-control-sm" value="{{ now()->addMonth()->toDateString() }}" required></div>
                </div>
                <button type="submit" class="btn btn-primary btn-sm mt-1">Simpan Target</button>
            </form>
        </div></div>
    </div>

    <div class="col-md-8 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="card-title mb-0">Daftar Target</h6>
                <div class="btn-group btn-group-sm">
                    <a href="{{ route('marketing-target.index') }}" class="btn btn-outline-primary {{ !$status ? 'active' : '' }}">Semua</a>
                    <a href="{{ route('marketing-target.index', ['status' => 'aktif']) }}" class="btn btn-outline-primary {{ $status === 'aktif' ? 'active' : '' }}">Berjalan</a>
                    <a href="{{ route('marketing-target.index', ['status' => 'berakhir']) }}" class="btn btn-outline-primary {{ $status === 'berakhir' ? 'active' : '' }}">History</a>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover datatable" style="width:100%">
                    <thead><tr>
                        <th>Marketing</th><th>Periode</th><th>Target</th><th>Realisasi</th><th>Capaian</th>
                        <th>Komisi</th><th>Status</th><th>Komisi Bayar</th><th>Aksi</th>
                    </tr></thead>
                    <tbody>
                        @forelse($rows as $r)
                            @php $ccls = $r['capaian_persen'] >= 100 ? 'bg-success' : ($r['capaian_persen'] >= 75 ? 'bg-warning text-dark' : 'bg-danger'); @endphp
                            <tr>
                                <td>{{ $r['name'] }} @if($r['scope'] === 'all')<span class="badge bg-dark">Semua</span>@endif</td>
                                <td><small>{{ \Illuminate\Support\Carbon::parse($r['start_date'])->format('d/m/y') }} – {{ \Illuminate\Support\Carbon::parse($r['end_date'])->format('d/m/y') }}</small></td>
                                <td>Rp {{ number_format($r['target'], 0, ',', '.') }}</td>
                                <td>Rp {{ number_format($r['realisasi'], 0, ',', '.') }}</td>
                                <td><span class="badge {{ $ccls }}">{{ $r['capaian_persen'] }}%</span></td>
                                <td>Rp {{ number_format($r['komisi'], 0, ',', '.') }}</td>
                                <td>
                                    <span class="badge {{ $statusBadge[$r['status']] ?? 'bg-secondary' }}">{{ ucfirst(str_replace('_', ' ', $r['status'])) }}</span>
                                    @if($r['tertunggak'])<span class="badge bg-danger">Tertunggak</span>@endif
                                </td>
                                <td>
                                    @if($r['commission_paid'])
                                        <span class="badge bg-success">Dibayar</span>
                                    @else
                                        <form action="{{ route('marketing-target.paid', $r['id']) }}" method="POST" class="m-0">
                                            @csrf
                                            <button class="btn btn-xs btn-outline-success">Tandai dibayar</button>
                                        </form>
                                    @endif
                                </td>
                                <td>
                                    <form action="{{ route('marketing-target.destroy', $r['id']) }}" method="POST" class="m-0" onsubmit="return confirm('Hapus target ini?');">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-xs btn-outline-danger">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-center text-muted py-3">Belum ada target.</td></tr>
                        @endforelse
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
@endpush
@push('custom-scripts')
<script>
    $(function () {
        $('.datatable').DataTable({ pageLength: 10, order: [] });
        $('#scopeSelect').on('change', function () {
            $('#userWrap').toggle(this.value === 'individual');
        });
    });
</script>
@endpush
