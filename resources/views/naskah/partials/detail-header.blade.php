{{--
    Header Detail Naskah: kode order sebagai identitas utama, judul pendamping,
    dan banner grup yang membuat aksi serempak terlihat sebelum tombol ditekan
    (keputusan "grup transparan + drill-down").
--}}
<div class="card"><div class="card-body">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <span class="fw-bold text-primary" style="font-size:1.05rem">{{ $kode }}</span>
            <span class="badge bg-primary-subtle text-primary">
                {{ $buku ? ($d?->type === 'bk_kolab' ? 'Buku Kolaborasi' : 'Buku Mandiri') : 'Artikel' }}
            </span>
            @if ($d?->indexation)
                <span class="badge bg-light text-dark border">{{ $d->indexation }}</span>
            @endif
            @if ($isKolab)
                @php $jumlahBab = $d?->titleRef?->chapters()->count() ?? (int) $d?->chapters; @endphp
                <span class="badge bg-light text-dark border">
                    {{ $jumlahBab }} bab · {{ $jumlahAuthorBab ?? 0 }} author
                </span>
            @endif
            {{-- Naskah dibuatkan vs dikirim author menentukan apakah tahap Pembuatan
                 relevan sama sekali — ditaruh sejajar jenis naskah, bukan disembunyikan. --}}
            <span class="badge {{ $naskahMandiri ? 'bg-secondary-subtle text-secondary border' : 'bg-warning-subtle text-warning border' }}">
                {{ $d?->naskahTypeLabel() ?? '—' }}
            </span>
            @if ($progress->priority === 'high')
                <span class="badge bg-danger">Prioritas High</span>
            @endif

            <h5 class="mt-2 mb-1">{{ $d?->title ?? '—' }}</h5>
            <div class="text-muted small">
                @if ($d?->authors->isNotEmpty())
                    Author: {{ $d->authors->pluck('name')->join(', ') }} ·
                @endif
                Order oleh marketing: {{ $d?->order?->user?->name ?? '—' }}
                @if ($d?->order?->ordered_at)
                    · {{ \Illuminate\Support\Carbon::parse($d->order->ordered_at)->translatedFormat('j M Y') }}
                @endif
            </div>
        </div>
        <a href="{{ route('naskah.pelacakan', ['tipe' => $buku ? 'buku' : 'artikel', 'view' => 'riwayat']) }}"
           class="btn btn-outline-secondary btn-sm text-nowrap">Riwayat Lengkap</a>
    </div>

    @if ($isKolab)
        <div class="alert alert-info small mt-3 mb-0">
            Status buku ini adalah <strong>roll-up otomatis</strong> dari bab paling belakang.
            Saat ini: <strong>{{ $progress->stageLabelId() }}</strong>. Tahap Layout → Terbit
            terbuka setelah <strong>semua bab Selesai</strong>.
        </div>
    @endif

    @if ($grup->count() > 1)
        <div class="alert alert-info small mt-3 mb-0">
            Judul ini mencakup <strong>{{ $grup->count() }} order</strong>. Aksi tahap berlaku
            serempak untuk semuanya.
            <details class="mt-1">
                <summary class="text-decoration-underline" style="cursor:pointer">Lihat rincian per order</summary>
                <ul class="mb-0 mt-2">
                    @foreach ($grup as $g)
                        <li>
                            {{ $g->orderDetail?->order?->code_order ?? '—' }}
                            — {{ $g->stageLabelId() }}
                            @if ($g->orderDetail?->order?->user)
                                · marketing: {{ $g->orderDetail->order->user->name }}
                            @endif
                        </li>
                    @endforeach
                </ul>
            </details>
        </div>
    @endif
</div></div>
