@extends('layouts.master')
@section('title', '403 — Akses Ditolak - SiMAPA')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-7 col-lg-6 grid-margin stretch-card">
        <div class="card"><div class="card-body text-center py-5">
            <i data-feather="lock" class="text-danger mb-3" style="width:56px;height:56px"></i>
            <h3 class="mb-2">403 — Akses Ditolak</h3>
            <p class="text-muted mb-4">
                Anda tidak berwenang mengakses fitur ini. Bila menurut Anda ini keliru,
                minta administrator memberi hak akses yang sesuai lewat menu <strong>Hak Akses</strong>.
            </p>
            <div class="d-flex justify-content-center" style="gap:.5rem">
                <a href="javascript:history.back()" class="btn btn-outline-secondary btn-sm">&larr; Kembali</a>
                <a href="{{ route('dashboard') }}" class="btn btn-primary btn-sm">Ke Dashboard</a>
            </div>
        </div></div>
    </div>
</div>
@endsection
