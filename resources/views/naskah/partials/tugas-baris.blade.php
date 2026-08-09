{{--
    Satu baris tugas di Meja Kerja. Tiga kolom seperti wireframe: identitas ·
    tahap + lama pengerjaan + target · tombol aksi kontekstual.
    Baris terlambat diberi latar & garis merah supaya kelihatan tanpa dibaca.
--}}
@php
    $isBab   = $jenis === 'bab';
    $detailId = $isBab
        ? optional(optional($model->chapter)->title)->orderDetails->first()?->id
        : $model->order_detail_id;
    $tahap   = $isBab ? $model->stageLabelId() : $model->stageLabelId();
    $hari    = $model->daysInStage();
    $akuPelaksana = (int) $model->pelaksana_user_id === (int) $akuId;
@endphp

<div class="card mb-2 {{ $terlambat ? 'border-danger bg-danger-subtle' : '' }}">
    <div class="card-body py-3">
        <div class="row align-items-center g-3">
            <div class="col-md-5">
                @include('naskah.partials.identitas', ['jenis' => $jenis, 'model' => $model])
            </div>

            <div class="col-md-4 small">
                <span class="badge {{ $terlambat ? 'bg-danger' : 'bg-primary-subtle text-primary' }}">{{ $tahap }}</span>

                <div class="mt-1 {{ $terlambat ? 'text-danger fw-bold' : 'text-muted' }}">
                    @if ($model->status === 'pembuatan' && $model->sla_due_at)
                        Hari ke-{{ $hari }} dari SLA 7 hari
                        @if ($terlambat)
                            — terlambat {{ $model->sla_due_at->diffInDays(now()) }} hari
                        @endif
                    @else
                        Sudah {{ $hari }} hari di tahap ini
                    @endif
                </div>

                @if (! $isBab && $model->target_date)
                    <div class="text-muted">
                        Target {{ in_array($model->status, ['cetak', 'terbit'], true) || $model->bidang === 'buku' ? 'terbit' : 'publish' }}:
                        {{ $model->target_date->translatedFormat('j M Y') }}
                    </div>
                @endif

                @if (! $isBab && $model->priority === 'high')
                    <div class="mt-1">Prioritas: <span class="badge bg-danger">High</span></div>
                @endif
            </div>

            <div class="col-md-3 text-md-end">
                @if ($antrian)
                    <form method="POST" action="{{ $isBab ? route('naskah.bab.claim', $model->id) : route('naskah.claim', $model->order_detail_id) }}">
                        @csrf
                        <button class="btn btn-outline-primary btn-sm">✋ Ambil Tugas Ini</button>
                    </form>
                @elseif ($model->status === 'pembuatan' && $akuPelaksana && $izin['upload'])
                    <a href="{{ $detailId ? route('naskah.show', $detailId) : '#' }}" class="btn btn-primary btn-sm">
                        ⬆ Upload Naskah{{ $isBab ? ' Bab' : '' }}
                    </a>
                @elseif ($izin['advance'])
                    <form method="POST" action="{{ $isBab ? route('naskah.bab.selesaikan', $model->id) : route('naskah.selesaikan', $model->order_detail_id) }}">
                        @csrf
                        <button class="btn btn-primary btn-sm">✓ Selesaikan {{ $isBab ? 'Bab' : 'Tahap' }} →</button>
                    </form>
                @else
                    <a href="{{ $detailId ? route('naskah.show', $detailId) : '#' }}" class="btn btn-outline-secondary btn-sm">Lihat Detail</a>
                @endif
            </div>
        </div>
    </div>
</div>
