{{-- Dua donut "judul unik per tahap": Buku & Artikel.
     Butuh: $stats (['buku'=>['labels','series'],'artikel'=>[...]]) + $idPrefix (string unik).
     Bergantung pada SimapaCharts (dashboard-charts.js) + apexcharts yang dimuat parent. --}}
<div class="row">
    <div class="col-md-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Judul Buku per Tahap</h6>
            <div id="{{ $idPrefix }}BukuChart"></div>
        </div></div>
    </div>
    <div class="col-md-6 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Judul Artikel per Tahap</h6>
            <div id="{{ $idPrefix }}ArtikelChart"></div>
        </div></div>
    </div>
</div>
@push('custom-scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var C = window.SimapaCharts;
    var buku = { labels: @json($stats['buku']['labels']), series: @json($stats['buku']['series']) };
    var art  = { labels: @json($stats['artikel']['labels']), series: @json($stats['artikel']['series']) };
    C.render('#{{ $idPrefix }}BukuChart',    C.donut(buku, 'Judul Buku'),    buku.series);
    C.render('#{{ $idPrefix }}ArtikelChart', C.donut(art,  'Judul Artikel'), art.series);
});
</script>
@endpush
