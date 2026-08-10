@extends('layouts.master')
@section('title', 'Detail Naskah - SiMAPA')
@section('content')

@php
    $d     = $progress->orderDetail;
    $kode  = $d?->order?->code_order ?? $d?->titleRef?->code ?? '—';
    $buku  = in_array($d?->type, ['bk_mandiri', 'bk_kolab'], true);
    $telat = $progress->isOverdue();
@endphp

@php
    // Jumlah author unik lintas bab — menjawab "10 bab · 10 author" di wireframe 3B.
    $jumlahAuthorBab = $isKolab ? $bab->flatMap->authors->unique('id')->count() : 0;

    // Order naskah mandiri tidak punya tahap pembuatan oleh tim — ditandai di semua
    // tempat yang biasanya menampilkan pelaksana, supaya kolom kosong tidak terbaca
    // sebagai "belum ditugaskan".
    $naskahMandiri = (bool) $d?->naskahMandiri();
@endphp

@include('naskah.partials.detail-header', compact('progress', 'grup', 'd', 'kode', 'buku', 'isKolab', 'jumlahAuthorBab', 'naskahMandiri'))

@include('naskah.partials.stepper', compact('progress', 'stages', 'isKolab', 'ringkasan'))

@if ($isKolab)
    @include('naskah.partials.bab-table', compact('bab', 'ringkasan', 'izin', 'pelaksanaOptions', 'progress', 'naskahMandiri'))
@endif

<div class="row g-3 mt-1">
    <div class="col-lg-5">
        {{-- Informasi & penanggung jawab --}}
        <div class="card mb-3"><div class="card-body">
            <h6 class="text-uppercase text-muted small fw-bold mb-3">Informasi &amp; Penanggung Jawab</h6>
            @php
                $baris = [
                    'Jenis naskah'            => $d?->naskahTypeLabel() ?? '—',
                    'Penanggung Jawab (PJ)'   => $progress->pj?->name ?? 'Belum ditentukan',
                    'Pelaksana pembuatan'     => $progress->pelaksana?->name
                        ?? ($naskahMandiri ? 'Tidak ada — naskah dikirim author' : 'Belum ditentukan'),
                    'Bidang'                  => $progress->bidang ? ucfirst($progress->bidang) : '—',
                    'Target ' . ($buku ? 'terbit' : 'publish')
                                              => $progress->target_date?->translatedFormat('j M Y') ?? 'Belum diset',
                    'Prioritas'               => ucfirst($progress->priority ?? 'normal'),
                    'Tahap sekarang'          => $progress->stageLabelId() . ' — sudah ' . $progress->daysInStage() . ' hari di tahap ini',
                    // Gerbang antrian adalah DP terverifikasi, jadi status bayar ikut
                    // ditampilkan di sini — bukan supaya orang produksi melihat nominal,
                    // melainkan supaya jelas kenapa naskah sudah/belum boleh jalan.
                    'Pembayaran'              => $d?->order
                        ? ($d->order->hasApprovedPayment() ? 'DP ✓' : 'DP belum')
                          . ' · Pelunasan: ' . ($d->order->isLunas() ? 'lunas' : 'belum')
                        : '—',
                ];
            @endphp
            @foreach ($baris as $label => $isi)
                <div class="d-flex justify-content-between border-bottom border-dashed py-2 small">
                    <span class="text-muted">{{ $label }}</span>
                    <strong class="text-end">{{ $isi }}</strong>
                </div>
            @endforeach
            @if ($progress->is_on_hold)
                <div class="alert alert-warning small mt-3 mb-0">Naskah sedang ditahan sementara.</div>
            @endif
            @if ($progress->cancelled_at)
                <div class="alert alert-danger small mt-3 mb-0">
                    Dibatalkan {{ $progress->cancelled_at->translatedFormat('j M Y') }} —
                    {{ $progress->cancel_reason ?? 'tanpa alasan tercatat' }}
                </div>
            @endif
        </div></div>

        {{-- Brief dari marketing --}}
        <div class="card mb-3"><div class="card-body">
            <h6 class="text-uppercase text-muted small fw-bold mb-2">Brief dari Marketing</h6>
            <p class="small text-muted mb-0">{{ $d?->order?->note ?: 'Belum ada brief dari marketing.' }}</p>
        </div></div>

        @include('naskah.partials.file-naskah', compact('progress', 'berkas', 'izin', 'isKolab'))
    </div>

    <div class="col-lg-7">
        @include('naskah.partials.aksi', compact('progress', 'grup', 'stages', 'next', 'izin', 'pelaksanaOptions', 'adminOptions', 'buku'))
        @include('naskah.partials.riwayat-naskah', ['logs' => $progress->logs->sortByDesc('created_at')])
    </div>
</div>

@endsection
