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
    <h6 class="card-title">Artikel di Jurnal Ini</h6>
    <p class="text-muted small mb-0">Daftar artikel yang di-submit/terbit ke jurnal ini akan hadir di fase berikutnya (tracking submit artikel).</p>
</div></div></div></div>
@endsection
