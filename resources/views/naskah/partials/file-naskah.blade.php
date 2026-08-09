{{--
    File per slot tahap. Slot `masuk` adalah bukti kerja yang memicu maju tahap
    otomatis — karena itu keterangannya ditulis eksplisit di formnya.
--}}
<div class="card mb-3"><div class="card-body">
    <h6 class="text-uppercase text-muted small fw-bold mb-2">
        {{ $isKolab ? 'File Level Buku' : 'File Naskah' }}
    </h6>

    @foreach (\App\Models\ManuscriptFile::SLOTS as $slot => $label)
        @php $versi = $berkas[$slot] ?? collect(); @endphp
        <div class="d-flex justify-content-between align-items-center border-bottom border-dashed py-2 small">
            <span class="{{ $versi->isEmpty() ? 'text-muted' : '' }}">
                📄 {{ $label }}
                @if ($versi->isNotEmpty())
                    @php $t = $versi->first(); @endphp
                    — v{{ $t->version }} · {{ $t->uploader?->name ?? '—' }}
                    · {{ $t->created_at?->translatedFormat('j M Y') }}
                @else
                    — belum ada
                @endif
            </span>
            @if ($versi->isNotEmpty() && $versi->first()->drive_url)
                <a href="{{ $versi->first()->drive_url }}" target="_blank" rel="noopener">Unduh</a>
            @endif
        </div>
    @endforeach

    @if ($izin['upload'] && ! $progress->cancelled_at)
        <form method="POST" action="{{ route('naskah.file', $progress->order_detail_id) }}"
              enctype="multipart/form-data" class="mt-3">
            @csrf
            <label class="form-label small fw-bold mb-1">Unggah file</label>
            <select name="slot" class="form-select form-select-sm mb-2" required>
                @foreach (\App\Models\ManuscriptFile::SLOTS as $slot => $label)
                    <option value="{{ $slot }}">{{ $label }}</option>
                @endforeach
            </select>
            <input type="file" name="file" class="form-control form-control-sm mb-2"
                   accept=".pdf,.doc,.docx,.zip" required>
            <button class="btn btn-sm btn-outline-primary">⬆ Unggah</button>
            <div class="form-text">
                Mengunggah <strong>Naskah Masuk</strong> saat tahap Pembuatan otomatis memajukan
                naskah ke Editing dan mengabari PJ.
            </div>
        </form>
    @endif
</div></div>
