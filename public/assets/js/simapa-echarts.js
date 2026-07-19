// public/assets/js/simapa-echarts.js
// Helper Apache ECharts untuk dashboard superadmin/manager.
// Palet dipinjam dari SimapaCharts agar "hijau berarti sama" di seluruh app.
window.SimapaECharts = (function () {
    var P = (window.SimapaCharts && window.SimapaCharts.PALETTE) || {
        primary: '#6571ff', success: '#05a34a', warning: '#fbbc06',
        danger: '#ff3366', dark: '#0c1427', info: '#0dcaf0',
    };
    var INK = '#7987a1', GRID = '#f1f1f1', AXIS = '#e8ebf3';

    function rupiah(v) { return 'Rp ' + Number(v || 0).toLocaleString('id-ID'); }
    function count(v) { return Number(v || 0).toLocaleString('id-ID'); }

    function isEmptyRows(rows, keys) {
        if (!rows || !rows.length) return true;
        return rows.every(function (r) { return keys.every(function (k) { return !r[k]; }); });
    }
    function emptyState(el) {
        el.innerHTML = '<div class="text-center text-muted py-5" style="font-size:13px">Belum ada data</div>';
    }

    /** Init aman-kosong + auto-resize. Kembalikan chart atau null. */
    function init(selector, option, rows, keys) {
        var el = document.querySelector(selector);
        if (!el) return null;
        if (rows !== undefined && isEmptyRows(rows, keys || [])) { emptyState(el); return null; }
        var c = echarts.init(el);
        c.setOption(option);
        window.addEventListener('resize', function () { c.resize(); });
        return c;
    }

    /**
     * Satu dataset (object-array) → DUA grid bertumpuk yang BERBAGI dataset (Share Dataset).
     * Bukan dual-axis: tiap grid punya satu skala-Y jujur sendiri.
     * source: array of objects. xDim: nama field kategori.
     * top/bottom: { title, money:bool, max?:number, series:[{name,dim,color}] }
     */
    function sharedDualGrid(source, xDim, top, bottom) {
        var fmtByName = {};
        top.series.forEach(function (s) { fmtByName[s.name] = top.money ? rupiah : count; });
        bottom.series.forEach(function (s) { fmtByName[s.name] = bottom.money ? rupiah : count; });

        function mkSeries(cfg, axisIndex) {
            return cfg.series.map(function (s) {
                return {
                    type: 'bar', name: s.name,
                    xAxisIndex: axisIndex, yAxisIndex: axisIndex,
                    encode: { x: xDim, y: s.dim },
                    seriesLayoutBy: 'column', // tiap kolom dataset = satu seri
                    barMaxWidth: 26,
                    itemStyle: { color: s.color, borderRadius: [4, 4, 0, 0] },
                };
            });
        }

        return {
            animationDuration: 750, animationEasing: 'cubicOut',
            legend: { top: 0, textStyle: { color: INK },
                      data: top.series.concat(bottom.series).map(function (s) { return s.name; }) },
            tooltip: {
                trigger: 'item',
                formatter: function (p) {
                    var f = fmtByName[p.seriesName] || count;
                    var dim = p.dimensionNames[p.encode.y[0]];
                    return p.marker + p.name + ' — ' + p.seriesName + '<br/><b>' + f(p.data[dim]) + '</b>';
                },
            },
            dataset: { source: source },
            // Kedua grid pakai `left` identik & TANPA containLabel supaya kolom kategori
            // (marketing/staf) di grid atas & bawah sejajar vertikal — inti "bandingkan
            // per baris". containLabel akan memberi inset kiri berbeda (label Rp lebar vs
            // angka hitungan sempit) sehingga batang tak sebaris. `left` cukup untuk label Rp.
            grid: [
                { left: 100, right: 16, top: 40, height: '34%' },
                { left: 100, right: 16, bottom: 44, height: '30%' },
            ],
            xAxis: [
                { type: 'category', gridIndex: 0, axisTick: { show: false },
                  axisLine: { lineStyle: { color: AXIS } }, axisLabel: { show: false } },
                { type: 'category', gridIndex: 1, axisTick: { show: false },
                  axisLine: { lineStyle: { color: AXIS } },
                  axisLabel: { color: INK, interval: 0, rotate: 30, fontSize: 10 } },
            ],
            yAxis: [
                { type: 'value', gridIndex: 0, name: top.title, nameTextStyle: { color: INK },
                  splitLine: { lineStyle: { color: GRID, type: 'dashed' } },
                  axisLabel: { color: INK, fontSize: 10, formatter: top.money ? function (v) { return rupiah(v); } : function (v) { return count(v); } } },
                { type: 'value', gridIndex: 1, name: bottom.title, nameTextStyle: { color: INK }, max: bottom.max,
                  splitLine: { lineStyle: { color: GRID, type: 'dashed' } },
                  axisLabel: { color: INK, fontSize: 10, formatter: bottom.money ? function (v) { return rupiah(v); } : function (v) { return count(v); } } },
            ],
            series: mkSeries(top, 0).concat(mkSeries(bottom, 1)),
        };
    }

    /**
     * Area tren tunggal (Simple Example of Dataset).
     * labels:[...], values:[...] → source object-array {d, v}.
     */
    function areaTrend(labels, values, color, money) {
        var source = labels.map(function (l, i) { return { d: l, v: values[i] }; });
        var tickInterval = labels.length > 31 ? Math.ceil(labels.length / 12) : (labels.length > 14 ? Math.ceil(labels.length / 10) : 0);
        return {
            animationDuration: 750, animationEasing: 'cubicOut',
            color: [color],
            dataset: { source: source },
            grid: { left: 8, right: 16, top: 16, bottom: 8, containLabel: true },
            tooltip: { trigger: 'axis', valueFormatter: money ? rupiah : count },
            xAxis: { type: 'category', boundaryGap: false, axisTick: { show: false },
                     axisLine: { lineStyle: { color: AXIS } },
                     axisLabel: { color: INK, interval: tickInterval, rotate: -45, fontSize: 10 } },
            yAxis: { type: 'value', splitLine: { lineStyle: { color: GRID, type: 'dashed' } },
                     axisLabel: { color: INK, formatter: money ? function (v) { return rupiah(v); } : function (v) { return count(v); } } },
            series: [{
                type: 'line', name: money ? 'Pemasukan' : 'Order',
                encode: { x: 'd', y: 'v' }, smooth: true, showSymbol: false,
                lineStyle: { width: 2, color: color },
                areaStyle: { color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                    { offset: 0, color: color + '66' }, { offset: 1, color: color + '05' },
                ]) },
            }],
        };
    }

    return {
        PALETTE: P, rupiah: rupiah, count: count,
        init: init, isEmptyRows: isEmptyRows, emptyState: emptyState,
        sharedDualGrid: sharedDualGrid, areaTrend: areaTrend,
    };
})();
