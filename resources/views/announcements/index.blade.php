@extends('layouts.master')
@section('title', 'Pengumuman - SiMAPA')

@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@php $sb = ['draft' => 'bg-secondary', 'published' => 'bg-success', 'archived' => 'bg-dark']; @endphp
<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="card-title mb-0">Pengumuman</h6>
        <a href="{{ route('announcement.create') }}" class="btn btn-primary btn-sm">Buat Pengumuman</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover datatable" style="width:100%">
            <thead><tr><th>Judul</th><th>Status</th><th>Pin</th><th>Dibuat oleh</th><th>Tanggal</th><th>Aksi</th></tr></thead>
            <tbody>
                @foreach($announcements as $a)
                    <tr>
                        <td>{{ $a->title }}</td>
                        <td><span class="badge {{ $sb[$a->status] ?? 'bg-secondary' }}">{{ ucfirst($a->status) }}</span></td>
                        <td>@if($a->is_pinned)<i data-feather="bookmark" class="text-warning icon-sm"></i>@endif</td>
                        <td>{{ $a->creator?->name ?? '—' }}</td>
                        <td><small>{{ $a->created_at->format('d/m/y') }}</small></td>
                        <td>
                            <a href="{{ route('announcement.edit', $a->id) }}" class="btn btn-xs btn-outline-primary">Edit</a>
                            @if($a->status !== 'published')
                                <form action="{{ route('announcement.status', $a->id) }}" method="POST" class="d-inline m-0">@csrf
                                    <input type="hidden" name="status" value="published">
                                    <button class="btn btn-xs btn-outline-success">Terbitkan</button>
                                </form>
                            @else
                                <form action="{{ route('announcement.status', $a->id) }}" method="POST" class="d-inline m-0">@csrf
                                    <input type="hidden" name="status" value="archived">
                                    <button class="btn btn-xs btn-outline-secondary">Arsipkan</button>
                                </form>
                            @endif
                            <form action="{{ route('announcement.destroy', $a->id) }}" method="POST" class="d-inline m-0" onsubmit="return confirm('Hapus pengumuman ini?');">@csrf @method('DELETE')
                                <button class="btn btn-xs btn-outline-danger">Hapus</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div></div></div></div>
@endsection

@push('plugin-scripts')
    <script src="{{ URL::asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
    $(function () {
        $('.datatable').DataTable({ pageLength: 10, order: [], language: { emptyTable: 'Belum ada pengumuman.' } });
    });
</script>
@endpush
