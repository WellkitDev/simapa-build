{{--
    Kartu Putaran Perbaikan.

    Judulnya mengikuti `stage` tiap putaran, bukan dipatok "Revisi": buku tak punya
    tahap revisi sama sekali, dan kartu berjudul "Revisi" di layar buku membuat orang
    meragukan seluruh halamannya.

    Putaran terbuka tampil terbuka; yang sudah ditutup terlipat dan hanya-baca — itulah
    yang membuat berkas revisi lama tetap terlist saat naskah mundur dari LoA.

    Formulir permintaan ada DI SINI, bukan di kartu Aksi, karena tempatnya bersebelahan
    dengan berkas yang dibicarakannya.
--}}
@php
    $adaFormMinta = $progress->status === 'revisi' && ($izin['advance'] ?? false);
@endphp

@if ($putaran->isNotEmpty() || $adaFormMinta)
<div class="card mb-3"><div class="card-body">
    <h6 class="text-uppercase text-muted small fw-bold mb-3">Putaran Perbaikan</h6>

    @forelse ($putaran as $p)
        @php
            $terbuka    = $p->closed_at === null;
            $judulKartu = $p->stage === 'pembuatan' ? 'Dikembalikan ke Pembuatan' : 'Revisi';
        @endphp

        <div class="border rounded p-2 mb-2 {{ $terbuka ? 'bg-light' : '' }}">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <strong class="small">{{ $judulKartu }} · putaran {{ $p->round }}</strong>
                    <div class="text-muted small">
                        dibuka dari {{ \App\Models\TitleProgress::labelFor($p->from_stage) }}
                        · {{ $p->created_at?->translatedFormat('j M Y') }}
                    </div>
                </div>
                <span class="badge {{ $terbuka ? 'bg-warning text-dark' : 'bg-secondary' }}">
                    {{ $terbuka ? 'terbuka' : 'selesai' }}
                </span>
            </div>

            <div class="small mt-2">
                <span class="text-muted">Diminta</span> {{ $p->requestedBy?->name ?? '—' }}
                @if ($p->assignedTo)
                    → <span class="text-muted">ditujukan</span> <strong>{{ $p->assignedTo->name }}</strong>
                @endif
            </div>
            <div class="small fst-italic mt-1">"{{ $p->request_note }}"</div>

            @foreach (['revisi_minta' => 'Permintaan', 'revisi_hasil' => 'Hasil'] as $slot => $label)
                @php $daftar = $p->files->where('slot', $slot); @endphp
                <div class="mt-2">
                    <div class="text-muted small fw-bold">{{ $label }}</div>
                    @forelse ($daftar as $b)
                        <div class="small d-flex justify-content-between">
                            <span class="text-truncate me-2">{{ $b->original_name }}</span>
                            @if ($b->status === 'selesai' && $b->drive_url)
                                <a href="{{ $b->drive_url }}" target="_blank" rel="noopener">buka</a>
                            @elseif ($b->status === 'antre')
                                <span class="text-muted text-nowrap">antre …</span>
                            @else
                                <span class="text-danger text-nowrap">gagal</span>
                            @endif
                        </div>
                    @empty
                        <div class="small text-muted">— belum ada —</div>
                    @endforelse
                </div>
            @endforeach

            @if ($terbuka && ($izin['upload'] ?? false))
                <form method="POST" action="{{ route('naskah.revisi.hasil', $progress->order_detail_id) }}"
                      enctype="multipart/form-data" class="mt-2">
                    @csrf
                    <input type="hidden" name="revision_id" value="{{ $p->id }}">
                    <div class="d-flex gap-1">
                        <input type="file" name="berkas[]" multiple required
                               accept=".pdf,.doc,.docx,.zip" class="form-control form-control-sm">
                        <button class="btn btn-sm btn-primary text-nowrap">Unggah hasil</button>
                    </div>
                </form>
            @endif

            @if ($terbuka && ($izin['advance'] ?? false))
                {{-- Pintu darurat: tanpa ini, satu putaran yang salah buka mengunci
                     naskah selamanya dan hanya superadmin yang bisa membebaskannya. --}}
                <form method="POST" action="{{ route('naskah.revisi.tutup', $progress->order_detail_id) }}"
                      class="mt-2 d-flex gap-1">
                    @csrf
                    <input type="hidden" name="revision_id" value="{{ $p->id }}">
                    <input type="text" name="close_note" required class="form-control form-control-sm"
                           placeholder="Alasan menutup tanpa berkas (wajib)">
                    <button class="btn btn-sm btn-outline-secondary text-nowrap">Tutup</button>
                </form>
            @endif

            @if (! $terbuka && $p->close_note)
                <div class="small text-muted mt-2">
                    Ditutup {{ $p->closedBy?->name ?? '—' }}: "{{ $p->close_note }}"
                </div>
            @endif
        </div>
    @empty
        <p class="text-muted small">Belum ada permintaan revisi untuk naskah ini.</p>
    @endforelse

    @if ($adaFormMinta)
        <button class="btn btn-sm btn-outline-primary w-100" data-bs-toggle="collapse"
                data-bs-target="#formMintaRevisi">+ Minta revisi baru</button>

        <div class="collapse mt-2" id="formMintaRevisi">
            <form method="POST" action="{{ route('naskah.revisi.minta', $progress->order_detail_id) }}"
                  enctype="multipart/form-data" class="border rounded p-2">
                @csrf
                <textarea name="request_note" rows="2" required class="form-control form-control-sm mb-2"
                          placeholder="Apa yang diminta reviewer? (wajib)">{{ old('request_note') }}</textarea>
                <input type="file" name="berkas[]" multiple accept=".pdf,.doc,.docx,.zip"
                       class="form-control form-control-sm mb-2">
                <div class="form-text mb-2">
                    Ditujukan ke <strong>{{ $progress->pelaksana?->name ?? 'pelaksana naskah' }}</strong>.
                    Naskah tak bisa maju ke LoA sampai permintaan ini dijawab atau ditutup.
                </div>
                <button class="btn btn-sm btn-primary">Kirim permintaan</button>
            </form>
        </div>
    @endif
</div></div>
@endif
