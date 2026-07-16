@if($gap['hasGap'])
    <div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
        <span aria-hidden="true">⚠</span>
        <div>
            <strong>Belum ada pengeluaran tercatat {{ $periodeLabel }}.</strong>
            Angka laba di bawah adalah <strong>pemasukan kotor</strong> — belum dikurangi biaya apa pun.
            @if($gap['fixedMonthly'] > 0)
                Menurut <a href="{{ route('accounting.assumption') }}">Asumsi</a>, ada
                <strong>Rp {{ number_format($gap['fixedMonthly'], 0, ',', '.') }}/bulan</strong>
                biaya tetap yang belum masuk Jurnal Kas.
            @endif
            <a href="{{ route('accounting.journal') }}">Catat pengeluaran di Jurnal Kas &rarr;</a>
        </div>
    </div>
@endif
