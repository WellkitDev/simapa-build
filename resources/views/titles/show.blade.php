@extends('layouts.master')
@section('title', 'Detail Judul - SiMAPA')

@section('content')
@php
    $sb = ['draft' => 'bg-secondary', 'menunggu' => 'bg-warning text-dark', 'disetujui' => 'bg-success', 'ditolak' => 'bg-danger'];
    $sl = ['draft' => 'Draf', 'menunggu' => 'Menunggu', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak'];
@endphp
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0">{{ $title->title }}</h5>
        <small class="text-muted">{{ ucfirst($title->jenis) }} · {{ ucfirst($title->tipe_naskah) }} · {{ $title->scope?->scope ?? 'Tanpa bidang ilmu' }} · {{ $title->indeksasi ?: 'Tanpa indeksasi' }}</small>
    </div>
    <a href="{{ route('title.index') }}" class="btn btn-sm btn-outline-secondary">Kembali</a>
</div>

<div class="row"><div class="col-md-8 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <p class="mb-2">Status: <span class="badge {{ $sb[$title->status] ?? 'bg-secondary' }}">{{ $sl[$title->status] ?? $title->status }}</span></p>
    <p class="mb-2"><small class="text-muted">Dibuat oleh {{ $title->creator?->name ?? '—' }}@if($title->approver) · disetujui {{ $title->approver->name }}@endif</small></p>
    <p class="mb-2">Distribusi: <span class="badge bg-light text-dark border">{{ $title->assignedMarketing?->name ?? 'Semua marketing' }}</span></p>
    @if($title->status === 'ditolak' && $title->reject_note)
        <div class="alert alert-danger py-2"><strong>Ditolak:</strong> {{ $title->reject_note }}</div>
    @endif

    @if($title->jenis === 'buku')
        <h6 class="card-title mt-3">Bab</h6>
        <ol class="mb-3">
            @forelse($title->chapters as $ch)
                <li>{{ $ch->judul }}</li>
            @empty
                <li class="text-muted">Belum ada bab.</li>
            @endforelse
        </ol>
    @endif

    <div class="d-flex gap-2 flex-wrap">
        @if($canManage && $title->isEditable())
            <a href="{{ route('title.edit', $title->id) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
            <form action="{{ route('title.submit', $title->id) }}" method="POST" class="m-0">@csrf<button class="btn btn-sm btn-info">Ajukan</button></form>
        @endif
        @if($isApprover && $title->status === 'menunggu')
            <form action="{{ route('title.approve', $title->id) }}" method="POST" class="m-0">@csrf<button class="btn btn-sm btn-success">Setujui</button></form>
            <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="collapse" data-bs-target="#rejectForm">Tolak</button>
        @endif
    </div>

    @if($isApprover && $title->status === 'menunggu')
        <div class="collapse mt-2" id="rejectForm">
            <form action="{{ route('title.reject', $title->id) }}" method="POST">@csrf
                <textarea name="reject_note" class="form-control mb-2" rows="2" placeholder="Alasan penolakan" required></textarea>
                <button class="btn btn-sm btn-danger">Kirim Penolakan</button>
            </form>
        </div>
    @endif
</div></div></div></div>
@endsection
