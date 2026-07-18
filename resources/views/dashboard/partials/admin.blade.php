{{-- resources/views/dashboard/partials/admin.blade.php
     Papan kerja dokumen/data. Tanpa angka uang: admin tidak berwenang atas order/pembayaran.
     "Judul menunggu approve" sengaja tidak ada — approve dijaga role:superadmin|manager,
     jadi angka itu hanya jadi hitungan yang admin tak bisa apa-apakan. --}}
<div class="d-flex justify-content-between align-items-center flex-wrap grid-margin">
    <h4 class="mb-0">Dashboard Admin</h4>
</div>

<h6 class="text-muted mb-2">Pekerjaan Dokumen</h6>
<div class="row">
    @php
        $cards = [
            ['Dokumen Belum Lengkap', $adm['doc_belum_lengkap'], 'danger', 'file-text', route('title.index')],
            ['Arsip Menunggu Artefak', $adm['arsip_menunggu_artefak'], 'warning', 'archive', route('archive.index')],
            ['Arsip Diajukan', $adm['arsip_diajukan'], 'info', 'send', route('archive.index')],
            ['Submission Jurnal Aktif', $adm['jurnal_submission_aktif'], 'primary', 'book-open', route('journal.index')],
            ['Pengumuman Aktif', $adm['pengumuman_aktif'], 'success', 'bell', route('announcement.index')],
        ];
    @endphp
    @foreach($cards as [$label, $val, $tone, $icon, $url])
        <div class="col-md-4 col-xl grid-margin stretch-card">
            <div class="card"><div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="card-title mb-0" style="font-size:12px">{{ $label }}</h6>
                    <i data-feather="{{ $icon }}" class="icon-sm text-{{ $tone }}"></i>
                </div>
                <h3 class="mt-2 mb-0 text-{{ $tone }}">{{ $val }}</h3>
                <a href="{{ $url }}" class="small">Buka &rarr;</a>
            </div></div>
        </div>
    @endforeach
</div>

<h6 class="text-muted mb-2 mt-2">Registri ISBN</h6>
<div class="row">
    @php
        $isbn = [
            ['Pendaftaran', $adm['isbn_pendaftaran'], 'warning'],
            ['Ber-ISBN', $adm['isbn_ber_isbn'], 'primary'],
            ['Cetak/Terbit', $adm['isbn_cetak'], 'success'],
        ];
    @endphp
    @foreach($isbn as [$label, $val, $tone])
        <div class="col-md-4 grid-margin stretch-card">
            <div class="card"><div class="card-body">
                <h6 class="card-title mb-0" style="font-size:12px">{{ $label }}</h6>
                <h3 class="mt-2 mb-0 text-{{ $tone }}">{{ $val }}</h3>
            </div></div>
        </div>
    @endforeach
    <div class="col-12 text-end grid-margin">
        <a href="{{ route('isbn.index') }}" class="small">Buka Direktori ISBN &rarr;</a>
    </div>
</div>

<h6 class="text-muted mb-2 mt-2">Produksi Global</h6>
<div class="row">
    @php
        $prodCards = [
            ['Naskah Dalam Produksi', $global['total_in_production'], 'primary'],
            ['Lewat Target', $global['lewat_target'], 'danger'],
            ['Jatuh Tempo ≤7 hari', $global['jatuh_tempo_7'], 'warning'],
            ['Selesai Bulan Ini', $global['selesai_bulan_ini'], 'success'],
        ];
    @endphp
    @foreach($prodCards as [$label, $val, $tone])
        <div class="col-md-3 grid-margin stretch-card">
            <div class="card"><div class="card-body">
                <h6 class="card-title mb-0" style="font-size:12px">{{ $label }}</h6>
                <h3 class="mt-2 mb-0 text-{{ $tone }}">{{ $val }}</h3>
            </div></div>
        </div>
    @endforeach
</div>
<div class="row">
    <div class="col-md-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Naskah per Tahap</h6>
            <div id="admStageChart"></div>
        </div></div>
    </div>
</div>

@push('plugin-scripts')
    <script src="{{ asset('assets/plugins/apexcharts/apexcharts.min.js') }}"></script>
    <script src="{{ asset('assets/js/dashboard-charts.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var C = window.SimapaCharts;
    var d = { labels: @json($global['per_stage']['labels']), series: @json($global['per_stage']['series']) };
    C.render('#admStageChart', C.donut(d, 'Dalam Produksi'), d.series);
});
</script>
@endpush
