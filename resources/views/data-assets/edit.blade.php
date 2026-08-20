@extends('layouts.master')
@section('title', 'Edit Data - Gudang Data - SiMAPA')
@section('content')
    <div class="mb-3">@include('partials.tombol-kembali', ['ke' => route('data.index')])</div>
<div class="row"><div class="col-lg-8">
<div class="card"><div class="card-body">
    <h5 class="mb-3">Edit Data</h5>
    <form method="POST" action="{{ route('data.update', $asset->id) }}" enctype="multipart/form-data">
        @csrf @method('PUT')
        @include('data-assets._form')
        <button class="btn btn-primary">Simpan</button>
        <a href="{{ route('data.index') }}" class="btn btn-outline-secondary">Batal</a>
    </form>
</div></div>
</div></div>
@endsection
