@extends('layouts.master')
@section('title', 'Detail Tagihan - SiMAPA')

@section('content')
@php
    $statusColors = [
        'diajukan' => 'secondary', 'disetujui' => 'success', 'ditolak' => 'danger',
        'jadi_order' => 'primary', 'dibatalkan' => 'dark',
    ];
    $isSuperadmin = auth()->user()->hasRole('superadmin');
    $isOwner = $tagihan->created_by === auth()->id();
@endphp

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h5 class="mb-0">Detail Tagihan</h5>
        </div>
        <div>
            <a href="{{ route('tagihan.index') }}" class="btn btn-sm btn-outline-secondary">Kembali</a>
        </div>
    </div>

<div class="row">
    <div class="col-md-8">
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <h5 class="mb-0">{{ $tagihan->tagihan_no }}</h5>
                    <span class="badge bg-{{ $statusColors[$tagihan->status] ?? 'secondary' }}">{{ Str::title(str_replace('_',' ',$tagihan->status)) }}</span>
                </div>
                <hr>
                <dl class="row mb-0">
                    <dt class="col-sm-3">Klien</dt><dd class="col-sm-9">{{ $tagihan->client_name }} @if($tagihan->client_institution) — {{ $tagihan->client_institution }} @endif</dd>
                    <dt class="col-sm-3">Kontak</dt><dd class="col-sm-9">{{ $tagihan->client_email ?: '-' }} / {{ $tagihan->client_phone ?: '-' }}</dd>
                    <dt class="col-sm-3">Judul</dt><dd class="col-sm-9">{{ $tagihan->title }} ({{ $tagihan->type_label }})</dd>
                    <dt class="col-sm-3">Author</dt><dd class="col-sm-9">{{ $tagihan->author_names ?: '-' }}</dd>
                    <dt class="col-sm-3">Deskripsi</dt><dd class="col-sm-9">{{ $tagihan->description ?: '-' }}</dd>
                    <dt class="col-sm-3">Nominal</dt><dd class="col-sm-9"><strong>Rp {{ number_format($tagihan->amount, 0, ',', '.') }}</strong></dd>
                    <dt class="col-sm-3">Jatuh Tempo</dt><dd class="col-sm-9">{{ $tagihan->due_at ? $tagihan->due_at->format('d M Y') : '-' }}</dd>
                    @if($tagihan->status === 'ditolak')<dt class="col-sm-3 text-danger">Alasan Tolak</dt><dd class="col-sm-9">{{ $tagihan->reject_note }}</dd>@endif
                    @if($tagihan->order_code)<dt class="col-sm-3">Order</dt><dd class="col-sm-9">{{ $tagihan->order_code }}</dd>@endif
                </dl>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h6 class="card-title">Log Status</h6>
                <ul class="list-unstyled mb-0">
                    @foreach($tagihan->logs->sortByDesc('created_at') as $log)
                        <li class="mb-2">
                            <span class="badge bg-light text-dark border">{{ $log->from_status ?: '—' }} → {{ $log->to_status }}</span>
                            <small class="text-muted">oleh {{ optional($log->changedBy)->name ?? 'sistem' }} · {{ $log->created_at->format('d M Y H:i') }}</small>
                            @if($log->note)<div><small>{{ $log->note }}</small></div>@endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <h6 class="card-title">Aksi</h6>
                @if($tagihan->isDownloadable())
                    <a href="{{ route('tagihan.pdf', $tagihan->id) }}" target="_blank" class="btn btn-outline-secondary w-100 mb-2">Download PDF</a>
                @endif
                @if($tagihan->canConvert() && $isOwner)
                    <a href="{{ route('tagihan.buatOrder', $tagihan->id) }}" class="btn btn-success w-100 mb-2">Buat Order dari Tagihan</a>
                @endif
                @if($tagihan->isEditable() && ($isOwner || auth()->user()->hasAnyRole(['manager','superadmin'])))
                    <a href="{{ route('tagihan.edit', $tagihan->id) }}" class="btn btn-outline-primary w-100 mb-2">Edit</a>
                @endif
                @if($isSuperadmin && $tagihan->status === 'diajukan')
                    <form method="POST" action="{{ route('tagihan.approve', $tagihan->id) }}" class="mb-2">@csrf
                        <button class="btn btn-success w-100">Approve</button>
                    </form>
                    <form method="POST" action="{{ route('tagihan.reject', $tagihan->id) }}">@csrf
                        <input type="text" name="note" class="form-control form-control-sm mb-1" placeholder="Alasan tolak" required>
                        <button class="btn btn-danger w-100">Tolak</button>
                    </form>
                @endif
                @if(in_array($tagihan->status, ['diajukan','disetujui']) && ($isOwner || auth()->user()->hasAnyRole(['manager','superadmin'])))
                    <form method="POST" action="{{ route('tagihan.cancel', $tagihan->id) }}" class="mt-2">@csrf
                        <button class="btn btn-outline-dark w-100">Batalkan</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
