@extends('layouts.master')
@section('title', 'Gudang Data - SiMAPA')

@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
    <link href="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
    @php $vis = \App\Models\DataAsset::VISIBILITIES; $kb = fn ($b) => $b ? number_format($b / 1024, 0) . ' KB' : ''; @endphp
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h5 class="mb-0">Gudang Data</h5>
        @can('data.create')
        <a href="{{ route('data.create') }}" class="btn btn-sm btn-primary">+ Tambah Data</a>
        @endcan
    </div>
    <div class="card"><div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-hover datatable dt-responsive nowrap" style="width:100%">
                <thead><tr><th>Nama</th><th>Jenis</th><th>Sumber</th><th>Deskripsi</th><th>Pemilik</th><th>Visibilitas</th><th>Diperbarui</th><th>Aksi</th></tr></thead>
                <tbody>
                    @foreach ($assets as $a)
                        <tr>
                            <td class="dt-judul">{{ $a->name }}</td>
                            <td><span class="badge {{ $a->type === 'file' ? 'bg-primary' : 'bg-info' }}">{{ \App\Models\DataAsset::TYPES[$a->type] ?? $a->type }}</span></td>
                            <td>
                                @if ($a->type === 'link' && $a->url)
                                    <a href="{{ $a->url }}" target="_blank" rel="noopener" class="btn btn-xs btn-outline-info">Buka ↗</a>
                                @elseif ($a->type === 'file' && $a->file_path)
                                    @can('data.download')
                                    <a href="{{ route('data.download', $a->id) }}" class="btn btn-xs btn-outline-primary">Unduh ⬇</a>
                                    @endcan
                                    <small class="text-muted d-block">{{ $a->file_name }} · {{ $kb($a->file_size) }}</small>
                                @else — @endif
                            </td>
                            <td class="dt-judul">{{ $a->description ?: '-' }}</td>
                            <td>{{ optional($a->owner)->name ?? '-' }}</td>
                            <td><span class="badge {{ $a->visibility === 'shared' ? 'bg-info' : 'bg-light text-dark border' }}">{{ $vis[$a->visibility] ?? $a->visibility }}</span></td>
                            <td>{{ optional($a->updated_at)->format('d/m/Y H:i') }}</td>
                            <td>
                                @if ($a->owner_id === auth()->id())
                                    @can('data.edit')
                                    <a href="{{ route('data.edit', $a->id) }}" class="btn btn-xs btn-outline-secondary">Edit</a>
                                    @endcan
                                    @can('data.delete')
                                    <form method="POST" action="{{ route('data.destroy', $a->id) }}" class="d-inline" data-confirm="Hapus data ini?">@csrf @method('DELETE')<button class="btn btn-xs btn-outline-danger">Hapus</button></form>
                                    @endcan
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div></div>
@endsection

@push('plugin-scripts')
    <script src="{{ URL::asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
    <script>$(function () { $('.datatable').DataTable({ pageLength: 25, responsive: true, order: [] }); });</script>
@endpush
