{{--
    Tombol "Kembali" seragam.

    $ke       : URL tujuan tetap — dipakai bila halaman ini memang hanya punya satu induk.
    $cadangan : tujuan bila $ke tak diberikan DAN halaman sebelumnya tak bisa dipercaya.
    $label    : teks tombol (bawaan "Kembali").

    Tanpa $ke, tujuannya diambil dari halaman sebelumnya. Itu yang benar untuk halaman
    yang bisa dicapai dari beberapa arah — Detail Naskah bisa dibuka dari Meja Kerja
    maupun dari Pelacakan, jadi menetapkan satu induk pasti salah separuh waktu.

    Halaman sebelumnya yang menunjuk balik ke halaman ini sendiri sengaja diabaikan:
    itu yang terjadi sesudah redirect galat validasi, dan tombolnya akan tampak rusak
    karena tak memindahkan ke mana-mana.
--}}
@php
    $tujuanKembali = $ke ?? null;

    if (! $tujuanKembali) {
        $sebelumnya = url()->previous();
        $tujuanKembali = ($sebelumnya && $sebelumnya !== request()->fullUrl() && $sebelumnya !== url()->current())
            ? $sebelumnya
            : ($cadangan ?? url('/'));
    }
@endphp

<a href="{{ $tujuanKembali }}" class="btn btn-sm btn-outline-secondary">&larr; {{ $label ?? 'Kembali' }}</a>
