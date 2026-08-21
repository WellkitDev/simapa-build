{{--
    Informasi Publikasi — cermin dari kartu yang sama di halaman judul.

    Ada di sini supaya PJ tak perlu pindah halaman saat naskahnya sedang dikerjakan,
    terutama untuk mengisi Link Terbit yang kini menggerbangi tahap akhir.

    Menulis lewat `title.info.update` yang SAMA — bukan jalur kedua — supaya aturan
    validasinya tak bercabang dua versi. `_redirect` yang membawa orang kembali ke sini.

    Panel ini sengaja hanya menampilkan sebagian field, dan TIDAK menampilkan Opsi Jurnal
    maupun Kode: keduanya urusan tata kelola judul, bukan pekerjaan harian naskah.
    Keduanya aman ditinggalkan karena updateInfo() memperlakukan kunci yang absen sebagai
    "jangan sentuh" — dulu tidak begitu, dan menyimpan dari sini akan memusnahkannya.
--}}
@if ($title)
<div class="card mb-3"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="text-uppercase text-muted small fw-bold mb-0">Informasi Publikasi</h6>
        @if ($canEditInfo)
            <button type="button" class="btn btn-sm btn-outline-primary py-0"
                    data-bs-toggle="collapse" data-bs-target="#infoPublikasiNaskah">
                Edit Informasi Publikasi
            </button>
        @endif
    </div>

    @if ($isKolab)
        <p class="text-muted small mb-2">Isian ini berlaku untuk seluruh judul, bukan hanya order ini.</p>
    @endif

    @php
        $linkTerbit = $title->linkTerbit();
        $labelLink  = $buku ? 'Link Buku Terbit' : 'Link Artikel Terbit';
        $baris = [
            'Target terbit' => optional($title->target_terbit)->translatedFormat('j M Y') ?? '—',
            'Jurnal target' => $title->jurnal_target ?: '—',
            'Template'      => $title->template_link ?: '—',
            'APC'           => $title->apc_info ?: '—',
            'Catatan'       => $title->catatan_publikasi ?: '—',
        ];
    @endphp

    @foreach ($baris as $label => $isi)
        <div class="d-flex justify-content-between border-bottom border-dashed py-2 small">
            <span class="text-muted">{{ $label }}</span>
            <strong class="text-end">{{ $isi }}</strong>
        </div>
    @endforeach

    <div class="d-flex justify-content-between border-bottom border-dashed py-2 small">
        <span class="text-muted">{{ $labelLink }}</span>
        <strong class="text-end">
            @if ($linkTerbit)
                <a href="{{ $linkTerbit }}" target="_blank" rel="noopener">buka</a>
            @else
                <span class="text-danger">belum diisi</span>
            @endif
        </strong>
    </div>

    @if (! $linkTerbit && $next && \App\Models\TitleProgress::isFinal($next))
        <div class="alert alert-warning small mt-3 mb-0">
            {{ $labelLink }} belum diisi — naskah belum bisa ditandai
            {{ \App\Models\TitleProgress::labelFor($next) }}.
        </div>
    @endif

    @if ($canEditInfo)
    <div class="collapse mt-3" id="infoPublikasiNaskah">
        <form method="POST" action="{{ route('title.info.update', $title->id) }}">
            @csrf @method('PUT')
            <input type="hidden" name="_redirect" value="{{ route('naskah.show', $progress->order_detail_id) }}">

            <div class="mb-2">
                <label class="form-label small">{{ $labelLink }}</label>
                <input type="url" name="link_terbit" class="form-control form-control-sm @error('link_terbit') is-invalid @enderror"
                       value="{{ old('link_terbit', $title->link_terbit) }}" placeholder="https://...">
                @error('link_terbit')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-2">
                <label class="form-label small">Jurnal Target</label>
                <input type="text" name="jurnal_target" class="form-control form-control-sm"
                       value="{{ old('jurnal_target', $title->jurnal_target) }}">
            </div>
            <div class="mb-2">
                <label class="form-label small">Link Jurnal</label>
                <input type="text" name="jurnal_link" class="form-control form-control-sm"
                       value="{{ old('jurnal_link', $title->jurnal_link) }}">
            </div>
            <div class="mb-2">
                <label class="form-label small">Link Template Artikel</label>
                <input type="text" name="template_link" class="form-control form-control-sm"
                       value="{{ old('template_link', $title->template_link) }}">
            </div>
            <div class="mb-2">
                <label class="form-label small">APC</label>
                <input type="text" name="apc_info" class="form-control form-control-sm"
                       value="{{ old('apc_info', $title->apc_info) }}">
            </div>
            <div class="mb-2">
                <label class="form-label small">Catatan</label>
                <textarea name="catatan_publikasi" class="form-control form-control-sm" rows="2">{{ old('catatan_publikasi', $title->catatan_publikasi) }}</textarea>
            </div>

            <button class="btn btn-sm btn-primary">Simpan</button>
            <small class="text-muted d-block mt-1">
                Kode judul dan Opsi Jurnal diatur di halaman judul.
            </small>
        </form>
    </div>
    @endif
</div></div>
@endif
