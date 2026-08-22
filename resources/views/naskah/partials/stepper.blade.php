{{--
    Linimasa tahap — bisa diklik.

    Tahap berjalan menampilkan "sudah X hari di tahap ini"; tahap terakhir menampilkan
    target supaya deadline selalu terlihat. Buku kolaborasi menggabungkan
    Pembuatan+Editing jadi satu langkah "per bab".

    Tiap tahap adalah <button> sungguhan, bukan <div> ber-onclick: papan ketik dan
    pembaca layar ikut bekerja tanpa pekerjaan tambahan.

    Seluruh panel dirender sekaligus lalu ditampilkan bergantian — delapan tahap terlalu
    sedikit untuk membenarkan endpoint baru, dan tanpa pemuatan ulang panelnya terasa
    seketika.
--}}
@php
    $sekarang = array_search($progress->status, $stages, true);
    $terakhir = count($stages) - 1;
@endphp

<div class="card mt-3"><div class="card-body">
    <div class="d-flex overflow-auto gap-2" id="stepperTahap">
        @foreach ($stages as $i => $stage)
            @php
                $lewat  = $sekarang !== false && $i < $sekarang;
                $aktif  = $i === $sekarang;
                $gabung = $isKolab && in_array($stage, ['pembuatan', 'editing'], true);
                $punya  = ($rincian[$stage]['dijalani'] ?? false) || $aktif;
            @endphp
            <button type="button" data-tahap="{{ $stage }}"
                    aria-expanded="false" aria-controls="rincian-{{ $stage }}"
                    class="stepper-tahap btn btn-link text-decoration-none text-center flex-shrink-0
                           border rounded p-2 {{ $punya ? '' : 'opacity-75' }}"
                    style="min-width:112px">
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
            </button>
        @endforeach
    </div>

    <div class="text-muted small mt-2" id="petunjukTahap">
        Klik tahap untuk melihat rinciannya.
    </div>

    @include('naskah.partials.rincian-tahap', compact('progress', 'stages', 'rincian'))
</div></div>

{{-- Stack-nya bernama `style`, bukan `custom-styles`: master hanya menyediakan
     plugin-styles, style, plugin-scripts, dan custom-scripts. Salah nama tak
     menghasilkan galat apa pun — gayanya cuma tak pernah terpasang. --}}
@push('style')
<style>
    /* Kalau tak terlihat bisa diklik, fiturnya tak ada. */
    .stepper-tahap { cursor: pointer; border-color: transparent !important; transition: background-color .12s, border-color .12s; }
    .stepper-tahap:hover { background-color: rgba(13,110,253,.06); border-color: rgba(13,110,253,.35) !important; }
    /* Tahap yang panelnya sedang terbuka — supaya tak ada keraguan panel ini milik siapa. */
    .stepper-tahap.terpilih { background-color: rgba(13,110,253,.10); border-color: rgba(13,110,253,.75) !important; }
</style>
@endpush

@push('custom-scripts')
<script>
    (function () {
        const strip = document.getElementById('stepperTahap');
        if (!strip) return;

        const petunjuk = document.getElementById('petunjukTahap');

        function tutupSemua() {
            document.querySelectorAll('[data-rincian]').forEach(function (p) { p.hidden = true; });
            strip.querySelectorAll('.stepper-tahap').forEach(function (b) {
                b.classList.remove('terpilih');
                b.setAttribute('aria-expanded', 'false');
            });
        }

        strip.addEventListener('click', function (e) {
            const tombol = e.target.closest('.stepper-tahap');
            if (!tombol) return;

            const tahap = tombol.dataset.tahap;
            const panel = document.querySelector('[data-rincian="' + tahap + '"]');
            if (!panel) return;

            // Satu panel terbuka pada satu waktu: dua panel terbuka membuat orang lupa
            // sedang membaca yang mana.
            const sudahTerbuka = !panel.hidden;
            tutupSemua();

            if (!sudahTerbuka) {
                panel.hidden = false;
                tombol.classList.add('terpilih');
                tombol.setAttribute('aria-expanded', 'true');
                if (petunjuk) petunjuk.hidden = true;
            } else if (petunjuk) {
                petunjuk.hidden = false;
            }
        });
    })();
</script>
@endpush
