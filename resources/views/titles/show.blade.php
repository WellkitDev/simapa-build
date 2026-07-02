@extends('layouts.master')
@section('title', 'Detail Judul - SiMAPA')

@section('content')
@php
    $sb = ['draft' => 'bg-secondary', 'menunggu' => 'bg-warning text-dark', 'disetujui' => 'bg-success', 'ditolak' => 'bg-danger'];
    $sl = ['draft' => 'Draf', 'menunggu' => 'Menunggu', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak'];
@endphp
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0">@if($title->code)<span class="badge bg-dark align-middle me-1">{{ $title->code }}</span>@endif {{ $title->title }}</h5>
        <small class="text-muted">{{ ucfirst($title->jenis) }} · {{ ucfirst($title->tipe_naskah) }} · {{ $title->scope?->scope ?? 'Tanpa bidang ilmu' }} · {{ $title->indeksasi ?: 'Tanpa indeksasi' }}</small>
    </div>
    <a href="{{ route('title.index') }}" class="btn btn-sm btn-outline-secondary">Kembali</a>
</div>

<div class="row"><div class="col-md-8 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <p class="mb-2">Status: <span class="badge {{ $sb[$title->status] ?? 'bg-secondary' }}">{{ $sl[$title->status] ?? $title->status }}</span></p>
    <p class="mb-2"><small class="text-muted">Dibuat oleh {{ $title->creator?->name ?? '—' }}@if($title->approver) · disetujui {{ $title->approver->name }}@endif</small></p>
    <p class="mb-2">Distribusi: <span class="badge bg-light text-dark border">{{ $title->assignedMarketing?->name ?? 'Semua marketing' }}</span></p>
    <p class="mb-2">Order tertaut: <strong>Jml Order</strong> {{ $ordersCount }} · <strong>Jml Author</strong> {{ $authorsCount }}</p>
    @php $mstat = $title->manuscriptStatus(); @endphp
    <p class="mb-2">Manuskrip:
        @if($mstat)<span class="badge {{ in_array($mstat, \App\Models\TitleProgress::FINAL_STAGES, true) ? 'bg-success' : 'bg-info' }}">{{ $title->manuscriptStatusLabel() }}</span>@else<span class="text-muted">Belum ada order</span>@endif
        @if($canOpenBoard)
            <a href="{{ route('manuscript.board', ['tipe' => $title->jenis === 'buku' ? 'buku' : 'artikel']) }}" class="btn btn-xs btn-outline-secondary ms-2">Buka Papan Manuskrip</a>
        @endif
    </p>
    @if($title->orderDetails->isNotEmpty())
        <h6 class="card-title mt-3">Order Tertaut</h6>
        <div class="table-responsive">
            <table class="table table-sm table-borderless mb-3">
                <thead><tr><th>Kode Order</th><th>Marketing</th><th>Tanggal</th><th>Manuskrip</th></tr></thead>
                <tbody>
                    @foreach($title->orderDetails as $od)
                        <tr>
                            <td>{{ $od->order?->code_order ?? '—' }}</td>
                            <td>{{ $od->order?->user?->name ?? '—' }}</td>
                            <td>{{ optional($od->order?->ordered_at)->format('d M Y') ?? '—' }}</td>
                            <td>{{ \App\Models\Title::stageLabel(optional($od->titleProgress)->status) ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    @if($title->status === 'ditolak' && $title->reject_note)
        <div class="alert alert-danger py-2"><strong>Ditolak:</strong> {{ $title->reject_note }}</div>
    @endif

    @if($title->jenis === 'buku')
        <h6 class="card-title mt-3">Bab</h6>
        <ol class="mb-3">
            @forelse($title->chapters as $ch)
                <li>{{ $ch->judul }}</li>
            @empty
                <li class="text-muted">Belum ada bab.</li>
            @endforelse
        </ol>
    @endif

    <div class="d-flex gap-2 flex-wrap">
        @if($canManage && $title->isEditable())
            <a href="{{ route('title.edit', $title->id) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
            <form action="{{ route('title.submit', $title->id) }}" method="POST" class="m-0">@csrf<button class="btn btn-sm btn-info">Ajukan</button></form>
        @endif
        @if($isApprover && $title->status === 'menunggu')
            <form action="{{ route('title.approve', $title->id) }}" method="POST" class="m-0">@csrf<button class="btn btn-sm btn-success">Setujui</button></form>
            <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="collapse" data-bs-target="#rejectForm">Tolak</button>
        @endif
    </div>

    @if($isApprover && $title->status === 'menunggu')
        <div class="collapse mt-2" id="rejectForm">
            <form action="{{ route('title.reject', $title->id) }}" method="POST">@csrf
                <textarea name="reject_note" class="form-control mb-2" rows="2" placeholder="Alasan penolakan" required></textarea>
                <button class="btn btn-sm btn-danger">Kirim Penolakan</button>
            </form>
        </div>
    @endif
</div></div></div></div>

@if($canViewInfo)
<div class="row"><div class="col-md-8 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="card-title mb-0">Informasi Publikasi</h6>
        @if($canEditInfo)
            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#infoForm">Edit Informasi</button>
        @endif
    </div>

    <dl class="row mb-2">
        <dt class="col-sm-4 text-muted small">Kode</dt><dd class="col-sm-8">{{ $title->code ?? '—' }}</dd>
        <dt class="col-sm-4 text-muted small">Target Terbit</dt><dd class="col-sm-8">{{ optional($title->target_terbit)->format('d M Y') ?? '—' }}</dd>
        <dt class="col-sm-4 text-muted small">Jurnal Target</dt><dd class="col-sm-8">{{ $title->jurnal_target ?? '—' }}@if($title->jurnal_link) · <a href="{{ $title->jurnal_link }}" target="_blank" rel="noopener">link</a>@endif</dd>
        <dt class="col-sm-4 text-muted small">Template Artikel</dt><dd class="col-sm-8">@if($title->template_link)<a href="{{ $title->template_link }}" target="_blank" rel="noopener">{{ $title->template_link }}</a>@else — @endif</dd>
        <dt class="col-sm-4 text-muted small">APC</dt><dd class="col-sm-8">{{ $title->apc_info ?? '—' }}</dd>
        <dt class="col-sm-4 text-muted small">Catatan</dt><dd class="col-sm-8">{{ $title->catatan_publikasi ?? '—' }}</dd>
    </dl>

    <h6 class="text-muted small mt-2">Opsi Jurnal Lain</h6>
    @forelse($title->journalOptions as $opt)
        <div class="small mb-1">• {{ $opt->nama_jurnal }}@if($opt->link) · <a href="{{ $opt->link }}" target="_blank" rel="noopener">link</a>@endif @if($opt->apc)· APC: {{ $opt->apc }}@endif</div>
    @empty
        <div class="small text-muted mb-1">Belum ada opsi jurnal.</div>
    @endforelse

    @if($canEditInfo)
    <div class="collapse mt-3" id="infoForm">
        <form method="POST" action="{{ route('title.info.update', $title->id) }}">
            @csrf @method('PUT')
            <div class="row">
                <div class="col-md-4 mb-2">
                    <label class="form-label">Kode</label>
                    <input type="text" name="code" class="form-control @error('code') is-invalid @enderror" value="{{ old('code', $title->code) }}" maxlength="16">
                    @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <small class="text-muted">Kosongkan untuk buat ulang dari judul.</small>
                </div>
                <div class="col-md-4 mb-2">
                    <label class="form-label">Target Terbit</label>
                    <input type="text" name="target_terbit" class="form-control flatpickr-date" value="{{ old('target_terbit', optional($title->target_terbit)->format('Y-m-d')) }}" placeholder="YYYY-MM-DD">
                </div>
                <div class="col-md-4 mb-2">
                    <label class="form-label">APC</label>
                    <input type="text" name="apc_info" class="form-control" value="{{ old('apc_info', $title->apc_info) }}">
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-2">
                    <label class="form-label">Jurnal Target</label>
                    <input type="text" name="jurnal_target" class="form-control" value="{{ old('jurnal_target', $title->jurnal_target) }}">
                </div>
                <div class="col-md-6 mb-2">
                    <label class="form-label">Link Jurnal</label>
                    <input type="text" name="jurnal_link" class="form-control" value="{{ old('jurnal_link', $title->jurnal_link) }}">
                </div>
            </div>
            <div class="mb-2">
                <label class="form-label">Link Template Artikel</label>
                <input type="text" name="template_link" class="form-control" value="{{ old('template_link', $title->template_link) }}">
            </div>
            <div class="mb-2">
                <label class="form-label">Catatan</label>
                <textarea name="catatan_publikasi" class="form-control" rows="2">{{ old('catatan_publikasi', $title->catatan_publikasi) }}</textarea>
            </div>

            <label class="form-label">Opsi Jurnal Lain</label>
            <div id="joList">
                @foreach($title->journalOptions as $i => $opt)
                    <div class="row g-1 mb-1" data-jo-row>
                        <div class="col-md-5"><input type="text" name="journal_options[{{ $i }}][nama_jurnal]" class="form-control form-control-sm" value="{{ $opt->nama_jurnal }}" placeholder="Nama jurnal"></div>
                        <div class="col-md-4"><input type="text" name="journal_options[{{ $i }}][link]" class="form-control form-control-sm" value="{{ $opt->link }}" placeholder="Link"></div>
                        <div class="col-md-2"><input type="text" name="journal_options[{{ $i }}][apc]" class="form-control form-control-sm" value="{{ $opt->apc }}" placeholder="APC"></div>
                        <div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger w-100" data-jo-remove>×</button></div>
                    </div>
                @endforeach
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary mb-3" id="joAdd">+ Opsi Jurnal</button>

            <div><button type="submit" class="btn btn-sm btn-primary">Simpan Informasi</button></div>
        </form>
    </div>
    @endif

    @if($title->logs->isNotEmpty())
        <h6 class="text-muted small mt-3">Riwayat Perubahan</h6>
        <ul class="list-unstyled small mb-0">
            @foreach($title->logs->take(10) as $log)
                <li class="mb-1">• {{ $log->note }} — <span class="text-muted">{{ $log->changedBy?->name ?? '—' }}, {{ optional($log->created_at)->format('d M Y H:i') }}</span></li>
            @endforeach
        </ul>
    @endif
</div></div></div></div>
@endif
@endsection

@push('plugin-styles')
<link href="{{ asset('assets/plugins/flatpickr/flatpickr.min.css') }}" rel="stylesheet" />
@endpush
@push('plugin-scripts')
<script src="{{ asset('assets/plugins/flatpickr/flatpickr.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
$(function () {
    if (window.flatpickr) { flatpickr('.flatpickr-date', { dateFormat: 'Y-m-d', allowInput: true }); }
    var list = document.getElementById('joList');
    var addBtn = document.getElementById('joAdd');
    var idx = list ? list.querySelectorAll('[data-jo-row]').length : 0;
    if (addBtn) addBtn.addEventListener('click', function () {
        var row = document.createElement('div');
        row.className = 'row g-1 mb-1';
        row.setAttribute('data-jo-row', '');
        row.innerHTML = '<div class="col-md-5"><input type="text" name="journal_options[' + idx + '][nama_jurnal]" class="form-control form-control-sm" placeholder="Nama jurnal"></div>'
            + '<div class="col-md-4"><input type="text" name="journal_options[' + idx + '][link]" class="form-control form-control-sm" placeholder="Link"></div>'
            + '<div class="col-md-2"><input type="text" name="journal_options[' + idx + '][apc]" class="form-control form-control-sm" placeholder="APC"></div>'
            + '<div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger w-100" data-jo-remove>×</button></div>';
        list.appendChild(row); idx++;
    });
    if (list) list.addEventListener('click', function (e) {
        var b = e.target.closest('[data-jo-remove]');
        if (b) b.closest('[data-jo-row]').remove();
    });
});
</script>
@endpush
