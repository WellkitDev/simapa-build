{{-- Riwayat: semua aksi tercatat, tanpa kecuali. Tidak ada jalur penghapusan. --}}
<div class="card"><div class="card-body">
    <h6 class="text-uppercase text-muted small fw-bold mb-3">Riwayat (semua aksi tercatat)</h6>

    @forelse ($logs as $log)
        <div class="border-bottom border-dashed py-2 small">
            <strong>{{ $log->created_at?->translatedFormat('j M Y H:i') ?? '—' }}
                · {{ $log->changedBy?->name ?? 'Sistem' }}</strong>
            — {{ $log->eventLabel() }}
            @if ($log->from_value || $log->to_value)
                <strong>{{ $log->from_value ?? '—' }} → {{ $log->to_value ?? '—' }}</strong>
            @endif
            @if ($log->is_correction)
                <span class="badge bg-warning text-dark">koreksi</span>
            @endif
            @if ($log->note)
                <div class="text-muted">{{ $log->note }}</div>
            @endif
        </div>
    @empty
        <p class="text-muted small mb-0">Belum ada aktivitas tercatat.</p>
    @endforelse
</div></div>
