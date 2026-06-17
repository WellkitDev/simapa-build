@extends('layouts.master')
@section('title', ($tagihan ? 'Edit' : 'Buat') . ' Tagihan - SiMAPA')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-9">
        <div class="card">
            <div class="card-header bg-transparent border-bottom">
                <h5 class="mb-0">{{ $tagihan ? 'Edit Tagihan' : 'Buat Tagihan Baru' }}</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ $tagihan ? route('tagihan.update', $tagihan->id) : route('tagihan.store') }}">
                    @csrf
                    @if($tagihan) @method('PUT') @endif

                    <h6 class="text-muted">Data Klien</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Nama Klien <span class="text-danger">*</span></label>
                            <input type="text" name="client_name" class="form-control @error('client_name') is-invalid @enderror" value="{{ old('client_name', $tagihan->client_name ?? '') }}" required>
                            @error('client_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Institusi</label>
                            <input type="text" name="client_institution" class="form-control" value="{{ old('client_institution', $tagihan->client_institution ?? '') }}">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="client_email" class="form-control" value="{{ old('client_email', $tagihan->client_email ?? '') }}">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Telepon</label>
                            <input type="text" name="client_phone" class="form-control" value="{{ old('client_phone', $tagihan->client_phone ?? '') }}">
                        </div>
                    </div>

                    <h6 class="text-muted mt-2">Data Naskah & Tagihan</h6>
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Judul <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control @error('title') is-invalid @enderror" value="{{ old('title', $tagihan->title ?? '') }}" required>
                            @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Tipe <span class="text-danger">*</span></label>
                            <select name="type" class="form-select" required>
                                @foreach(['buku' => 'Buku', 'jurnal' => 'Jurnal'] as $val => $lbl)
                                    <option value="{{ $val }}" {{ old('type', $tagihan->type ?? 'buku') === $val ? 'selected' : '' }}>{{ $lbl }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Author</label>
                            <input type="text" name="author_names" class="form-control" placeholder="pisahkan dengan koma" value="{{ old('author_names', $tagihan->author_names ?? '') }}">
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Deskripsi / Rincian</label>
                            <textarea name="description" class="form-control" rows="2">{{ old('description', $tagihan->description ?? '') }}</textarea>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Nominal (Rp) <span class="text-danger">*</span></label>
                            <input type="number" name="amount" class="form-control @error('amount') is-invalid @enderror" min="0" value="{{ old('amount', $tagihan->amount ?? '') }}" required>
                            @error('amount') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Jatuh Tempo</label>
                            <input type="date" name="due_at" class="form-control" value="{{ old('due_at', optional($tagihan->due_at ?? null)->toDateString()) }}">
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Catatan</label>
                            <textarea name="note" class="form-control" rows="2">{{ old('note', $tagihan->note ?? '') }}</textarea>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">{{ $tagihan ? 'Simpan Perubahan' : 'Simpan & Ajukan' }}</button>
                        <a href="{{ route('tagihan.index') }}" class="btn btn-outline-secondary">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
