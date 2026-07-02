@extends('layouts.master')
@section('title', ($title->exists ? 'Edit' : 'Buat') . ' Judul - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/plugins/select2/select2.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">{{ $title->exists ? 'Edit' : 'Buat' }} Judul</h5>
    <a href="{{ route('title.index') }}" class="btn btn-sm btn-outline-secondary">Kembali</a>
</div>

<div class="row"><div class="col-md-8 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <form method="POST" action="{{ $title->exists ? route('title.update', $title->id) : route('title.store') }}">
        @csrf
        @if($title->exists) @method('PUT') @endif

        <div class="mb-3">
            <label class="form-label">Judul <span class="text-danger">*</span></label>
            <input type="text" name="title" class="form-control @error('title') is-invalid @enderror" value="{{ old('title', $title->title) }}" required>
            @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label">Jenis <span class="text-danger">*</span></label>
                <select name="jenis" id="jenis" class="form-select">
                    <option value="artikel" {{ old('jenis', $title->jenis) === 'artikel' ? 'selected' : '' }}>Artikel</option>
                    <option value="buku" {{ old('jenis', $title->jenis) === 'buku' ? 'selected' : '' }}>Buku</option>
                </select>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Tipe Naskah <span class="text-danger">*</span></label>
                <select name="tipe_naskah" class="form-select">
                    <option value="mandiri" {{ old('tipe_naskah', $title->tipe_naskah) === 'mandiri' ? 'selected' : '' }}>Mandiri</option>
                    <option value="kolaborasi" {{ old('tipe_naskah', $title->tipe_naskah) === 'kolaborasi' ? 'selected' : '' }}>Kolaborasi</option>
                </select>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Indeksasi</label>
                <select name="indeksasi" class="form-select select2-indeks">
                    <option value="">— pilih / ketik —</option>
                    @foreach(\App\Models\Title::INDEKSASI as $ix)
                        <option value="{{ $ix }}" {{ old('indeksasi', $title->indeksasi) === $ix ? 'selected' : '' }}>{{ $ix }}</option>
                    @endforeach
                    @if($title->indeksasi && ! in_array($title->indeksasi, \App\Models\Title::INDEKSASI, true))
                        <option value="{{ $title->indeksasi }}" selected>{{ $title->indeksasi }}</option>
                    @endif
                </select>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">Bidang Ilmu (Scope)</label>
            <select name="scope_id" class="form-select select2-scope" data-tags="true">
                <option value="">Tidak ada / opsional</option>
                @foreach($scopes as $scope)
                    <option value="{{ $scope->id }}" {{ (string) old('scope_id', $title->scope_id) === (string) $scope->id ? 'selected' : '' }}>{{ $scope->scope }}</option>
                @endforeach
            </select>
            <small class="text-muted">Pilih dari daftar atau ketik bidang ilmu baru.</small>
        </div>

        <div class="mb-3">
            <label class="form-label">Distribusi ke Marketing</label>
            <select name="assigned_to" class="form-select">
                <option value="">Semua marketing</option>
                @foreach($marketers as $m)
                    <option value="{{ $m->id }}" {{ (string) old('assigned_to', $title->assigned_to) === (string) $m->id ? 'selected' : '' }}>{{ $m->name }}</option>
                @endforeach
            </select>
            <small class="text-muted">Kosongkan (Semua) agar terlihat oleh seluruh marketing, atau tetapkan ke satu marketing.</small>
        </div>

        <div id="chaptersWrap" class="mb-3 {{ old('jenis', $title->jenis) === 'buku' ? '' : 'd-none' }}">
            <label class="form-label">Bab (judul per bab)</label>
            <div id="chaptersList">
                @php $existing = old('chapters', $title->exists ? $title->chapters->map(fn($c) => ['judul' => $c->judul])->all() : []); @endphp
                @forelse($existing as $i => $ch)
                    <div class="input-group mb-2" data-chapter-row>
                        <input type="text" name="chapters[{{ $i }}][judul]" class="form-control" value="{{ $ch['judul'] ?? '' }}" placeholder="Judul bab">
                        <button type="button" class="btn btn-outline-danger" data-remove-chapter>Hapus</button>
                    </div>
                @empty
                @endforelse
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary" id="addChapter">+ Tambah Bab</button>
        </div>

        <button type="submit" class="btn btn-primary">Simpan</button>
    </form>
</div></div></div></div>
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/plugins/select2/select2.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
$(function () {
    if (window.jQuery && jQuery.fn.select2) { jQuery('.select2-indeks, .select2-scope').select2({ tags: true, width: '100%' }); }

    var jenis = document.getElementById('jenis');
    var wrap = document.getElementById('chaptersWrap');
    if (jenis) jenis.addEventListener('change', function () { wrap.classList.toggle('d-none', this.value !== 'buku'); });

    var list = document.getElementById('chaptersList');
    var idx = list ? list.querySelectorAll('[data-chapter-row]').length : 0;
    var addBtn = document.getElementById('addChapter');
    if (addBtn) addBtn.addEventListener('click', function () {
        var row = document.createElement('div');
        row.className = 'input-group mb-2';
        row.setAttribute('data-chapter-row', '');
        row.innerHTML = '<input type="text" name="chapters[' + (idx++) + '][judul]" class="form-control" placeholder="Judul bab">'
            + '<button type="button" class="btn btn-outline-danger" data-remove-chapter>Hapus</button>';
        list.appendChild(row);
    });
    if (list) list.addEventListener('click', function (e) {
        var b = e.target.closest('[data-remove-chapter]');
        if (b) b.closest('[data-chapter-row]').remove();
    });
});
</script>
@endpush
