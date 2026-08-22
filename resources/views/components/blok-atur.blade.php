{{--
    Blok kolom kiri yang bisa disusun ulang, dilipat, dan ditempelkan.

    Kepalanya seragam untuk semua blok — itu yang membuat susunannya terbaca sebagai
    satu sistem, bukan empat kartu yang kebetulan bertumpuk. Isinya bebas.

    Preferensinya milik PERAMBAN, bukan akun: susunan blok adalah kenyamanan pribadi
    di satu layar, bukan data kerja. Menyimpannya di server berarti satu permintaan
    tiap kali orang menggeser kartu, ditambah route dan pemetaan izin, demi hal yang
    tak seorang pun perlu lihat selain pemiliknya sendiri.

    @props
      id    — kunci penyimpanan; HARUS stabil, karena ia yang mengikat preferensi lama
      judul — teks kepala
--}}
@props(['id', 'judul'])

<div class="card mb-3 blok-atur" data-blok="{{ $id }}">
    <div class="card-header bg-white d-flex align-items-center gap-2 py-2">
        <span class="blok-atur-pegangan text-muted" title="Geser untuk menyusun ulang"
              aria-hidden="true">&#x2059;</span>

        <h6 class="text-uppercase text-muted small fw-bold mb-0 flex-grow-1">{{ $judul }}</h6>

        <button type="button" class="btn btn-sm btn-link p-0 text-muted blok-atur-pin"
                aria-pressed="false" title="Tempelkan blok ini saat menggulir">
            <span aria-hidden="true">&#128204;</span>
            <span class="visually-hidden">Tempelkan {{ $judul }}</span>
        </button>

        <button type="button" class="btn btn-sm btn-link p-0 text-muted blok-atur-lipat"
                aria-expanded="true" title="Sembunyikan isi blok">
            <span class="blok-atur-tanda" aria-hidden="true">&minus;</span>
            <span class="visually-hidden">Lipat {{ $judul }}</span>
        </button>
    </div>

    <div class="card-body blok-atur-isi">
        {{ $slot }}
    </div>
</div>
