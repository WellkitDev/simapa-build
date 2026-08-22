{{--
    Panel rincian satu tahap. Semua dirender, satu ditampilkan.

    HANYA-BACA. Mengubah tahap yang sudah lewat tetap lewat Koreksi — satu-satunya pintu
    resmi, superadmin, wajib catatan. Panel ini menautkan ke pemilik datanya, tak
    menyalin formulirnya: empat salinan aturan validasi akan bercabang diam-diam.

    Sandi OJS tak pernah ada di sini. Halaman ini terbuka untuk semua role.
--}}
@foreach ($stages as $stage)
    @php $r = $rincian[$stage] ?? null; @endphp
    @continue(! $r)

    <div data-rincian="{{ $stage }}" id="rincian-{{ $stage }}" hidden
         class="border rounded p-3 mt-3 bg-light">

        <div class="d-flex justify-content-between align-items-start mb-2">
            <h6 class="mb-0">{{ \App\Models\TitleProgress::labelFor($stage) }}</h6>
            @if ($r['berjalan'])
                <span class="badge bg-primary">tahap berjalan</span>
            @elseif (! $r['dijalani'])
                <span class="badge bg-light text-muted border">belum dijalani</span>
            @endif
        </div>

        @if (! $r['dijalani'] && ! $r['berjalan'])
            <p class="small text-muted mb-0">
                Naskah belum pernah sampai ke tahap ini.
                @if (! empty($r['berkas']) || $stage === 'submit' || $stage === 'loa' || $stage === 'isbn')
                    Nanti tahap ini akan mencatat
                    @switch($stage)
                        @case('submit')  jurnal tujuan, tanggal submit, dan akun OJS. @break
                        @case('loa')     link artikel terbit dan tanggal terbitnya. @break
                        @case('isbn')    nomor ISBN beserta berkasnya. @break
                        @default         berkas keluaran tahap ini.
                    @endswitch
                @endif
            </p>
        @else
            {{-- Tiap kali naskah MASUK tahap ini = satu baris. Sejak mundur LoA→Revisi
                 ada, sebuah tahap bisa dijalani lebih dari sekali, dan justru kunjungan
                 berulang itulah riwayat yang paling menarik. --}}
            @foreach ($r['kunjungan'] as $k)
                <div class="small border-bottom border-dashed pb-2 mb-2">
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <strong>
                            {{ $k['masuk']?->translatedFormat('j M Y') ?? '—' }}
                            @if ($k['keluar'])
                                → {{ $k['keluar']->translatedFormat('j M Y') }}
                            @else
                                → sekarang
                            @endif
                        </strong>
                        <span class="text-muted">{{ $k['hari'] }} hari</span>
                        @if ($k['koreksi'])
                            <span class="badge bg-warning text-dark">koreksi</span>
                        @endif
                        @if (count($r['kunjungan']) > 1)
                            <span class="text-muted">· kunjungan ke-{{ $loop->iteration }}</span>
                        @endif
                    </div>
                    @if ($k['oleh'])
                        <div class="text-muted">oleh {{ $k['oleh'] }}</div>
                    @endif
                    @if ($k['catatan'])
                        <div class="fst-italic">"{{ $k['catatan'] }}"</div>
                    @endif
                </div>
            @endforeach
        @endif

        @if (! empty($r['data']))
            <dl class="row small mb-0 mt-2">
                @foreach ($r['data'] as $label => $isi)
                    <dt class="col-sm-4 text-muted fw-normal">{{ $label }}</dt>
                    <dd class="col-sm-8">
                        @if (str_starts_with((string) $isi, 'http'))
                            <a href="{{ $isi }}" target="_blank" rel="noopener">{{ $isi }}</a>
                        @else
                            {{ $isi }}
                        @endif
                    </dd>
                @endforeach
            </dl>
        @endif

        @if ($r['berkas']->isNotEmpty())
            <div class="mt-2">
                <div class="text-muted small fw-bold">Berkas tahap ini</div>
                @foreach ($r['berkas'] as $b)
                    <div class="small d-flex justify-content-between">
                        <span class="text-truncate me-2">
                            {{ \App\Models\ManuscriptFile::SLOTS[$b->slot] ?? $b->slot }} ·
                            {{ $b->original_name }}
                        </span>
                        @if ($b->status === 'selesai' && $b->drive_url)
                            <a href="{{ $b->drive_url }}" target="_blank" rel="noopener">buka</a>
                        @elseif ($b->status === 'antre')
                            <span class="text-muted text-nowrap">antre …</span>
                        @else
                            <span class="text-danger text-nowrap">gagal</span>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        <div class="d-flex flex-wrap gap-2 mt-3">
            @if ($r['tautan'])
                <a href="{{ $r['tautan']['url'] }}" class="btn btn-sm btn-outline-secondary">
                    {{ $r['tautan']['label'] }} →
                </a>
            @endif
            {{-- Pintasan ke pintu yang SUDAH ada, bukan pintu kedua: tombolnya membuka
                 formulir Koreksi di kartu Aksi dengan tahap ini terpilih. --}}
            @if (($izin['correct'] ?? false) && $r['dijalani'] && ! $r['berjalan'])
                <button type="button" class="btn btn-sm btn-outline-primary"
                        data-koreksi-ke="{{ $stage }}">
                    Koreksi ke tahap ini
                </button>
            @endif
        </div>
    </div>
@endforeach

{{--
    Skripnya ikut digerbangi izin, bukan hanya tombolnya.

    Dua sebab. Pertama, mengirim kode yang menyebut formulir Koreksi kepada orang yang
    tak boleh mengoreksi adalah bobot mati. Kedua — dan ini yang menangkapnya — sebuah
    tes lama memastikan admin biasa tak pernah menerima dropdown semua-tahap dengan
    mencari teks `name="status"` di halaman; selektor di dalam skrip ini memicunya.
    Tesnya benar: yang tak berhak memakai, tak perlu menerimanya.
--}}
@if ($izin['correct'] ?? false)
@push('custom-scripts')
<script>
    // Membuka formulir Koreksi yang sudah ada dan memilih tahapnya. Tak ada jalur
    // penyimpanan kedua — hanya jalan menuju pintu yang sama yang dipermudah.
    document.addEventListener('click', function (e) {
        const t = e.target.closest('[data-koreksi-ke]');
        if (!t) return;

        const form = document.getElementById('formKoreksi');
        const sel  = form && form.querySelector('select[name="status"]');
        if (!form || !sel) return;

        sel.value = t.dataset.koreksiKe;
        form.classList.add('show');
        form.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
</script>
@endpush
@endif
