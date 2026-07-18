@extends('layouts.master')
@section('title', 'Dashboard Keuangan - SiMAPA')

@section('content')
<div class="d-flex justify-content-end mb-2">
    <form method="GET" class="d-flex gap-2 align-items-center">
        <input type="number" name="year" value="{{ $year }}" class="form-control form-control-sm" style="width:100px">
        <button class="btn btn-sm btn-outline-secondary">Tahun</button>
    </form>
</div>
@include('dashboard.partials.accounting')
@endsection
