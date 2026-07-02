@extends('layouts.master')
@section('title', ($journal->exists ? 'Edit' : 'Tambah') . ' Jurnal - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/plugins/select2/select2.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">{{ $journal->exists ? 'Edit' : 'Tambah' }} Jurnal</h5>
    <a href="{{ route('journal.index') }}" class="btn btn-sm btn-outline-secondary">Kembali</a>
</div>

<div class="row"><div class="col-lg-9 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <form method="POST" action="{{ $journal->exists ? route('journal.update', $journal->id) : route('journal.store') }}">
        @csrf
        @if($journal->exists) @method('PUT') @endif

        <div class="mb-3">
            <label class="form-label">Nama Jurnal <span class="text-danger">*</span></label>
            <input type="text" name="nama" class="form-control @error('nama') is-invalid @enderror" value="{{ old('nama', $journal->nama) }}" required>
            @error('nama') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Akreditasi</label>
                <select name="akreditasi" class="form-select select2-tags">
                    <option value="">— pilih / ketik —</option>
                    @foreach(\App\Models\Title::INDEKSASI as $ix)
                        <option value="{{ $ix }}" {{ old('akreditasi', $journal->akreditasi) === $ix ? 'selected' : '' }}>{{ $ix }}</option>
                    @endforeach
                    @if($journal->akreditasi && ! in_array($journal->akreditasi, \App\Models\Title::INDEKSASI, true))
                        <option value="{{ $journal->akreditasi }}" selected>{{ $journal->akreditasi }}</option>
                    @endif
                </select>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Scope / Bidang</label>
                <select name="scope_id" class="form-select select2-tags">
                    <option value="">— pilih / ketik —</option>
                    @foreach($scopes as $scope)
                        <option value="{{ $scope->id }}" {{ (string) old('scope_id', $journal->scope_id) === (string) $scope->id ? 'selected' : '' }}>{{ $scope->scope }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">APC Reguler</label>
                <input type="text" name="apc_reguler" class="form-control" value="{{ old('apc_reguler', $journal->apc_reguler) }}" placeholder="Rp / USD / Gratis">
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">APC Fastrack</label>
                <input type="text" name="apc_fastrack" class="form-control" value="{{ old('apc_fastrack', $journal->apc_fastrack) }}">
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">Link Jurnal</label>
            <input type="text" name="link" class="form-control" value="{{ old('link', $journal->link) }}" placeholder="https://…">
        </div>

        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Kontak Editor (WA)</label>
                <input type="text" name="kontak_wa" class="form-control" value="{{ old('kontak_wa', $journal->kontak_wa) }}">
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Kontak Editor (Email)</label>
                <input type="text" name="kontak_email" class="form-control" value="{{ old('kontak_email', $journal->kontak_email) }}">
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label d-block">Bulan Terbitan</label>
            @php $selMonths = collect(old('terbitan_bulan', $journal->terbitan_bulan ?? []))->map(fn($m) => (int) $m)->all(); @endphp
            <div class="d-flex flex-wrap gap-3">
                @foreach(\App\Models\Journal::MONTHS as $num => $label)
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="terbitan_bulan[]" value="{{ $num }}" id="bln{{ $num }}" {{ in_array($num, $selMonths, true) ? 'checked' : '' }}>
                        <label class="form-check-label" for="bln{{ $num }}">{{ $label }}</label>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">Catatan</label>
            <textarea name="catatan" class="form-control" rows="2">{{ old('catatan', $journal->catatan) }}</textarea>
        </div>

        <button type="submit" class="btn btn-primary">Simpan</button>
    </form>
</div></div></div></div>
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/plugins/select2/select2.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>$(function () { if (window.jQuery && jQuery.fn.select2) jQuery('.select2-tags').select2({ tags: true, width: '100%' }); });</script>
@endpush
