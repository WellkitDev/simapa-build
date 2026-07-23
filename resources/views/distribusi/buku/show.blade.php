@extends('layouts.master')
@section('title', 'Distribusi Buku - SiMAPA')
@section('content')
<div class="card mb-3"><div class="card-body">
    <h3 class="mb-1">{{ $title->title }}</h3>
    <span class="badge bg-dark">{{ $title->code ?? '—' }}</span>
    <span class="badge bg-info">Buku</span>
    <span class="badge bg-light text-dark">{{ ucfirst($title->tipe_naskah) }}</span>
    <span class="badge bg-secondary">{{ $title->manuscriptStatusLabel() ?? '—' }}</span>
</div></div>

{{-- Pintasan level buku --}}
<div class="card mb-3"><div class="card-header bg-transparent border-bottom"><h5 class="mb-0">Pintasan Seluruh Buku</h5></div>
<div class="card-body"><div class="row g-3">
    <div class="col-md-4">
        <form method="POST" action="{{ route('distribusi.buku.editorSemua', $title->id) }}">@csrf
            <label class="form-label form-label-sm">Terapkan 1 Editor ke Semua Bab</label>
            <div class="d-flex gap-1">
                <select name="assigned_user_id" class="form-select form-select-sm">
                    <option value="">— Belum —</option>
                    @foreach ($editors as $ed)<option value="{{ $ed->id }}">{{ $ed->name }}</option>@endforeach
                </select>
                <button class="btn btn-sm btn-primary">Terapkan</button>
            </div>
        </form>
    </div>
    <div class="col-md-4">
        <form method="POST" action="{{ route('distribusi.buku.target', $title->id) }}">@csrf
            <label class="form-label form-label-sm">Target Terbit</label>
            <div class="d-flex gap-1">
                <input type="date" name="target_date" class="form-control form-control-sm">
                <button class="btn btn-sm btn-primary">Simpan</button>
            </div>
        </form>
    </div>
    <div class="col-md-4">
        <label class="form-label form-label-sm">File Naskah (level buku)</label>
        @include('distribusi.partials.file-slot', ['uploadRoute' => route('distribusi.buku.file', $title->id), 'files' => $filesFor(null)])
    </div>
</div></div></div>

{{-- Grid per bab --}}
<div class="card"><div class="card-header bg-transparent border-bottom"><h5 class="mb-0">Distribusi per Bab</h5></div>
<div class="card-body">
    @foreach ($title->chapters as $ch)
        @php $cp = $ch->progress; @endphp
        <div class="border rounded p-3 mb-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <strong>{{ $ch->urutan }}. {{ $ch->judul }}</strong>
                <span class="badge bg-info">{{ \App\Models\Title::stageLabel(optional($cp)->status) }}</span>
            </div>
            <div class="text-muted mb-2" style="font-size:12px">Author: {{ $ch->authors->pluck('name')->join(', ') ?: '—' }}</div>
            @if ($cp)
            <div class="row g-2">
                <div class="col-md-4">
                    <form method="POST" action="{{ route('distribusi.buku.chapter.editor', $cp->id) }}">@csrf
                        <label class="form-label form-label-sm">Editor bab</label>
                        <div class="d-flex gap-1">
                            <select name="assigned_user_id" class="form-select form-select-sm">
                                <option value="">— Belum —</option>
                                @foreach ($editors as $ed)<option value="{{ $ed->id }}" {{ $cp->assigned_user_id == $ed->id ? 'selected' : '' }}>{{ $ed->name }}</option>@endforeach
                            </select>
                            <button class="btn btn-sm btn-primary">Simpan</button>
                        </div>
                    </form>
                </div>
                <div class="col-md-4">
                    <form method="POST" action="{{ route('distribusi.buku.chapter.tahap', $cp->id) }}">@csrf
                        <label class="form-label form-label-sm">Tahap bab</label>
                        <div class="d-flex gap-1">
                            <select name="status" class="form-select form-select-sm">
                                @foreach (\App\Models\TitleProgress::BOOK_STAGES as $s)
                                    <option value="{{ $s }}" {{ $s === $cp->status ? 'selected' : '' }}>{{ \App\Models\Title::stageLabel($s) }}</option>
                                @endforeach
                            </select>
                            <button class="btn btn-sm btn-primary">Simpan</button>
                        </div>
                        <input type="text" name="note" class="form-control form-control-sm mt-1" placeholder="Catatan (opsional)">
                    </form>
                </div>
                <div class="col-md-4">
                    <label class="form-label form-label-sm">File bab</label>
                    @include('distribusi.partials.file-slot', ['uploadRoute' => route('distribusi.buku.chapter.file', $cp->id), 'files' => $filesFor($ch->id)])
                </div>
            </div>
            @endif
        </div>
    @endforeach
</div></div>
@endsection
