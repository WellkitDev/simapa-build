@extends('layouts.master')

@push('plugin-styles')
    <link href="{{ asset('assets/plugins/flatpickr/flatpickr.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@if(($dashboardView ?? 'financial') === 'production')
    @include('dashboard.partials.production')
@elseif(($dashboardView ?? 'financial') === 'marketing')
    @include('dashboard.partials.marketing')
@else
    <div class="d-flex justify-content-between align-items-center flex-wrap grid-margin">
        <h4 class="mb-0">Dashboard</h4>
    </div>

    <h6 class="text-muted mb-2">Ringkasan Pembayaran &amp; Order</h6>
    <div class="row">
        @php
            $cards = [
                ['Total Pembayaran',      number_format($data['total_payment'], 0, ',', '.'),       'primary', 'credit-card',   'paymentChart'],
                ['Total Order',           number_format($data['total_orders'], 0, ',', '.'),        'success', 'shopping-cart', 'ordersChart'],
                ['Total Pemasukan',       'Rp ' . number_format($data['total_income'], 0, ',', '.'), 'warning', 'dollar-sign',  'incomeChart'],
                ['Disetujui',             number_format($data['total_approve'], 0, ',', '.'),       'success', 'check-circle',  'approveChart'],
                ['Menunggu Persetujuan',  number_format($data['total_pending'], 0, ',', '.'),       'warning', 'clock',         'pendingChart'],
                ['Ditolak',               number_format($data['total_reject'], 0, ',', '.'),        'danger',  'x-circle',      'rejectChart'],
            ];
        @endphp
        @foreach($cards as [$title, $value, $tone, $icon, $chartId])
            <div class="col-md-4 grid-margin stretch-card">
                <div class="card"><div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <h6 class="card-title mb-0">{{ $title }}</h6>
                        <div class="bg-{{ $tone }} bg-opacity-10 rounded p-2">
                            <i data-feather="{{ $icon }}" class="text-{{ $tone }}"></i>
                        </div>
                    </div>
                    <div class="row align-items-center mt-2">
                        <div class="col-6 col-md-12 col-xl-5">
                            <h3 class="mb-0">{{ $value }}</h3>
                        </div>
                        <div class="col-6 col-md-12 col-xl-7">
                            <div id="{{ $chartId }}" class="mt-md-3 mt-xl-0"></div>
                        </div>
                    </div>
                </div></div>
            </div>
        @endforeach
    </div>
    @hasanyrole('manager|superadmin')
        @include('dashboard.partials.progress-global')
    @endhasanyrole
@endif
@endsection

@push('plugin-scripts')
    <script src="{{ asset('assets/plugins/flatpickr/flatpickr.min.js') }}"></script>
    <script src="{{ asset('assets/plugins/apexcharts/apexcharts.min.js') }}"></script>
@endpush

@push('custom-scripts')
@if(($dashboardView ?? 'financial') === 'financial')
    <script>
        $(function() {
            'use strict';

            const labels = @json($data['chart_labels']);

            function renderChart(selector, data, color, isCurrency = false) {
                var options = {
                    chart: {
                        type: "bar",
                        height: 60,
                        sparkline: { enabled: true }
                    },
                    plotOptions: {
                        bar: { borderRadius: 2, columnWidth: "60%" }
                    },
                    colors: [color],
                    series: [{ name: 'Total', data: data }],
                    xaxis: { categories: labels },
                    tooltip: {
                        fixed: { enabled: false },
                        y: {
                            formatter: function(val) {
                                if (isCurrency) {
                                    return "Rp " + val.toLocaleString('id-ID');
                                }
                                return val + " items";
                            }
                        }
                    }
                };
                new ApexCharts(document.querySelector(selector), options).render();
            }

            // Sparkline 30 hari per kartu; warna selaras tone kartu.
            renderChart("#paymentChart", @json($data['series_payment']), "#6571ff");
            renderChart("#ordersChart",  @json($data['series_order']),   "#05a34a");
            renderChart("#incomeChart",  @json($data['series_income']),  "#fbbc06", true);
            renderChart("#approveChart", @json($data['series_approve']), "#05a34a");
            renderChart("#pendingChart", @json($data['series_pending']), "#fbbc06");
            renderChart("#rejectChart",  @json($data['series_reject']),  "#ff3366");
        });
    </script>
@endif
@endpush
