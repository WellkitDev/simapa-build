{{-- resources/views/dashboard/partials/delta.blade.php — indikator naik/turun.
     invertGood=true untuk metrik yang naiknya BURUK (mis. piutang). --}}
@php
    $dir    = $delta['dir'] ?? 'flat';
    $invert = $invertGood ?? false;
    $good   = $invert ? ($dir === 'down') : ($dir === 'up');
    $dcls   = $dir === 'flat' ? 'text-muted' : ($good ? 'text-success' : 'text-danger');
    $dic    = $dir === 'up' ? 'arrow-up' : ($dir === 'down' ? 'arrow-down' : 'minus');

    if (! isset($delta['pct']) || $delta['pct'] === null) {
        $dtxt = $dir === 'up' ? 'baru' : '—';
        $dttl = null;
    } elseif ($delta['capped'] ?? false) {
        $dtxt = '>999% vs periode lalu';
        $dttl = $delta['pct'] . '% vs periode lalu';
    } else {
        $dtxt = $delta['pct'] . '% vs periode lalu';
        $dttl = null;
    }
@endphp
<p class="{{ $dcls }} mb-0 mt-2" style="font-size:12px" @if($dttl) title="{{ $dttl }}" @endif>
    <i data-feather="{{ $dic }}" class="icon-sm mb-1"></i> {{ $dtxt }}
</p>
