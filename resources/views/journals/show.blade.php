@extends('layouts.master')
@section('title', 'Detail Jurnal - SiMAPA')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0">{{ $journal->nama }}</h5>
        <small class="text-muted">{{ $journal->akreditasi ?: 'Tanpa akreditasi' }} · {{ $journal->scope?->scope ?? 'Tanpa scope' }}</small>
    </div>
    <div class="d-flex gap-2">
        @if($canManage)<a href="{{ route('journal.edit', $journal->id) }}" class="btn btn-sm btn-outline-secondary">Edit</a>@endif
        <a href="{{ route('journal.index') }}" class="btn btn-sm btn-outline-secondary">Kembali</a>
    </div>
</div>

<div class="row"><div class="col-lg-8 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <dl class="row mb-0">
        <dt class="col-sm-4 text-muted small">Akreditasi</dt><dd class="col-sm-8">{{ $journal->akreditasi ?: '—' }}</dd>
        <dt class="col-sm-4 text-muted small">Scope</dt><dd class="col-sm-8">{{ $journal->scope?->scope ?? '—' }}</dd>
        <dt class="col-sm-4 text-muted small">Bulan Terbitan</dt><dd class="col-sm-8">@forelse($journal->terbitanLabels() as $m)<span class="badge bg-light text-dark border me-1">{{ $m }}</span>@empty—@endforelse</dd>
        <dt class="col-sm-4 text-muted small">APC Reguler</dt><dd class="col-sm-8">{{ $journal->apc_reguler ?: '—' }}</dd>
        <dt class="col-sm-4 text-muted small">APC Fastrack</dt><dd class="col-sm-8">{{ $journal->apc_fastrack ?: '—' }}</dd>
        <dt class="col-sm-4 text-muted small">Link</dt><dd class="col-sm-8">@if($journal->link)<a href="{{ $journal->link }}" target="_blank" rel="noopener">{{ $journal->link }}</a>@else—@endif</dd>
        <dt class="col-sm-4 text-muted small">Kontak Editor</dt><dd class="col-sm-8">WA: {{ $journal->kontak_wa ?: '—' }} · Email: {{ $journal->kontak_email ?: '—' }}</dd>
        <dt class="col-sm-4 text-muted small">Catatan</dt><dd class="col-sm-8">{{ $journal->catatan ?: '—' }}</dd>
    </dl>

    <hr class="my-3">
    @php $sb = ['submitted' => 'bg-secondary', 'loa' => 'bg-info', 'published' => 'bg-success']; @endphp
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="card-title mb-0">Artikel di Jurnal Ini</h6>
        @if($canManage)
            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#subCreate">+ Tambah Artikel Submit</button>
        @endif
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover">
            <thead><tr><th>Judul</th><th>Submit</th><th>Terbit</th><th>Status</th>@if($canManage)<th>Aksi</th>@endif</tr></thead>
            <tbody>
                @forelse($journal->submissions as $s)
                    <tr>
                        <td class="dt-judul">{{ $s->title?->title ?? '—' }}</td>
                        <td>{{ optional($s->tgl_submit)->format('d M Y') ?? '—' }}</td>
                        <td>{{ optional($s->tgl_terbit)->format('d M Y') ?? '—' }}</td>
                        <td><span class="badge {{ $sb[$s->status] ?? 'bg-secondary' }}">{{ $s->statusLabel() }}</span></td>
                        @if($canManage)
                            <td>
                                <button type="button" class="btn btn-xs btn-outline-primary" data-bs-toggle="modal" data-bs-target="#subDetail{{ $s->id }}">Detail</button>
                                <button type="button" class="btn btn-xs btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#subEdit{{ $s->id }}">Edit</button>
                                <form action="{{ route('journal.submission.destroy', $s->id) }}" method="POST" class="d-inline m-0" data-confirm="Hapus submission ini?">@csrf @method('DELETE')<button class="btn btn-xs btn-outline-danger">Hapus</button></form>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $canManage ? 5 : 4 }}" class="text-muted text-center py-3">Belum ada submission.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div></div></div></div>

