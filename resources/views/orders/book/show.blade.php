@extends('layouts.master')
<!-- Title pages active -->
@section('title', 'Detail Order Book - SiMAPA')

@push('plugin-styles')
    <!-- Plugin css import here -->
@endpush

@section('content')
    <div class="container py-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3">
                Detail Order: <strong>{{ $order->code_order }}</strong>
            </h1>
            <a href="{{ route('order.book.index') }}" class="btn btn-secondary">
                ← Kembali ke Daftar
            </a>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Informasi Order</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        @php $detail = $order->details; @endphp
                        <table class="table table-borderless">
                            <tr>
                                <th width="35%">Kode Order</th>
                                <td>: {{ $order->code_order }}</td>
                            </tr>
                            <tr>
                                <th>Jenis Layanan</th>
                                <td>: {{ $detail->type ?? '-' }}
                                    <span class="badge bg-info ms-2 text-uppercase">{{ $detail->naskah_type ?? '-' }}</span>
                                </td>
                            </tr>
                            <tr>
                                <th>Judul</th>
                                <td>: {{ $detail->title ?? 'Judul Belum Diisi' }}</td>
                            </tr>
                            <tr>
                                <th>Jumlah Bab</th>
                                <td>: {{ $detail->chapters ?? 0 }} Bab</td>
                            </tr>
                            <tr>
                                <th>Scope</th>
                                <td>:
                                    @if ($detail && $detail->scopes->isNotEmpty())
                                        {{ $detail->scopes->pluck('scope')->implode(' / ') }}
                                    @else
                                        <span class="text-muted">N/A</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th>Jenis Publikasi</th>
                                <td>:
                                    <span
                                        class="badge bg-success text-uppercase">{{ $detail->publication_type ?? 'reguler' }}</span>
                                </td>
                            </tr>
                            <tr>
                                <th>Status Order</th>
                                <td>:
                                    <span class="badge bg-warning text-uppercase">{{ $order->status }}</span>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="col-md-6 border-start">
                        <h5 class="mb-3">Penulis</h5>
                        @if ($detail && $detail->authors->isNotEmpty())
                            <ol class="list-group list-group-numbered">
                                @foreach ($detail->authors->sortBy('pivot.position') as $author)
                                    <li
                                        class="list-group-item d-flex justify-content-between align-items-start border-0 border-bottom">
                                        <div class="ms-2 me-auto">
                                            <div class="fw-bold">{{ $author->name }}</div>
                                            <small
                                                class="text-muted">{{ $author->affiliation ?? 'No Affiliation' }}</small><br>
                                            <small class="text-muted">
                                                {{ $author->email ?? '-' }} | {{ $author->phone ?? '-' }}
                                            </small>
                                        </div>
                                        <span class="badge bg-primary rounded-pill">Posisi
                                            {{ $author->pivot->position }}</span>
                                    </li>
                                @endforeach
                            </ol>
                        @else
                            <div class="alert alert-light text-center">Belum ada data penulis.</div>
                        @endif
                    </div>
                </div>

                <div class="mt-4">
                    <h5>Catatan Tambahan</h5>
                    <div class="alert alert-secondary">
                        {{ $order->note ?? 'Tidak ada catatan tambahan.' }}
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">Rincian Keuangan</h5>
            </div>
            <div class="card-body">
                <div class="row text-center mb-4">
                    <div class="col">
                        <h4>Rp {{ number_format($totalCost, 0, ',', '.') }}</h4>
                        <small class="text-muted">Total Biaya</small>
                    </div>
                    <div class="col">
                        <h4>Rp {{ number_format($alreadyPaid, 0, ',', '.') }}</h4>
                        <small class="text-muted text-success">Sudah Dibayar</small>
                    </div>
                    <div class="col">
                        <h4 class="{{ $remainingBalance > 0 ? 'text-danger' : 'text-success' }}">
                            Rp {{ number_format($remainingBalance, 0, ',', '.') }}
                        </h4>
                        <small class="text-muted">Sisa Tagihan</small>
                    </div>
                </div>

                <h5>Riwayat Pembayaran</h5>
                <div class="table-responsive">
                    <table class="table table-hover mt-3">
                        <thead class="table-light">
                            <tr>
                                <th>No</th>
                                <th>Tanggal</th>
                                <th>Jenis</th>
                                <th>Jumlah</th>
                                <th>Bukti</th>
                                <th>Status Approval</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($order->payments as $index => $payment)
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>{{ $payment->created_at->format('d M Y H:i') }}</td>
                                    <td><span class="badge bg-secondary text-uppercase">{{ $payment->payment_type }}</span>
                                    </td>
                                    <td>Rp {{ number_format($payment->amount, 0, ',', '.') }}</td>
                                    <td>
                                        @if ($payment->proof_url)
                                            <a href="{{ $payment->proof_url }}" target="_blank"
                                                class="btn btn-sm btn-outline-primary">Lihat Bukti</a>
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td>
                                        @php $appStatus = $payment->approval->status ?? 'pending'; @endphp
                                        <span
                                            class="badge {{ $appStatus == 'approved' ? 'bg-success' : ($appStatus == 'rejected' ? 'bg-danger' : 'bg-warning') }}">
                                            {{ ucfirst($appStatus) }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted">Belum ada riwayat pembayaran.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Invoice Terakhir</h5>
                @if ($order->invoices->isNotEmpty())
                    <a href="#" class="btn btn-light btn-sm">📄 Download PDF Invoice</a>
                @endif
            </div>
            <div class="card-body text-center">
                @if ($order->invoices->isNotEmpty())
                    @php $latestInv = $order->invoices->last(); @endphp
                    <p><strong>No. Invoice:</strong> {{ $latestInv->invoice_no }}</p>
                    <p><strong>Tanggal Terbit:</strong> {{ \Carbon\Carbon::parse($latestInv->issued_at)->format('d F Y') }}
                    </p>
                    <p><strong>Jatuh Tempo:</strong> {{ \Carbon\Carbon::parse($latestInv->due_at)->format('d F Y') }}</p>

                    <h4 class="mt-4">
                        Status:
                        @if ($remainingBalance <= 0)
                            <span class="text-success fw-bold">LUNAS</span>
                        @else
                            <span class="text-warning fw-bold text-uppercase">Menunggu Pelunasan</span>
                        @endif
                    </h4>
                @else
                    <div class="py-3">
                        <p class="text-muted">Invoice belum diterbitkan.</p>
                    </div>
                @endif
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
