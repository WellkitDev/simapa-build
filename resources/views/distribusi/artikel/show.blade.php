@extends('layouts.master')
@section('title', 'Distribusi Artikel - SiMAPA')
@section('content')
@php
    $p = $progresses->first();
    $stages = $p ? $p->getStages() : [];
@endphp
<div class="card mb-3"><div class="card-body">
    <h3 class="mb-1">{{ $title->title }}</h3>
    <span class="badge bg-info">Artikel</span>
    <span class="badge bg-secondary">{{ \App\Models\Title::stageLabel(optional($p)->status) ?? '—' }}</span>
</div></div>

<div class="card mb-3"><div class="card-header bg-transparent border-bottom"><h5 class="mb-0">Kontrol Naskah</h5></div>
<div class="card-body"><div class="row g-3">
    <div class="col-md-4">
        <form method="POST" action="{{ route('distribusi.artikel.editor', $title->id) }}">@csrf
            <label class="form-label form-label-sm">Editor / PIC</label>
            <div class="d-flex gap-1">
                <select name="assigned_user_id" class="form-select form-select-sm">
                    <option value="">— Belum —</option>
                    @foreach ($editors as $ed)
                        <option value="{{ $ed->id }}" {{ optional(optional($p)->assignedUser)->id == $ed->id ? 'selected' : '' }}>{{ $ed->name }}</option>
                    @endforeach
                </select>
                <button class="btn btn-sm btn-primary">Simpan</button>
            </div>
        </form>
    </div>
    <div class="col-md-4">
        <form method="POST" action="{{ route('distribusi.artikel.tahap', $title->id) }}">@csrf
            <label class="form-label form-label-sm">Ubah Tahap</label>
            <div class="d-flex gap-1">
                <select name="status" class="form-select form-select-sm">
                    @foreach ($stages as $s)
                        <option value="{{ $s }}" {{ $s === optional($p)->status ? 'selected' : '' }}>{{ \App\Models\Title::stageLabel($s) }}</option>
                    @endforeach
                </select>
                <button class="btn btn-sm btn-primary">Simpan</button>
            </div>
            <input type="text" name="note" class="form-control form-control-sm mt-1" placeholder="Catatan (opsional)">
        </form>
    </div>
    <div class="col-md-4">
        <form method="POST" action="{{ route('distribusi.artikel.target', $title->id) }}">@csrf
            <label class="form-label form-label-sm">Target Publish</label>
            <div class="d-flex gap-1">
                <input type="date" name="target_date" class="form-control form-control-sm" value="{{ optional(optional($p)->target_date)->format('Y-m-d') }}">
                <button class="btn btn-sm btn-primary">Simpan</button>
            </div>
        </form>
    </div>
    <div class="col-md-6">
        <label class="form-label form-label-sm">File Naskah</label>
        @include('distribusi.partials.file-slot', ['uploadRoute' => route('distribusi.artikel.file', $title->id), 'files' => $files])
    </div>
</div></div></div>
@endsection
