@extends('layouts.master')

@push('plugin-styles')
    <link href="{{ asset('assets/plugins/flatpickr/flatpickr.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@include('dashboard.partials.announcements')
@include('dashboard.partials.deadlines')
@switch($dashboardView ?? 'none')
    @case('production') @include('dashboard.partials.production') @break
    @case('sales')      @include('dashboard.partials.sales') @break
    @case('company')    @include('dashboard.partials.company') @break
    @case('admin')      @include('dashboard.partials.admin') @break
    @case('accounting') @include('dashboard.partials.accounting') @break
    @default
        <div class="card"><div class="card-body">
            <h4 class="mb-1">Dashboard</h4>
            <p class="text-muted mb-0">Belum ada ringkasan untuk akun ini. Hubungi admin bila ini keliru.</p>
        </div></div>
@endswitch
@endsection

@push('plugin-scripts')
    <script src="{{ asset('assets/plugins/flatpickr/flatpickr.min.js') }}"></script>
    <script src="{{ asset('assets/plugins/apexcharts/apexcharts.min.js') }}"></script>
@endpush
