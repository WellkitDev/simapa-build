@extends('layouts.master')
@section('title', 'Jurnal Kas - SiMAPA')
@section('content')
<h5 class="mb-3">Jurnal Kas</h5>
<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <p>Total Pemasukan: {{ number_format($totalIn, 0, ',', '.') }} · Total Pengeluaran: {{ number_format($totalOut, 0, ',', '.') }} · Saldo: {{ number_format($saldoAkhir, 0, ',', '.') }}</p>
    <ul class="list-unstyled mb-0">
        @foreach($entries as $e)
            <li>{{ $e->keterangan }} — {{ number_format($e->amount, 0, ',', '.') }}</li>
        @endforeach
    </ul>
</div></div></div></div>
@endsection
