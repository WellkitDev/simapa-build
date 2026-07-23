@foreach (\App\Models\ManuscriptFile::SLOTS as $slot => $label)
    <div class="mb-2">
        <div class="d-flex justify-content-between align-items-center">
            <strong style="font-size:12px">{{ $label }}</strong>
            @php $latest = optional($files[$slot] ?? collect())->first(); @endphp
            @if ($latest)
                <a href="{{ $latest->drive_url }}" target="_blank" class="badge bg-success text-white text-decoration-none">
                    v{{ $latest->version }} · {{ Str::limit($latest->original_name, 24) }}
                </a>
            @else
                <span class="text-muted" style="font-size:11px">belum ada</span>
            @endif
        </div>
        <form method="POST" action="{{ $uploadRoute }}" enctype="multipart/form-data" class="d-flex gap-1 mt-1">
            @csrf
            <input type="hidden" name="slot" value="{{ $slot }}">
            <input type="file" name="file" class="form-control form-control-sm" required>
            <button class="btn btn-sm btn-outline-primary">Unggah</button>
        </form>
        @if (($files[$slot] ?? collect())->count() > 1)
            <details class="mt-1"><summary style="font-size:11px" class="text-muted">Riwayat versi</summary>
                <ul class="mb-0 ps-3">
                    @foreach ($files[$slot] as $f)
                        <li style="font-size:11px"><a href="{{ $f->drive_url }}" target="_blank">v{{ $f->version }}</a> — {{ $f->original_name }} ({{ optional($f->created_at)->format('d/m/Y H:i') }})</li>
                    @endforeach
                </ul>
            </details>
        @endif
    </div>
@endforeach
