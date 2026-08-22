@extends('layouts.master')
@section('title', ($title->exists ? 'Edit' : 'Buat') . ' Judul - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/plugins/select2/select2.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0">{{ $title->exists ? 'Edit' : 'Buat' }} Judul</h5>
        <small class="text-muted">Lengkapi detail judul untuk didistribusikan ke marketing.</small>
    </div>
    <a href="{{ route('title.index') }}" class="btn btn-sm btn-outline-secondary">Kembali</a>
</div>

<form method="POST" action="{{ $title->exists ? route('title.update', $title->id) : route('title.store') }}">
    @csrf
    @if($title->exists) @method('PUT') @endif

    <div class="row">
        {{-- Kolom utama --}}
        <div class="col-lg-8 col-12 grid-margin stretch-card">
            <div class="card">
                <div class="card-body">
                    {{-- Informasi Judul --}}
                    <h6 class="card-title mb-1">Informasi Judul</h6>
                    <p class="text-muted small mb-3">Judul karya dan jenis naskahnya.</p>

                    <div class="mb-3">
                        <label class="form-label">Judul <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control @error('title') is-invalid @enderror" value="{{ old('title', $title->title) }}" placeholder="Masukkan judul artikel / buku" required>
                        @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Jenis <span class="text-danger">*</span></label>
                            <select name="jenis" id="jenis" class="form-select">
                                <option value="artikel" {{ old('jenis', $title->jenis) === 'artikel' ? 'selected' : '' }}>Artikel</option>
                                <option value="buku" {{ old('jenis', $title->jenis) === 'buku' ? 'selected' : '' }}>Buku</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Tipe Naskah <span class="text-danger">*</span></label>
                            <select name="tipe_naskah" class="form-select">
                                <option value="mandiri" {{ old('tipe_naskah', $title->tipe_naskah) === 'mandiri' ? 'selected' : '' }}>Mandiri</option>
                                <option value="kolaborasi" {{ old('tipe_naskah', $title->tipe_naskah) === 'kolaborasi' ? 'selected' : '' }}>Kolaborasi</option>
                            </select>
                        </div>
                    </div>

                    <hr class="my-3">

                    {{-- Klasifikasi --}}
                    <h6 class="card-title mb-1">Klasifikasi</h6>
                    <p class="text-muted small mb-3">Bidang ilmu dan target indeksasi.</p>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Bidang Ilmu (Scope)</label>
                            <select name="scope_id" class="form-select select2-scope" data-tags="true">
                                <option value="">Tidak ada / opsional</option>
                                @foreach($scopes as $scope)
                                    <option value="{{ $scope->id }}" {{ (string) old('scope_id', $title->scope_id) === (string) $scope->id ? 'selected' : '' }}>{{ $scope->scope }}</option>
                                @endforeach
                            </select>
                            <small class="text-muted">Pilih dari daftar atau ketik bidang ilmu baru.</small>
                        </div>
                        <div class="col-md-6 mb-3">
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
                            <small class="text-muted">Mis. SINTA, Scopus, atau ketik nilai lain.</small>
                        </div>
                    </div>

                    {{-- Bab (buku) --}}
                    <div id="chaptersWrap" class="{{ old('jenis', $title->jenis) === 'buku' ? '' : 'd-none' }}">
                        <hr class="my-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <h6 class="card-title mb-0">Daftar Bab</h6>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="addChapter">+ Tambah Bab</button>
                        </div>
                        <p class="text-muted small mb-3">Rincikan judul tiap bab hingga selesai (khusus buku).</p>
                        <div id="chaptersList">
                            {{-- `id` ikut dikirim supaya bab dicocokkan berdasarkan
                                 identitasnya, bukan posisinya. Tanpa itu, menghapus bab
                                 di TENGAH membuat sisa bab mewarisi judul tetangganya —
                                 dan kemajuan bab 4 mendarat di bawah label "Bab 5". --}}
                            @php $existing = old('chapters', $title->exists ? $title->chapters->map(fn($c) => ['id' => $c->id, 'judul' => $c->judul])->all() : []); @endphp
                            @forelse($existing as $i => $ch)
                                <div class="input-group mb-2" data-chapter-row>
                                    <span class="input-group-text">Bab</span>
                                    @if (! empty($ch['id']))
                                        <input type="hidden" name="chapters[{{ $i }}][id]" value="{{ $ch['id'] }}">
                                    @endif
                                    <input type="text" name="chapters[{{ $i }}][judul]" class="form-control" value="{{ $ch['judul'] ?? '' }}" placeholder="Judul bab">
                                    <button type="button" class="btn btn-outline-danger" data-remove-chapter>Hapus</button>
                                </div>
                            @empty
                            @endforelse
                        </div>
                        <p class="text-muted small mb-0" id="chaptersEmpty" style="{{ count($existing) ? 'display:none' : '' }}">Belum ada bab. Klik “Tambah Bab”.</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Kolom samping: Distribusi --}}
        <div class="col-lg-4 col-12 grid-margin stretch-card">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-title mb-1">Distribusi</h6>
                    <p class="text-muted small mb-3">Penerima judul di tim marketing.</p>

                    <div class="mb-2">
                        <label class="form-label">Distribusi ke Marketing</label>
                        <select name="assigned_to" class="form-select">
                            <option value="">Semua marketing</option>
                            @foreach($marketers as $m)
                                <option value="{{ $m->id }}" {{ (string) old('assigned_to', $title->assigned_to) === (string) $m->id ? 'selected' : '' }}>{{ $m->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="alert alert-secondary py-2 px-3 small mb-0" role="alert">
                        Kosongkan (<strong>Semua marketing</strong>) agar terlihat oleh seluruh marketing, atau tetapkan ke satu marketing tertentu.
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Footer aksi --}}
    <div class="d-flex gap-2 mb-4">
        <button type="submit" class="btn btn-primary px-4">Simpan</button>
        <a href="{{ route('title.index') }}" class="btn btn-outline-secondary">Batal</a>
    </div>
</form>
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
    var empty = document.getElementById('chaptersEmpty');
    var idx = list ? list.querySelectorAll('[data-chapter-row]').length : 0;

    function refreshEmpty() {
        if (!empty || !list) return;
        empty.style.display = list.querySelectorAll('[data-chapter-row]').length ? 'none' : '';
    }

    var addBtn = document.getElementById('addChapter');
    if (addBtn) addBtn.addEventListener('click', function () {
        var row = document.createElement('div');
        row.className = 'input-group mb-2';
        row.setAttribute('data-chapter-row', '');
        row.innerHTML = '<span class="input-group-text">Bab</span>'
            + '<input type="text" name="chapters[' + (idx++) + '][judul]" class="form-control" placeholder="Judul bab">'
            + '<button type="button" class="btn btn-outline-danger" data-remove-chapter>Hapus</button>';
        list.appendChild(row);
        refreshEmpty();
    });
    if (list) list.addEventListener('click', function (e) {
        var b = e.target.closest('[data-remove-chapter]');
        if (b) { b.closest('[data-chapter-row]').remove(); refreshEmpty(); }
    });
});
</script>
@endpush
