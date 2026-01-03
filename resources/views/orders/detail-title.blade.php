@extends('layouts.master')
<!-- Title pages active -->
@section('title', 'Detail Title - SiMAPA')

@push('plugin-styles')
    <!-- Plugin css import here -->
@endpush

@section('content')
    <div class="page-content">
        <div class="row">
            <div class="col-md-12">
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-baseline mb-3">
                            <h3 class="mb-0">{{ $detail->title }}</h3>
                            <span class="badge bg-info text-uppercase">{{ $detail->type }}</span>
                        </div>
                        <div class="row">
                            <div class="col-sm-4">
                                <p class="text-muted mb-1">Kode Order</p>
                                <h5>{{ $detail->order->code_order }}</h5>
                            </div>
                            <div class="col-sm-4">
                                <p class="text-muted mb-1">Marketing</p>
                                <h5>{{ $detail->order->user->name ?? '-' }}</h5>
                            </div>
                            <div class="col-sm-4">
                                <p class="text-muted mb-1">Total Biaya</p>
                                <h5 class="text-success">Rp {{ number_format($detail->cost_amount, 0, ',', '.') }}</h5>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header bg-transparent border-bottom">
                        <h5 class="mb-0">Daftar Penulis (Authors)</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>No</th>
                                        <th>Nama Penulis</th>
                                        <th>Email / WhatsApp</th>
                                        <th>Posisi</th>
                                        <th>Status Order</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($detail->authors as $author)
                                        <tr>
                                            <td>{{ $loop->iteration }}</td>
                                            <td><strong>{{ $author->name }}</strong></td>
                                            <td>
                                                {{ $author->email }}<br>
                                                <small class="text-muted">{{ $author->phone }}</small>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-primary">
                                                    Penulis Ke-{{ $author->pivot->position }}
                                                </span>
                                            </td>
                                            <td>
                                                @if ($detail->order->status == 'lunas')
                                                    <span class="badge bg-success">Lunas</span>
                                                @else
                                                    <span class="badge bg-warning">Proses</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="text-center">Belum ada data penulis terdaftar.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

@endsection

@push('plugin-scripts')
    <!-- Plugin js import here -->
@endpush

@push('custom-scripts')
    <!-- Custom js here -->
@endpush
