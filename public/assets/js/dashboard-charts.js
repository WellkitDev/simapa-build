// public/assets/js/dashboard-charts.js
// Palet & opsi ApexCharts bersama untuk seluruh dashboard.
// "Hijau" harus berarti hal yang sama di mana pun.
window.SimapaCharts = (function () {
    var PALETTE = {
        primary: '#6571ff',
        success: '#05a34a',
        warning: '#fbbc06',
        danger:  '#ff3366',
        dark:    '#0c1427',
        info:    '#0dcaf0',
    };

    function rp(v) { return 'Rp ' + Number(v).toLocaleString('id-ID'); }

    // Batasi jumlah label sumbu-X biar tidak "semut" di rentang panjang.
    function tickFor(n) { return n <= 14 ? n : (n <= 31 ? 10 : 12); }

    function slice(o, n) { return { labels: o.labels.slice(-n), series: o.series.slice(-n) }; }

    function isEmpty(series) {
        return !series || series.length === 0 || series.every(function (v) { return !v; });
    }

    function emptyState(el, msg) {
        el.innerHTML = '<div class="text-center text-muted py-5" style="font-size:13px">'
            + (msg || 'Belum ada data') + '</div>';
    }

    function area(name, d, color, isCurrency) {
        return {
            chart: { type: 'area', height: 240, toolbar: { show: false } },
            series: [{ name: name, data: d.series }],
            xaxis: {
                categories: d.labels,
                tickAmount: tickFor(d.series.length),
                tickPlacement: 'on',
                labels: { rotate: -45, rotateAlways: false, hideOverlappingLabels: true, trim: false, style: { fontSize: '11px' } },
                axisTicks: { show: false },
            },
            yaxis: { labels: { formatter: function (v) { return isCurrency ? Number(v).toLocaleString('id-ID') : Math.round(v); } } },
            dataLabels: { enabled: false },
            stroke: { curve: 'smooth', width: 2 },
            colors: [color],
            fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.1 } },
            markers: { size: 0, hover: { size: 5 } },
            grid: { borderColor: '#f1f1f1', strokeDashArray: 4, padding: { left: 8, right: 8 } },
            tooltip: isCurrency ? { y: { formatter: rp } } : {},
        };
    }

    function donut(data, totalLabel) {
        return {
            chart: { type: 'donut', height: 260 },
            series: data.series,
            labels: data.labels,
            colors: [PALETTE.primary, PALETTE.success, PALETTE.warning, PALETTE.danger, PALETTE.info, PALETTE.dark],
            legend: { position: 'bottom' },
            dataLabels: { enabled: true, formatter: function (v) { return Math.round(v) + '%'; } },
            plotOptions: { pie: { donut: { labels: { show: true, total: { show: true, label: totalLabel || 'Total' } } } } },
        };
    }

    /** Render dengan penjagaan keadaan kosong. Mengembalikan chart atau null. */
    function render(selector, opts, series) {
        var el = document.querySelector(selector);
        if (!el) return null;
        if (isEmpty(series)) { emptyState(el); return null; }
        var c = new ApexCharts(el, opts);
        c.render();
        return c;
    }

    /** Pasang toggle 7/30/90 pada sekumpulan chart area. */
    function rangeToggle(toggleSelector, charts, full) {
        document.querySelectorAll(toggleSelector + ' [data-range]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var n = +this.dataset.range;
                charts.forEach(function (pair) {
                    if (!pair.chart) return;
                    var s = slice(full[pair.key], n);
                    pair.chart.updateOptions({
                        xaxis: { categories: s.labels, tickAmount: tickFor(s.series.length) },
                        series: [{ data: s.series }],
                    });
                });
                document.querySelectorAll(toggleSelector + ' [data-range]').forEach(function (b) {
                    b.classList.remove('btn-primary', 'active');
                    b.classList.add('btn-outline-primary');
                });
                this.classList.remove('btn-outline-primary');
                this.classList.add('btn-primary', 'active');
            });
        });
    }

    return {
        PALETTE: PALETTE, rp: rp, slice: slice, tickFor: tickFor,
        area: area, donut: donut, render: render, rangeToggle: rangeToggle,
        isEmpty: isEmpty, emptyState: emptyState,
    };
})();
