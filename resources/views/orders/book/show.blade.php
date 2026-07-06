@extends('layouts.master')
<!-- Title pages active -->
@section('title', 'Detail Order Buku - SiMAPA')

@push('plugin-styles')
    <!-- Plugin css import here -->
@endpush

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h5 class="mb-0">Detail Order: <strong>{{ $order->code_order }}</strong></h5>
        </div>
        <div>
            <a href="{{ route('order.book.index') }}" class="btn btn-sm btn-outline-secondary">
                ← Kembali ke Daftar
            </a>
        </div>
    </div>

    <div class="row">
        <div class="col-12 grid-margin stretch-card">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-title">Informasi Order</h6>
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
                                    <td>: @switch($detail->type)
                                            @case('bk_mandiri')
                                                Buku Mandiri
                                            @break

                                            @case('bk_kolab')
                                                Buku Kolaborasi
                                            @break

                                            @case('at_mandiri')
                                                Artikel Mandiri
                                            @break

                                            @case('at_kolab')
                                                Artikel Kolaborasi
                                            @break
                                        @endswitch
                                        <span class="badge bg-info ms-2 text-uppercase">{{ $detail->naskah_type ?? '-' }}</span>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Judul</th>
                                    <td class="text-wrap" style="width: 150px;">: {{ $detail->title ?? 'Judul Belum Diisi' }}
                                    </td>
                                </tr>
                                <tr>
                                    @if (str_starts_with($detail->type, 'at_'))
                                        <th>Target Indeksasi</th>
                                        <td>: {{ strtoupper($detail->indexation) }}</td>
                                    @else
                                        <th>{{ $detail->type === 'bk_kolab' ? 'Bab Order' : 'Jumlah Bab' }}</th>
                                        <td>: {{ $detail->chapters ?? 0 }}</td>
                                    @endif
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
        </div>
    </div>

    <div class="row">
        <div class="col-12 grid-margin stretch-card">
            <div class="card">
                <div class="card-body">
                    <h6 class="card-title">Rincian Keuangan</h6>
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
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($order->payments as $index => $payment)
                                    @php $appStatus = $payment->approval->status ?? 'pending'; @endphp
                                    <tr>
                                        <td>{{ $index + 1 }}</td>
                                        <td>{{ $payment->created_at->format('d M Y H:i') }}</td>
                                        <td><span class="badge bg-secondary text-uppercase">{{ $payment->payment_type }}</span></td>
                                        <td>Rp {{ number_format($payment->amount, 0, ',', '.') }}</td>
                                        <td>
                                            @if ($payment->proof_url)
                                                <a href="{{ $payment->proof_url }}" target="_blank" class="btn btn-sm btn-outline-primary">Lihat Bukti</a>
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td>
                                            <span class="badge {{ $appStatus == 'approved' ? 'bg-success' : ($appStatus == 'rejected' ? 'bg-danger' : 'bg-warning') }}">
                                                {{ ucfirst($appStatus) }}
                                            </span>
                                        </td>
                                        <td class="text-nowrap">
                                            @if ($payment->invoice)
                                                <a href="{{ route('invoice.pdf', $payment->invoice->id) }}" target="_blank"
                                                   class="btn btn-sm btn-outline-success">Download Invoice</a>
                                            @endif
                                            @hasanyrole('manager|superadmin')
                                                @if ($appStatus === 'pending')
                                                    <button type="button" class="btn btn-sm btn-outline-warning"
                                                            data-bs-toggle="modal" data-bs-target="#editPayment{{ $payment->id }}">Edit</button>
                                                @endif
                                            @endhasanyrole
                                        </td>
                                    </tr>

                                    @hasanyrole('manager|superadmin')
                                        @if ($appStatus === 'pending')
                                            <div class="modal fade" id="editPayment{{ $payment->id }}" tabindex="-1" aria-hidden="true">
                                                <div class="modal-dialog">
                                                    <form class="modal-content" method="POST"
                                                          action="{{ route('payment.update', $payment->id) }}" enctype="multipart/form-data">
                                                        @csrf
                                                        @method('PUT')
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Edit Pembayaran</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <div class="mb-3">
                                                                <label class="form-label">Jumlah (Rp)</label>
                                                                <input type="number" name="amount" class="form-control" min="1"
                                                                       value="{{ old('amount', $payment->amount) }}" required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Jenis</label>
                                                                <select name="payment_type" class="form-select" required>
                                                                    @foreach (['dp' => 'DP', 'lunas' => 'Lunas', 'pelunasan' => 'Pelunasan'] as $val => $lbl)
                                                                        <option value="{{ $val }}" {{ $payment->payment_type === $val ? 'selected' : '' }}>{{ $lbl }}</option>
                                                                    @endforeach
                                                                </select>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Tanggal</label>
                                                                <input type="date" name="paid_at" class="form-control"
                                                                       value="{{ old('paid_at', \Carbon\Carbon::parse($payment->paid_at)->format('Y-m-d')) }}" required>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Bukti (opsional, kosongkan jika tidak diganti)</label>
                                                                <input type="file" name="proof_url" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                                            <button type="submit" class="btn btn-primary">Simpan</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        @endif
                                    @endhasanyrole
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center text-muted">Belum ada riwayat pembayaran.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12 grid-margin stretch-card">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="card-title mb-0">Daftar Invoice</h6>
                        <span class="badge {{ $remainingBalance <= 0 ? 'bg-success' : 'bg-warning text-dark' }}">
                            {{ $remainingBalance <= 0 ? 'LUNAS' : 'MENUNGGU PELUNASAN' }}
                        </span>
                    </div>
                    @php
                        $invStatusColors = [
                            'draft' => 'secondary', 'diterbitkan' => 'info', 'jatuh_tempo' => 'warning',
                            'lunas' => 'success', 'dibatalkan' => 'danger', 'refund' => 'dark',
                        ];
                    @endphp
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>No</th>
                                    <th>No. Invoice</th>
                                    <th>Tanggal Terbit</th>
                                    <th>Jatuh Tempo</th>
                                    <th>Status</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($order->invoices->sortByDesc('id') as $i => $invoice)
                                    <tr>
                                        <td>{{ $i + 1 }}</td>
                                        <td>{{ $invoice->invoice_no }}</td>
                                        <td>{{ \Carbon\Carbon::parse($invoice->issued_at)->format('d F Y') }}</td>
                                        <td>{{ \Carbon\Carbon::parse($invoice->due_at)->format('d F Y') }}</td>
                                        <td>
                                            <span class="badge bg-{{ $invStatusColors[$invoice->status] ?? 'secondary' }}">
                                                {{ Str::title(str_replace('_', ' ', $invoice->status)) }}
                                            </span>
                                        </td>
                                        <td>
                                            <a href="{{ route('invoice.pdf', $invoice->id) }}" target="_blank"
                                               class="btn btn-sm btn-outline-success">Download</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">Invoice belum diterbitkan.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
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
