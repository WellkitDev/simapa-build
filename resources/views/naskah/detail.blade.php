@extends('layouts.master')
@section('title', 'Detail Naskah - SiMAPA')
@section('content')
    <div class="mb-3">@include('partials.tombol-kembali', ['cadangan' => route('naskah.pelacakan')])</div>

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
    //
    // Bab milik JUDUL, bukan order, sedangkan naskah_type melekat pada order — jadi
    // tabel bab hanya boleh memakai penanda ini bila SELURUH order sejudul sepakat.
    // Kalau campuran, biarkan asal naskah diturunkan per bab.
    $jenisNaskahGrup = \App\Models\OrderDetail::jenisNaskahGrup($grup->map->orderDetail);
    $naskahMandiri   = $jenisNaskahGrup === 'mandiri';
@endphp

@include('naskah.partials.detail-header', compact('progress', 'grup', 'd', 'kode', 'buku', 'isKolab', 'jumlahAuthorBab', 'naskahMandiri', 'jenisNaskahGrup'))

@include('naskah.partials.stepper', compact('progress', 'stages', 'isKolab', 'ringkasan', 'rincian', 'izin'))

@if ($isKolab)
    @include('naskah.partials.bab-table', compact('bab', 'ringkasan', 'izin', 'pelaksanaOptions', 'progress', 'naskahMandiri'))
@endif

<div class="row g-3 mt-1">
    {{--
        Kolom kiri = konteks yang dibaca. Tiap bloknya bisa disusun ulang, dilipat, dan
        ditempelkan menurut cara kerja masing-masing orang; preferensinya di peramban.

        Kolom kanan = pekerjaan yang dilakukan. SENGAJA tidak bisa disusun ulang:
        urutannya bermakna — blok opsional yang menahan laju harus terbaca sebelum
        tombol Aksi yang tertahan, dan membiarkannya digeser berarti pengguna bisa
        merusak sebab-akibat itu sendiri.
    --}}
    <div class="col-lg-5">
        <div class="d-flex justify-content-end mb-2">
            <button type="button" id="blokAturReset" class="btn btn-sm btn-link text-muted p-0 small">
                Kembalikan susunan bawaan
            </button>
        </div>

        <div id="blokAturKiri">
        <x-blok-atur id="informasi" judul="Informasi &amp; Penanggung Jawab">
            @php
                $baris = [
                    // Kartu ini memang tentang ORDER yang sedang dibuka. Bila order
                    // sejudul lainnya berbeda jenis, dibubuhi "(order ini)" supaya tak
                    // terbaca sebagai sifat seluruh judul.
                    'Jenis naskah'            => ($d?->naskahTypeLabel() ?? '—')
                        . ($jenisNaskahGrup === 'campuran' ? ' (order ini)' : ''),
                    // PJ wajib diisi manual dan berlaku untuk SEMUA jenis naskah — ia yang
                    // menerima notifikasi tahap, ditagih saat lewat SLA, dan namanya
                    // tercetak di laporan arsip. Kekosongannya ditandai, bukan didiamkan.
                    'Penanggung Jawab (PJ)'   => $progress->pj?->name ?? '⚠ Belum ditetapkan',
                    // Buku kolaborasi dikerjakan PER BAB, dan pelaksananya tersimpan di
                    // tb_chapter_progress — tabel yang berbeda dari yang dibaca baris ini.
                    // Menampilkan satu nama level order di sini berarti mengklaim sesuatu
                    // yang bisa bertentangan dengan tabel bab tepat di bawahnya.
                    'Pelaksana pembuatan'     => $isKolab
                        ? 'Per bab — ' . ($bab->map->progress->filter()->pluck('pelaksana_user_id')->filter()->unique()->count() ?: 'belum ada')
                          . ' pelaksana · lihat tabel bab'
                        : ($progress->pelaksana?->name
                            ?? ($naskahMandiri ? 'Tidak ada — naskah dikirim author' : 'Belum ditentukan')),
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
        </x-blok-atur>

        {{-- Jurnal (artikel saja) --}}
        @if (! $buku)
            @php
                $judulRef = $d?->titleRef;
                $sub      = $judulRef?->journalSubmissions()->orderByDesc('id')->first();
            @endphp
            <x-blok-atur id="jurnal" judul="Jurnal">
                @if ($judulRef)
                    <div class="mb-2">
                        <a href="{{ route('title.show', $judulRef->id) }}" class="btn btn-xs btn-outline-secondary">Direktori Judul</a>
                    </div>
                @endif

                @if ($sub)
                    <div class="d-flex justify-content-between border-bottom border-dashed py-2 small">
                        <span class="text-muted">Jurnal tujuan</span>
                        <strong class="text-end">
                            @if ($sub->journal)
                                <a href="{{ route('journal.show', $sub->journal_id) }}">{{ $sub->journal->nama }}</a>
                            @else
                                —
                            @endif
                        </strong>
                    </div>
                    <div class="d-flex justify-content-between border-bottom border-dashed py-2 small">
                        <span class="text-muted">Tgl submit</span>
                        <strong class="text-end">{{ $sub->tgl_submit?->translatedFormat('j M Y') ?? '—' }}</strong>
                    </div>
                    <div class="d-flex justify-content-between border-bottom border-dashed py-2 small">
                        <span class="text-muted">Status</span>
                        <strong class="text-end">{{ $sub->statusLabel() }}</strong>
                    </div>
                    <div class="d-flex justify-content-between py-2 small">
                        <span class="text-muted">Link terbit</span>
                        <strong class="text-end">
                            @if ($sub->link_publish)
                                <a href="{{ $sub->link_publish }}" target="_blank" rel="noopener">Buka</a>
                            @else
                                Belum ada
                            @endif
                        </strong>
                    </div>
                @else
                    {{-- Tak memakai kata "belum diisi": sebelum tahap Submit memang belum
                         waktunya, jadi menyebutnya kekurangan hanya jadi kebisingan. --}}
                    <p class="small text-muted mb-0">
                        Catatan jurnal terbentuk saat tahap <strong>Submit</strong> diselesaikan.
                    </p>
                @endif
            </x-blok-atur>
        @endif

        <x-blok-atur id="brief" judul="Brief dari Marketing">
            <p class="small text-muted mb-0">{{ $d?->order?->note ?: 'Belum ada brief dari marketing.' }}</p>
        </x-blok-atur>

        @if ($title)
            <x-blok-atur id="info-publikasi" judul="Informasi Publikasi">
                @include('naskah.partials.informasi-publikasi', compact('title', 'canEditInfo', 'progress', 'next', 'buku', 'isKolab'))
            </x-blok-atur>
        @endif
        </div>

        @include('naskah.partials.blok-atur-aset')
    </div>

    <div class="col-lg-7">
        {{-- Blok opsional: hanya muncul bila naskah ini memang punya putaran perbaikan.
             Letaknya TEPAT DI ATAS Aksi karena kartu inilah yang menahan tombol
             "Selesaikan tahap" di bawahnya; saat dipisahkan, pesan penolakan muncul di
             satu ujung layar dan tempat menjawabnya di ujung yang lain. --}}
        @include('naskah.partials.revisi', compact('progress', 'putaran', 'izin'))

        @include('naskah.partials.aksi', compact('progress', 'grup', 'stages', 'next', 'izin', 'pelaksanaOptions', 'adminOptions', 'buku', 'isKolab'))

        @include('naskah.partials.file-naskah', compact('progress', 'berkas', 'izin', 'isKolab', 'buku'))

        @include('naskah.partials.riwayat-naskah', ['logs' => $progress->logs->sortByDesc('created_at')])
    </div>
</div>

@endsection