@if($canManage)
    {{-- Modal Create --}}
    <div class="modal fade" id="subCreate" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content">
        <form method="POST" action="{{ route('journal.submission.store', $journal->id) }}" enctype="multipart/form-data">
            @csrf
            <div class="modal-header"><h6 class="modal-title">Tambah Artikel Submit</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                @include('journals.partials.submission-fields', ['s' => null])
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">Batal</button><button type="submit" class="btn btn-sm btn-primary">Simpan</button></div>
        </form>
    </div></div></div>

    {{-- Modal Edit + Detail per baris --}}
    @foreach($journal->submissions as $s)
        <div class="modal fade" id="subEdit{{ $s->id }}" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content">
            <form method="POST" action="{{ route('journal.submission.update', $s->id) }}" enctype="multipart/form-data">
                @csrf @method('PUT')
                <div class="modal-header"><h6 class="modal-title">Edit Submission</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    @include('journals.partials.submission-fields', ['s' => $s])
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">Batal</button><button type="submit" class="btn btn-sm btn-primary">Simpan</button></div>
            </form>
        </div></div></div>

        <div class="modal fade" id="subDetail{{ $s->id }}" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
            <div class="modal-header"><h6 class="modal-title">Detail Submission</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body small">
                <dl class="row mb-0">
                    <dt class="col-4 text-muted">Judul</dt><dd class="col-8">{{ $s->title?->title ?? '—' }}</dd>
                    <dt class="col-4 text-muted">Submit</dt><dd class="col-8">{{ optional($s->tgl_submit)->format('d M Y') ?? '—' }}</dd>
                    <dt class="col-4 text-muted">Terbit</dt><dd class="col-8">{{ optional($s->tgl_terbit)->format('d M Y') ?? '—' }}</dd>
                    <dt class="col-4 text-muted">Status</dt><dd class="col-8">{{ $s->statusLabel() }}</dd>
                    <dt class="col-4 text-muted">OJS Akun</dt><dd class="col-8">{{ $s->ojs_akun ?: '—' }}</dd>
                    <dt class="col-4 text-muted">OJS Password</dt><dd class="col-8">{{ $s->ojs_password ?: '—' }}</dd>
                    <dt class="col-4 text-muted">LoA</dt><dd class="col-8">@if($s->loa_url)<a href="{{ $s->loa_url }}" target="_blank" rel="noopener">buka</a>@else—@endif</dd>
                    <dt class="col-4 text-muted">Bukti Bayar</dt><dd class="col-8">@if($s->bukti_bayar_url)<a href="{{ $s->bukti_bayar_url }}" target="_blank" rel="noopener">buka</a>@else—@endif</dd>
                    <dt class="col-4 text-muted">Link Publish</dt><dd class="col-8">@if($s->link_publish)<a href="{{ $s->link_publish }}" target="_blank" rel="noopener">{{ $s->link_publish }}</a>@else—@endif</dd>
                    <dt class="col-4 text-muted">Catatan</dt><dd class="col-8">{{ $s->catatan ?: '—' }}</dd>
                </dl>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">Tutup</button></div>
        </div></div></div>
    @endforeach
@endif
@endsection

@if($canManage)
@push('plugin-styles')
<link href="{{ asset('assets/plugins/select2/select2.min.css') }}" rel="stylesheet" />
@endpush
@push('plugin-scripts')
<script src="{{ asset('assets/plugins/select2/select2.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
$(function () {
    if (!window.jQuery || !jQuery.fn.select2) return;
    // Init select2 judul artikel saat modal dibuka (dropdownParent = modal agar kotak cari fokus).
    document.querySelectorAll('.modal').forEach(function (modal) {
        modal.addEventListener('shown.bs.modal', function () {
            jQuery(modal).find('.select2-article').each(function () {
                if (!jQuery(this).hasClass('select2-hidden-accessible')) {
                    jQuery(this).select2({ width: '100%', dropdownParent: jQuery(modal), placeholder: 'Cari judul artikel…' });
                }
            });
        });
    });
});
</script>
@endpush
@endif
