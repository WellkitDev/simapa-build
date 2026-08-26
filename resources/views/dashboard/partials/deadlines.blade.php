@php
    $items = $deadlines ?? collect();

    // Ada yang sudah lewat atau jatuh tempo hari ini? Yang begitu TIDAK ikut disembunyikan
    // otomatis — menyembunyikan lencana merah setelah dua belas detik bukan merapikan
    // tampilan, itu menyembunyikan pekerjaan yang sudah terlambat.
    $mendesak = $items->contains(function ($t) {
        return \Illuminate\Support\Carbon::today()->diffInDays($t->due_date, false) <= 0;
    });
@endphp
@if($items->isNotEmpty())
{{-- `data-autohide` hanya dipasang bila TAK ada yang mendesak. Nilainya dibaca JS;
     ketiadaannya berarti kartunya bertahan sampai ditutup orangnya. --}}
<div class="row" data-deadline-wrap @unless($mendesak) data-autohide="12" @endunless>
    {{-- Pil ringkas yang tertinggal setelah kartunya disembunyikan. Informasinya dilipat,
         bukan dibuang — orang tetap bisa membukanya lagi kapan pun. --}}
    <div class="col-12 grid-margin d-none" data-deadline-pill>
        <button type="button" class="btn btn-sm btn-outline-warning d-inline-flex align-items-center gap-2">
            <i data-feather="clock" class="icon-sm"></i>
            Tugas mendekati deadline ({{ $items->count() }})
            <span class="text-muted" style="font-size:12px">— klik untuk lihat</span>
        </button>
    </div>

    <div class="col-12 grid-margin" data-deadline-card>
        <div class="card border-start border-4 border-warning">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2 gap-2">
                    <h6 class="mb-0 text-warning">
                        <i data-feather="clock" class="icon-sm me-1"></i>Tugas Mendekati Deadline ({{ $items->count() }})
                    </h6>
                    <div class="d-flex align-items-center gap-2 flex-shrink-0">
                        <span class="text-muted" style="font-size:12px" data-deadline-count></span>
                        <button type="button" class="btn-close btn-sm" aria-label="Sembunyikan" data-deadline-close></button>
                    </div>
                </div>
                <ul class="list-group list-group-flush">
                    @foreach($items as $t)
                        @php $days = (int) \Illuminate\Support\Carbon::today()->diffInDays($t->due_date, false); @endphp
                        @php $sayaBeri = ! ($deadlineIsOverseer ?? false) && $t->user_id !== auth()->id(); @endphp
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-1">
                            <span style="font-size:13px">
                                {{ $t->title }}
                                {{-- Pengawas selalu perlu tahu tugas siapa. Selain itu, nama
                                     hanya ditampilkan untuk tugas yang SAYA BERIKAN — tanpa
                                     penanda ini orang tak bisa membedakan mana yang harus ia
                                     kerjakan sendiri dan mana yang cuma perlu ia tunggu. --}}
                                @if($deadlineIsOverseer ?? false)
                                    <span class="text-muted">· {{ $t->user?->name }}</span>
                                @elseif($sayaBeri)
                                    <span class="badge bg-light text-muted border ms-1" style="font-weight:500">
                                        diberikan ke {{ $t->user?->name }}
                                    </span>
                                @endif
                            </span>
                            <span class="badge {{ $days <= 2 ? 'bg-danger' : 'bg-warning text-dark' }}">
                                {{ $days < 0 ? 'Lewat ' . abs($days) . 'h' : ($days === 0 ? 'Hari ini' : $days . ' hari lagi') }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</div>

@push('style')
<style>
    [data-deadline-card] { transition: opacity .25s ease; }
    [data-deadline-card].is-fading { opacity: 0; }
    @media (prefers-reduced-motion: reduce) {
        [data-deadline-card] { transition: none; }
    }
</style>
@endpush

@push('custom-scripts')
<script>
/*
 | POPUP SweetAlert DIHAPUS 2026-08-26, dan itu disengaja.
 |
 | Dulu halaman ini memunculkan modal berisi daftar yang PERSIS SAMA dengan kartu di
 | bawahnya. Dua pemberitahuan untuk satu data di layar yang sama, salah satunya
 | menghalangi seluruh halaman - itu bentuk kebisingan yang paling mahal, dan justru
 | melawan tujuan dashboard yang tak sesak.
 |
 | Yang menggantikan perannya: tugas yang sudah LEWAT atau jatuh tempo HARI INI tak
 | pernah disembunyikan otomatis. Yang mendesak tetap berdiri sampai ditutup orangnya.
 */
(function () {
    var wrap = document.querySelector('[data-deadline-wrap]');
    if (!wrap) return;

    var kartu  = wrap.querySelector('[data-deadline-card]');
    var pil    = wrap.querySelector('[data-deadline-pill]');
    var hitung = wrap.querySelector('[data-deadline-count]');
    var tutup  = wrap.querySelector('[data-deadline-close]');

    // Detik sebelum kartunya melipat sendiri, dari atribut yang dipasang Blade.
    // Tak ada atribut = ada tugas yang lewat atau jatuh tempo hari ini; kartunya
    // bertahan sampai orangnya sendiri yang menutup.
    var DETIK = parseInt(wrap.getAttribute('data-autohide') || '0', 10);
    var KUNCI = 'deadlineCardHidden';

    function lipat() {
        kartu.classList.add('is-fading');
        setTimeout(function () {
            kartu.classList.add('d-none');
            kartu.classList.remove('is-fading');
            pil.classList.remove('d-none');
            if (window.feather) feather.replace();
        }, 250);
    }

    function buka() {
        pil.classList.add('d-none');
        kartu.classList.remove('d-none');
        if (window.feather) feather.replace();
    }

    pil.querySelector('button').addEventListener('click', buka);

    tutup.addEventListener('click', function () {
        // Ditutup tangan = keputusan, bukan waktu habis. Diingat sepanjang sesi supaya
        // kartunya tak muncul lagi tiap kali halaman dibuka.
        try { sessionStorage.setItem(KUNCI, '1'); } catch (e) {}
        lipat();
    });

    try {
        if (sessionStorage.getItem(KUNCI)) {
            kartu.classList.add('d-none');
            pil.classList.remove('d-none');
            return;
        }
    } catch (e) {}

    if (!DETIK || !hitung) return;

    var sisa = DETIK;
    hitung.textContent = 'sembunyi dalam ' + sisa + ' dtk';

    var jam = setInterval(function () {
        sisa--;
        if (sisa <= 0) {
            clearInterval(jam);
            lipat();
            return;
        }
        hitung.textContent = 'sembunyi dalam ' + sisa + ' dtk';
    }, 1000);

    // Menyembunyikan sesuatu yang sedang dibaca orang adalah cara tercepat membuat
    // orang tak percaya pada layarnya. Hitungannya berhenti selama kursor di atas kartu.
    kartu.addEventListener('mouseenter', function () {
        clearInterval(jam);
        hitung.textContent = 'ditahan';
    });
    kartu.addEventListener('mouseleave', function () {
        sisa = DETIK;
        hitung.textContent = 'sembunyi dalam ' + sisa + ' dtk';
        jam = setInterval(function () {
            sisa--;
            if (sisa <= 0) { clearInterval(jam); lipat(); return; }
            hitung.textContent = 'sembunyi dalam ' + sisa + ' dtk';
        }, 1000);
    });
})();
</script>
@endpush
@endif
