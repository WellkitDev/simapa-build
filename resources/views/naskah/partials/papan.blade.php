{{--
    Papan zona (Layar 2/2B). Zona mengelompokkan tahap menurut SIAPA yang memegangnya,
    supaya orang tahu bagian mana yang urusannya tanpa membaca satu per satu.
    Kartu selalu duduk di kolom tahap paling belakang di antara order sejudul.
--}}
@php
    $warnaZona = [
        'z1' => ['bg' => '#f1f5f9', 'garis' => '#64748b', 'teks' => '#64748b'],
        'z2' => ['bg' => '#eef2ff', 'garis' => '#4c5fd5', 'teks' => '#4c5fd5'],
        'z3' => ['bg' => '#ecfdf5', 'garis' => '#16a34a', 'teks' => '#16a34a'],
        'z4' => ['bg' => '#f5f3ff', 'garis' => '#7c3aed', 'teks' => '#7c3aed'],
    ];
@endphp

<div class="d-flex gap-3 overflow-auto pb-2">
    @foreach ($zona as $z)
        @php $w = $warnaZona[$z['warna']]; @endphp
        <div class="rounded-3 p-2 flex-shrink-0"
             style="background:{{ $w['bg'] }};border-top:3px solid {{ $w['garis'] }}">
            <div class="fw-bold text-uppercase small mb-2" style="color:{{ $w['teks'] }};letter-spacing:.08em">
                {{ $z['label'] }}
                <span class="fw-normal text-muted text-lowercase" style="letter-spacing:0">· {{ $z['catatan'] }}</span>
            </div>
            <div class="d-flex gap-2">
                @foreach ($z['kolom'] as $status)
                    @php $isi = $kartu[$status] ?? collect(); @endphp
                    <div style="width:190px" class="flex-shrink-0">
                        <div class="d-flex align-items-center gap-2 mb-2 small fw-bold">
                            {{ \App\Models\TitleProgress::labelFor($status) }}
                            <span class="badge bg-white text-muted border">{{ $isi->count() }}</span>
                        </div>
                        <div class="rounded-2 p-1" style="background:rgba(255,255,255,.65);min-height:210px">
                            @forelse ($isi as $k)
                                @include('naskah.partials.kartu', ['k' => $k])
                            @empty
                                <div class="text-center text-muted small py-3">Kosong</div>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
