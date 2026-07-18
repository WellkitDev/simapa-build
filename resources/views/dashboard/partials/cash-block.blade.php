{{-- resources/views/dashboard/partials/cash-block.blade.php — superadmin saja.
     Butuh: $cash = ['year' => int, 'ytd' => [...], 'gap' => [...]] --}}
<h6 class="text-muted mb-2 mt-2">Kas &amp; Laba ({{ $cash['year'] }})</h6>

@include('accounting.partials.expense-warning', ['gap' => $cash['gap'], 'periodeLabel' => 'sepanjang ' . $cash['year']])

<div class="row">
    @php
        $cards = [
            ['Saldo Akhir', $cash['ytd']['saldoAkhir'], 'primary', 'credit-card'],
            ['Laba Tahun Berjalan', $cash['ytd']['laba'], 'success', 'trending-up'],
            ['Pemasukan YTD', $cash['ytd']['totalIn'], 'info', 'arrow-down-circle'],
            ['Pengeluaran YTD', $cash['ytd']['totalOut'], 'warning', 'arrow-up-circle'],
        ];
    @endphp
    @foreach($cards as [$label, $val, $tone, $icon])
        <div class="col-md-3 grid-margin stretch-card">
            <div class="card"><div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h6 class="card-title mb-0">{{ $label }}</h6>
                        <h4 class="mt-2 mb-0 text-{{ $tone }}">Rp {{ number_format((int) $val, 0, ',', '.') }}</h4>
                    </div>
                    <div class="bg-{{ $tone }} bg-opacity-10 rounded p-2">
                        <i data-feather="{{ $icon }}" class="text-{{ $tone }}"></i>
                    </div>
                </div>
            </div></div>
        </div>
    @endforeach
</div>

<div class="text-end grid-margin">
    <a href="{{ route('accounting.journal') }}" class="small">Buka Jurnal Kas &rarr;</a>
</div>
