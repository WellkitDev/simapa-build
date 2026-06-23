@extends('layouts.master')
@section('title', ($announcement->exists ? 'Edit' : 'Buat') . ' Pengumuman - SiMAPA')

@push('plugin-styles')
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-lite.min.css" rel="stylesheet">
@endpush

@section('content')
<div class="row"><div class="col-md-10 offset-md-1 grid-margin stretch-card"><div class="card"><div class="card-body">
    <h6 class="card-title">{{ $announcement->exists ? 'Edit' : 'Buat' }} Pengumuman</h6>
    <form method="POST" action="{{ $announcement->exists ? route('announcement.update', $announcement->id) : route('announcement.store') }}">
        @csrf
        @if($announcement->exists) @method('PUT') @endif
        <div class="mb-3">
            <label class="form-label">Judul</label>
            <input type="text" name="title" class="form-control @error('title') is-invalid @enderror" value="{{ old('title', $announcement->title) }}" required>
            @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="mb-3">
            <label class="form-label">Isi</label>
            <textarea name="body" id="summernote">{{ old('body', $announcement->body) }}</textarea>
        </div>
        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="draft" {{ old('status', $announcement->status) === 'draft' ? 'selected' : '' }}>Draf</option>
                    <option value="published" {{ old('status', $announcement->status) === 'published' ? 'selected' : '' }}>Terbit</option>
                </select>
            </div>
            <div class="col-md-4 mb-3 d-flex align-items-end">
                <div class="form-check">
                    <input type="checkbox" name="is_pinned" value="1" class="form-check-input" id="pinChk" {{ old('is_pinned', $announcement->is_pinned) ? 'checked' : '' }}>
                    <label class="form-check-label" for="pinChk">Pin ke atas</label>
                </div>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Simpan</button>
        <a href="{{ route('announcement.index') }}" class="btn btn-secondary">Batal</a>
    </form>
</div></div></div></div>
@endsection

@push('plugin-scripts')
    <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-lite.min.js"></script>
@endpush
@push('custom-scripts')
<script>
    $(function () {
        $('#summernote').summernote({ height: 250, placeholder: 'Tulis isi pengumuman...' });
    });
</script>
@endpush
