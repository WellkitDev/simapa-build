$(function () {
    "use strict";

    // Warna tema template (biar tetap konsisten)
    var colors = {
        primary: "#6571ff",
        secondary: "#7987a1",
        success: "#05a34a",
        info: "#66d1d1",
        warning: "#fbbc06",
        danger: "#ff3366",
        light: "#e9ecef",
        dark: "#060c17",
        muted: "#7987a1",
        gridBorder: "rgba(77, 138, 240, .15)",
        bodyColor: "#000",
        cardBg: "#fff",
    };

    var fontFamily = "'Roboto', Helvetica, sans-serif";

    // === 1. Mini Sparkline: New Orders (di card kecil) ===
    if ($("#ordersChart").length) {
        var ordersChartOptions = {
            chart: {
                type: "line",
                height: 80,
                sparkline: { enabled: true },
            },
            series: [
                {
                    name: "Orders",
                    data: window.ordersSparklineData || [
                        35, 48, 42, 65, 58, 70, 82,
                    ], // fallback kalau JS error
                },
            ],
            stroke: { width: 3, curve: "smooth" },
            colors: [colors.success],
            tooltip: { enabled: false },
        };
        new ApexCharts(
            document.querySelector("#ordersChart"),
            ordersChartOptions
        ).render();
    }

    // === 2. Revenue Chart (30 hari terakhir) ===
    if ($("#revenueChart").length) {
        var revenueOptions = {
            chart: {
                type: "area",
                height: 350,
                parentHeightOffset: 0,
                foreColor: colors.bodyColor,
                background: colors.cardBg,
                toolbar: { show: true },
                export: {
                    csv: { filename: "Revenue_{{ ucfirst($period) }}" },
                    png: { filename: "Revenue_{{ ucfirst($period) }}" },
                },
            },
            theme: { mode: "light" },
            tooltip: { theme: "light" },
            colors: [colors.primary],
            grid: {
                borderColor: colors.gridBorder,
                padding: { bottom: -4 },
                xaxis: { lines: { show: true } },
            },
            series: [
                {
                    name: "Revenue",
                    data: window.revenueData || [], // di-inject dari Blade
                },
            ],
            xaxis: {
                categories: window.revenueLabels || [],
                type: "category",
                axisBorder: { color: colors.gridBorder },
                axisTicks: { color: colors.gridBorder },
                labels: { style: { fontFamily: fontFamily } },
            },
            yaxis: {
                title: {
                    text: "Revenue (Rp)",
                    style: { color: colors.muted, fontSize: 11 },
                },
                labels: {
                    formatter: function (val) {
                        return val >= 1000000
                            ? "Rp" + (val / 1000000).toFixed(1) + "jt"
                            : "Rp" + val.toLocaleString();
                    },
                },
            },
            fill: { opacity: 0.1 },
            stroke: { width: 3, curve: "smooth" },
            dataLabels: { enabled: false },
            markers: { size: 0 },
        };
        new ApexCharts(
            document.querySelector("#revenueChart"),
            revenueOptions
        ).render();
    }

    // === 3. Monthly Sales Chart (12 bulan terakhir) ===
    if ($("#monthlySalesChart").length) {
        var salesOptions = {
            chart: {
                type: "bar",
                height: 380,
                parentHeightOffset: 0,
                foreColor: colors.bodyColor,
                background: colors.cardBg,
                toolbar: { show: true },
            },
            theme: { mode: "light" },
            tooltip: { theme: "light" },
            colors: [colors.primary],
            grid: {
                borderColor: colors.gridBorder,
                padding: { bottom: -4 },
                xaxis: { lines: { show: true } },
            },
            series: [
                {
                    name: "Jumlah Order",
                    data: window.monthlySalesData || [],
                },
            ],
            xaxis: {
                categories: window.monthlySalesLabels || [],
                axisBorder: { color: colors.gridBorder },
                axisTicks: { color: colors.gridBorder },
                labels: { style: { fontFamily: fontFamily } },
            },
            yaxis: {
                title: {
                    text: "Jumlah Order",
                    style: { color: colors.muted, fontSize: 11 },
                },
                labels: { style: { fontFamily: fontFamily } },
            },
            plotOptions: {
                bar: {
                    borderRadius: 6,
                    columnWidth: "45%",
                    dataLabels: { position: "top" },
                },
            },
            dataLabels: {
                enabled: true,
                offsetY: -25,
                style: {
                    fontSize: "11px",
                    fontFamily: fontFamily,
                    colors: [colors.bodyColor],
                },
            },
            stroke: { width: 0 },
            legend: { show: false },
        };
        new ApexCharts(
            document.querySelector("#monthlySalesChart"),
            salesOptions
        ).render();
    }

    // === 4. Date Picker (tetap dipertahankan) ===
    if ($("#dashboardDate").length) {
        flatpickr("#dashboardDate", {
            wrap: true,
            dateFormat: "d-M-Y",
            defaultDate: "today",
        });
    }
});
