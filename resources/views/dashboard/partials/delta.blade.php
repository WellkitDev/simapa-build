{{-- resources/views/dashboard/partials/delta.blade.php — indikator naik/turun --}}
@php
    $dir  = $delta['dir'] ?? 'flat';
    $dcls = $dir === 'up' ? 'text-success' : ($dir === 'down' ? 'text-danger' : 'text-muted');
    $dic  = $dir === 'up' ? 'arrow-up' : ($dir === 'down' ? 'arrow-down' : 'minus');
    $dtxt = (isset($delta['pct']) && $delta['pct'] !== null)
        ? $delta['pct'] . '% vs periode lalu'
        : ($dir === 'up' ? 'baru' : '—');
@endphp
<p class="{{ $dcls }} mb-0 mt-2" style="font-size:12px">
    <i data-feather="{{ $dic }}" class="icon-sm mb-1"></i> {{ $dtxt }}
</p>
