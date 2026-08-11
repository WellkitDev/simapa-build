@extends('layouts.master')
@section('title', 'Klien: ' . $client->name . ' - SiMAPA')

@section('content')
<div class="row">
    <div class="col-md-4 grid-margin stretch-card">
        <div class="card">
            <div class="card-body">
                <h6 class="card-title">{{ $client->name }}</h6>
                <p class="mb-1"><strong>Instansi:</strong> {{ $client->institution ?? '-' }}</p>
                <p class="mb-1"><strong>Email:</strong> {{ $client->email ?? '-' }}</p>
                <p class="mb-1"><strong>Telepon:</strong> {{ $client->phone ?? '-' }}</p>
                <p class="mb-1"><strong>Alamat:</strong> {{ $client->address ?? '-' }}</p>
                @if($client->note)
                    <p class="mb-0"><strong>Catatan:</strong> {{ $client->note }}</p>
                @endif
                <a href="{{ route('service.client.index') }}" class="btn btn-sm btn-outline-secondary mt-3">← Kembali</a>
            </div>
        </div>
    </div>

    <div class="col-md-8 grid-margin stretch-card">
        <div class="card">
            <div class="card-body">
                <h6 class="card-title">Riwayat Pekerjaan</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-centered">
                        <thead>
                            <tr><th>No Invoice</th><th>Terbit</th><th>Total</th><th>Kerja</th><th>Bayar</th><th></th></tr>
                        </thead>
                        <tbody>
                            @forelse($client->invoices as $inv)
                            <tr>
                                <td><strong>{{ $inv->invoice_no }}</strong></td>
                                <td><small>{{ $inv->issued_at?->format('d/m/Y') }}</small></td>
                                <td>Rp {{ number_format($inv->total, 0, ',', '.') }}</td>
                                <td><span class="badge bg-secondary">{{ $inv->workStatusLabel() }}</span></td>
                                <td><span class="badge bg-info">{{ $inv->paymentStatusLabel() }}</span></td>
                                {{-- Tombol Detail sengaja BELUM ada di sini; ditambahkan di Task 8
                                     bersama rute service.invoice.show. Membungkusnya dengan
                                     @can('service_invoice.view') TIDAK aman: Gate::before di
                                     AuthServiceProvider meloloskan superadmin untuk ability APA PUN,
                                     termasuk permission yang belum terdaftar — jadi blok itu tetap
                                     dievaluasi dan route() yang belum ada melempar 500. --}}
                                <td></td>
                            </tr>
                            @empty
                            <tr><td colspan="6" class="text-center text-muted">Belum ada invoice untuk klien ini.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
