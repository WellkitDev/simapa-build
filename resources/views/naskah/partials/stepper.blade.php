{{--
    Linimasa tahap. Tahap berjalan menampilkan "sudah X hari di tahap ini" — bukan
    jargon, dan tahap terakhir menampilkan target supaya deadline selalu terlihat.
    Buku kolaborasi menggabungkan Pembuatan+Editing jadi satu langkah "per bab".
--}}
@php
    $sekarang = array_search($progress->status, $stages, true);
    $terakhir = count($stages) - 1;
@endphp

<div class="card mt-3"><div class="card-body">
    <div class="d-flex overflow-auto gap-2">
        @foreach ($stages as $i => $stage)
            @php
                $lewat  = $sekarang !== false && $i < $sekarang;
                $aktif  = $i === $sekarang;
                $gabung = $isKolab && in_array($stage, ['pembuatan', 'editing'], true);
            @endphp
            <div class="text-center flex-shrink-0" style="min-width:110px">
                <div class="rounded-circle mx-auto mb-1 d-flex align-items-center justify-content-center
                            {{ $lewat ? 'bg-success text-white' : ($aktif ? 'bg-primary text-white' : 'bg-light border text-muted') }}"
                     style="width:26px;height:26px;font-size:.75rem">
                    {{ $lewat ? '✓' : ($aktif ? '●' : '') }}
                </div>
                <div class="small fw-semibold {{ $aktif ? 'text-primary' : 'text-muted' }}">
                    {{ \App\Models\TitleProgress::labelFor($stage) }}
                </div>
                <div class="text-muted" style="font-size:.7rem">
                    @if ($aktif)
                        <span class="{{ $progress->isOverdue() ? 'text-danger fw-bold' : '' }}">
                            {{ $progress->daysInStage() }} hari — sejak
                            {{ $progress->started_at?->translatedFormat('j M') ?? '—' }}
                        </span>
                    @elseif ($gabung && $ringkasan)
                        per bab · {{ $ringkasan['selesai'] }}/{{ $ringkasan['total'] }} selesai
                    @elseif ($i === $terakhir && $progress->target_date)
                        🎯 {{ $progress->target_date->translatedFormat('j M') }}
                    @else
                        —
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div></div>
