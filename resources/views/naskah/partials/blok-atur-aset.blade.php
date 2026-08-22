{{--
    Gaya dan skrip untuk blok kiri yang bisa diatur.

    Sengaja TERPISAH dari komponennya: @once di dalam komponen anonim meninggalkan
    output buffer yang tak tertutup, dan PHPUnit menandai setiap tes yang merender
    halaman ini sebagai risky. Disertakan sekali dari detail.blade.php.
--}}
@push('style')
<style>
    .blok-atur-pegangan { cursor: grab; font-size: 1.1rem; line-height: 1; user-select: none; }
    .blok-atur-pegangan:active { cursor: grabbing; }
    .blok-atur .card-header { border-bottom: 1px solid rgba(0,0,0,.06); }
    .blok-atur.terlipat .blok-atur-isi { display: none; }
    /* Kepala blok yang terlipat tak boleh terlihat seperti kartu terpotong. */
    .blok-atur.terlipat .card-header { border-bottom: 0; }

    /* Pin = menempel saat menggulir. Hanya SATU blok boleh ter-pin sekaligus: di layar
       pendek dua blok menempel akan memakan seluruh tinggi kolom. */
    .blok-atur.tertempel { position: sticky; top: 1rem; z-index: 5; box-shadow: 0 .25rem .75rem rgba(0,0,0,.08); }
    .blok-atur.tertempel .blok-atur-pin { color: #0d6efd !important; }

    /* Bayangan tempat jatuh saat menggeser — tanpa ini orang tak tahu blok akan mendarat di mana. */
    .blok-atur-hantu { opacity: .35; }
    .blok-atur-terangkat { box-shadow: 0 .5rem 1.5rem rgba(0,0,0,.18); }

    /* Di layar sempit kolomnya bertumpuk; menempel di situ justru menghalangi. */
    @media (max-width: 991.98px) {
        .blok-atur.tertempel { position: static; box-shadow: none; }
    }
</style>
@endpush

@push('plugin-scripts')
<script src="{{ asset('assets/plugins/sortablejs/Sortable.min.js') }}"></script>
@endpush

@push('custom-scripts')
<script>
(function () {
    const WADAH = document.getElementById('blokAturKiri');
    if (!WADAH) return;

    const KUNCI = 'simapa.naskah.blok.v1';

    // localStorage bisa melempar, bukan sekadar kosong: jendela penyamaran, site data
    // yang diblokir, dan beberapa peramban korporat melemparkan SecurityError saat
    // diakses. Halaman harus tetap benar tanpa preferensi apa pun.
    function baca() {
        try { return JSON.parse(localStorage.getItem(KUNCI)) || {}; } catch (e) { return {}; }
    }
    function tulis(nilai) {
        try { localStorage.setItem(KUNCI, JSON.stringify(nilai)); } catch (e) { /* diabaikan */ }
    }

    function blokAda() {
        return Array.from(WADAH.querySelectorAll(':scope > .blok-atur'));
    }

    function simpan() {
        tulis({
            urutan:   blokAda().map(b => b.dataset.blok),
            terlipat: blokAda().filter(b => b.classList.contains('terlipat')).map(b => b.dataset.blok),
            pin:      (WADAH.querySelector('.blok-atur.tertempel') || {}).dataset?.blok || null,
        });
    }

    function pulihkan() {
        const p = baca();

        // Susunan tersimpan bisa menyebut blok yang tak ada di naskah ini (kartu Jurnal
        // hanya muncul untuk artikel), dan naskah ini bisa punya blok yang belum ada di
        // susunan tersimpan. Keduanya harus selamat: yang dikenal diurutkan, sisanya
        // menyusul di belakang dengan urutan aslinya.
        if (Array.isArray(p.urutan)) {
            const punya = new Map(blokAda().map(b => [b.dataset.blok, b]));
            p.urutan.forEach(function (id) {
                const el = punya.get(id);
                if (el) { WADAH.appendChild(el); punya.delete(id); }
            });
            punya.forEach(el => WADAH.appendChild(el));
        }

        (p.terlipat || []).forEach(function (id) {
            const el = WADAH.querySelector('[data-blok="' + id + '"]');
            if (el) setLipat(el, true);
        });

        if (p.pin) {
            const el = WADAH.querySelector('[data-blok="' + p.pin + '"]');
            if (el) setPin(el, true);
        }
    }

    function setLipat(blok, terlipat) {
        blok.classList.toggle('terlipat', terlipat);
        const tombol = blok.querySelector('.blok-atur-lipat');
        const tanda  = blok.querySelector('.blok-atur-tanda');
        if (tombol) tombol.setAttribute('aria-expanded', terlipat ? 'false' : 'true');
        if (tanda)  tanda.innerHTML = terlipat ? '&plus;' : '&minus;';
    }

    function setPin(blok, tertempel) {
        // Satu saja: dua blok menempel akan memakan seluruh tinggi kolom di layar pendek.
        blokAda().forEach(function (b) {
            b.classList.remove('tertempel');
            const t = b.querySelector('.blok-atur-pin');
            if (t) t.setAttribute('aria-pressed', 'false');
        });
        if (tertempel) {
            blok.classList.add('tertempel');
            const t = blok.querySelector('.blok-atur-pin');
            if (t) t.setAttribute('aria-pressed', 'true');
        }
    }

    WADAH.addEventListener('click', function (e) {
        const blok = e.target.closest('.blok-atur');
        if (!blok) return;

        if (e.target.closest('.blok-atur-lipat')) {
            setLipat(blok, !blok.classList.contains('terlipat'));
            simpan();
        } else if (e.target.closest('.blok-atur-pin')) {
            setPin(blok, !blok.classList.contains('tertempel'));
            simpan();
        }
    });

    const reset = document.getElementById('blokAturReset');
    if (reset) {
        reset.addEventListener('click', function () {
            try { localStorage.removeItem(KUNCI); } catch (e) { /* diabaikan */ }
            location.reload();
        });
    }

    pulihkan();

    // Sortable dimuat ke stack plugin-scripts, yang di master berada SEBELUM
    // custom-scripts. Tetap dijaga: tanpa pustakanya, lipat dan pin harus tetap jalan.
    //
    // JANGAN menulis nama direktif Blade berawalan @ di dalam komentar berkas .blade —
    // Blade mengompilasinya sungguhan, dan satu direktif pembuka tanpa penutup
    // meninggalkan output buffer menggantung tanpa satu pun galat.
    if (window.Sortable) {
        Sortable.create(WADAH, {
            handle: '.blok-atur-pegangan',
            draggable: '.blok-atur',
            animation: 150,
            ghostClass: 'blok-atur-hantu',
            chosenClass: 'blok-atur-terangkat',
            onEnd: simpan,
        });
    }
})();
</script>
@endpush
