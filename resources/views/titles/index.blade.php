@extends('layouts.master')
@section('title', 'Direktori Judul - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
<link href="{{ asset('assets/libs/datatables.net-responsive-bs4/css/responsive.bootstrap4.min.css') }}" rel="stylesheet" />
<style>
    /* Kolom Aksi Direktori Judul: maksimal 2 tombol per baris, sisanya turun ke
       bawah — lebar "menempel" isi supaya tidak memakan ruang kolom tabel. */
    .title-actions{
        display:grid;
        grid-template-columns:repeat(2, 1fr);
        gap:.25rem;
        width:max-content;
        max-width:13rem;
    }
    .title-actions form{ margin:0; }
    .title-actions > a,
    .title-actions > button,
    .title-actions form > button{ width:100%; white-space:nowrap; }
</style>
@endpush

@section('content')
@php
    $sb = ['draft' => 'bg-secondary', 'menunggu' => 'bg-warning text-dark', 'disetujui' => 'bg-success', 'ditolak' => 'bg-danger'];
    $sl = ['draft' => 'Draf', 'menunggu' => 'Menunggu', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak'];
@endphp
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">Direktori Judul{{ $showInactive ? ' (termasuk nonaktif)' : '' }}</h5>
    <div class="d-flex gap-2">
        @if ($showInactive)
            <a href="{{ route('title.index') }}" class="btn btn-sm btn-outline-secondary">Sembunyikan judul nonaktif</a>
        @else
            <a href="{{ route('title.index', ['inactive' => 1]) }}" class="btn btn-sm btn-outline-secondary">Tampilkan judul nonaktif</a>
        @endif
        @if($canManage)
            @can('title.create')
            <a href="{{ route('title.create') }}" class="btn btn-sm btn-primary">Buat Judul</a>
            @endcan
        @endif
    </div>
</div>

<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <div class="table-responsive">
        <table class="table table-hover datatable dt-responsive nowrap" style="width:100%">
            <thead><tr><th>Kode</th><th>Judul</th><th>Jenis</th><th>Bidang Ilmu</th><th>Indeksasi</th><th>Tipe</th><th>Jml Order</th><th>Jml Author</th><th>Manuskrip</th><th>Distribusi</th><th>Status</th><th>Pembuat</th><th>Aksi</th></tr></thead>
            <tbody>
                @foreach($titles as $t)
                    <tr>
                        <td><span class="badge bg-dark">{{ $t->code ?? '—' }}</span></td>
                        <td class="dt-judul">{{ $t->title }}</td>
                        <td>{{ ucfirst($t->jenis) }}</td>
                        <td>{{ $t->scope?->scope ?? '—' }}</td>
                        <td>{{ $t->indeksasi ?: '—' }}</td>
                        <td>{{ ucfirst($t->tipe_naskah) }}</td>
                        <td>{{ $t->orders_count ?? 0 }}</td>
                        <td>{{ $t->authors_count ?? 0 }}</td>
                        @php $mstat = $t->manuscriptStatus(); @endphp
                        <td>@if($mstat)<span class="badge {{ in_array($mstat, \App\Models\TitleProgress::FINAL_STAGES, true) ? 'bg-success' : 'bg-info' }}">{{ $t->manuscriptStatusLabel() }}</span>@else<span class="text-muted">—</span>@endif</td>
                        <td>{{ $t->assignedMarketing?->name ?? 'Semua' }}</td>
                        <td>
                            <span class="badge {{ $sb[$t->status] ?? 'bg-secondary' }}">{{ $sl[$t->status] ?? $t->status }}</span>
                            @unless ($t->isActive())
                                <span class="badge bg-dark ms-1" title="Judul ini tidak muncul di dropdown Tambah Order">Nonaktif</span>
                            @endunless
                        </td>
                        <td><small>{{ $t->creator?->name ?? '—' }}</small></td>
                        <td>
                          <div class="title-actions">
                            <a href="{{ route('title.show', $t->id) }}" class="btn btn-xs btn-outline-primary">Lihat</a>

                            @if ($canManage && $t->isEditable() && (! $t->isApproved() || $canEditApproved))
                                @can('title.edit')
                                <a href="{{ route('title.edit', $t->id) }}" class="btn btn-xs btn-outline-secondary">Edit</a>
                                @endcan
                            @endif

                            @if ($canManage && in_array($t->status, ['draft', 'ditolak'], true))
                                @can('title.submit')
                                <form action="{{ route('title.submit', $t->id) }}" method="POST" class="d-inline m-0">@csrf<button class="btn btn-xs btn-outline-info">Ajukan</button></form>
                                @endcan
                            @endif

                            @if($isApprover && $t->status === 'menunggu')
                                @can('title.approve')
                                <form action="{{ route('title.approve', $t->id) }}" method="POST" class="d-inline m-0">@csrf<button class="btn btn-xs btn-outline-success">Setujui</button></form>
                                @endcan
                            @endif

                            @if ($canManage)
                                @can('title.deactivate')
                                    @if ($t->isActive())
                                        <form action="{{ route('title.deactivate', $t->id) }}" method="POST" class="d-inline m-0"
                                              onsubmit="return confirm('Nonaktifkan judul ini? Judul akan hilang dari dropdown Tambah Order, tapi laporan dan papan manuskrip tetap utuh.')">
                                            @csrf<button class="btn btn-xs btn-outline-warning">Nonaktifkan</button>
                                        </form>
                                    @else
                                        <form action="{{ route('title.activate', $t->id) }}" method="POST" class="d-inline m-0">
                                            @csrf<button class="btn btn-xs btn-outline-success">Aktifkan</button>
                                        </form>
                                    @endif
                                @endcan

                                @can('title.delete')
                                    @php $blocked = $t->deleteBlockReason(); @endphp
                                    @if ($blocked)
                                        {{-- Tombol SENGAJA tidak disembunyikan: user perlu tahu KENAPA judul
                                             tak bisa dihapus, dan langsung melihat Nonaktifkan sbg jalan keluarnya. --}}
                                        <button type="button" class="btn btn-xs btn-outline-danger disabled" tabindex="-1"
                                                title="{{ $blocked }} — tidak bisa dihapus">Hapus</button>
                                    @else
                                        <form action="{{ route('title.destroy', $t->id) }}" method="POST" class="d-inline m-0"
                                              onsubmit="return confirm('Hapus judul ini? Judul belum dipakai order, ISBN, maupun arsip.')">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-xs btn-outline-danger">Hapus</button>
                                        </form>
                                    @endif
                                @endcan
                            @endif
                          </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div></div></div></div>
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
<script src="{{ asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
<script src="{{ asset('assets/libs/datatables.net-responsive/js/dataTables.responsive.min.js') }}"></script>
<script src="{{ asset('assets/libs/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>$(function () { $('.datatable').DataTable({ pageLength: 10, responsive: true, order: [], language: { emptyTable: @json($showInactive ? 'Belum ada judul.' : 'Belum ada judul aktif.') } }); });</script>
@endpush
