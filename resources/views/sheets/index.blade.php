@extends('layouts.master')
@section('title', 'Lembar Kerja - SiMAPA')

@push('plugin-styles')
    <link href="{{ URL::asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
    @php $vis = \App\Models\CustomSheet::VISIBILITIES; @endphp
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h5 class="mb-0">Lembar Kerja</h5>
        <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#formSheet">+ Lembar Baru</button>
    </div>
    <div class="collapse mb-3" id="formSheet">
        <form method="POST" action="{{ route('sheet.store') }}" class="border rounded p-3 d-flex gap-2 align-items-end flex-wrap">
            @csrf
            <div><label class="form-label small mb-1">Nama Lembar</label><input name="name" class="form-control form-control-sm" style="min-width:240px" required></div>
            <button class="btn btn-sm btn-primary">Buat</button>
        </form>
    </div>

    @foreach (['Lembar Saya' => $mine, 'Dibagikan ke Saya' => $shared] as $label => $list)
        <div class="card mb-3"><div class="card-body">
            <h6 class="card-title">{{ $label }} <span class="badge bg-secondary ms-1">{{ $list->count() }}</span></h6>
            <div class="table-responsive mt-2">
                <table class="table table-sm table-hover datatable" style="width:100%">
                    <thead><tr><th>Nama</th><th>Pemilik</th><th>Visibilitas</th><th>Diperbarui</th><th>Aksi</th></tr></thead>
                    <tbody>
                        @foreach ($list as $s)
                            <tr>
                                <td class="dt-judul">{{ $s->name }}</td>
                                <td>{{ optional($s->owner)->name ?? '-' }}</td>
                                <td><span class="badge {{ $s->visibility === 'shared' ? 'bg-info' : 'bg-light text-dark border' }}">{{ $vis[$s->visibility] ?? $s->visibility }}</span></td>
                                <td>{{ optional($s->updated_at)->format('d/m/Y H:i') }}</td>
                                <td>
                                    <a href="{{ route('sheet.show', $s->id) }}" class="btn btn-xs btn-outline-primary">Buka</a>
                                    @if ($s->owner_id === auth()->id())
                                        <form method="POST" action="{{ route('sheet.destroy', $s->id) }}" class="d-inline" data-confirm="Hapus lembar ini?">@csrf @method('DELETE')<button class="btn btn-xs btn-outline-danger">Hapus</button></form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div></div>
    @endforeach
@endsection

@push('plugin-scripts')
    <script src="{{ URL::asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ URL::asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
    <script>$(function () { $('.datatable').DataTable({ pageLength: 10, order: [] }); });</script>
@endpush
