@extends('layouts.master')
@section('title', 'Invoice ' . $invoice->invoice_no . ' - SiMAPA')

@section('content')
@php
    $workColors = ['belum' => 'secondary', 'proses' => 'warning', 'selesai' => 'success', 'batal' => 'danger'];
    $payColors  = ['belum' => 'secondary', 'dp' => 'info', 'lunas' => 'success'];
@endphp
<div class="row">
    <div class="col-md-8 grid-margin stretch-card">
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <h5 class="mb-1">{{ $invoice->invoice_no }}</h5>
                        <span class="badge bg-{{ $workColors[$invoice->work_status] ?? 'secondary' }}">{{ $invoice->workStatusLabel() }}</span>
                        <span class="badge bg-{{ $payColors[$invoice->payment_status] ?? 'secondary' }}">{{ $invoice->paymentStatusLabel() }}</span>
                    </div>
                    <div class="d-flex gap-1">
                        {{-- Syarat kedua bukan keamanan (controller sudah menjaganya), tapi
                             afordans: tanpa itu manager melihat tombol Edit pada invoice
                             terkunci, mengkliknya, dan selalu dipantulkan balik. superadmin
                             tetap melihatnya karena memang boleh mengoreksi dengan alasan. --}}
                        @can('service_invoice.edit')
                            @if ($invoice->isEditable() || auth()->user()->hasRole('superadmin'))
                                <a href="{{ route('service.invoice.edit', $invoice->id) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
                            @endif
                        @endcan
                        @can('service_invoice.delete')
                            {{-- data-confirm: listener SweetAlert terdelegasi di layouts/master,
                                 dipakai seluruh aksi destruktif lain di aplikasi ini. --}}
                            <form action="{{ route('service.invoice.destroy', $invoice->id) }}" method="POST"
                                  data-confirm="Hapus invoice ini? Nomornya tidak akan dipakai ulang.">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger">Hapus</button>
                            </form>
                        @endcan
                        <a href="{{ route('service.invoice.index') }}" class="btn btn-sm btn-outline-secondary">← Daftar</a>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-6">
                        <h6>Kepada</h6>
                        <p class="mb-0">
                            {{ $invoice->client_name }}<br>
                            {{ $invoice->client_institution ?? '-' }}<br>
                            {{ $invoice->client_email ?? '-' }}<br>
                            {{ $invoice->client_phone ?? '-' }}
                        </p>
                    </div>
                    <div class="col-6 text-end">
                        <h6>Tanggal</h6>
                        <p class="mb-0">
                            Terbit: {{ $invoice->issued_at?->format('d M Y') }}<br>
                            Jatuh tempo:
                            <span class="{{ $invoice->isOverdue() ? 'text-danger fw-bold' : '' }}">
                                {{ $invoice->due_at?->format('d M Y') ?? '-' }}
                            </span>
                        </p>
                    </div>
                </div>

                <h6>Rincian Layanan</h6>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr><th>#</th><th>Layanan</th><th class="text-end">Qty</th>
                                <th class="text-end">Harga</th><th class="text-end">Subtotal</th></tr>
                        </thead>
                        <tbody>
                            @foreach($invoice->items as $i => $item)
                            <tr>
                                <td>{{ $i + 1 }}</td>
                                <td>
                                    {{ $item->name }}
                                    @if($item->description)<br><small class="text-muted">{{ $item->description }}</small>@endif
                                </td>
                                <td class="text-end">{{ rtrim(rtrim(number_format($item->qty, 2, ',', '.'), '0'), ',') }}</td>
                                <td class="text-end">Rp {{ number_format($item->unit_price, 0, ',', '.') }}</td>
                                <td class="text-end">Rp {{ number_format($item->subtotal, 0, ',', '.') }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr><td colspan="4" class="text-end">Subtotal</td>
                                <td class="text-end">Rp {{ number_format($invoice->subtotal, 0, ',', '.') }}</td></tr>
                            @if((float) $invoice->discount > 0)
                            <tr><td colspan="4" class="text-end">Diskon</td>
                                <td class="text-end">− Rp {{ number_format($invoice->discount, 0, ',', '.') }}</td></tr>
                            @endif
                            <tr class="fw-bold"><td colspan="4" class="text-end">Total</td>
                                <td class="text-end">Rp {{ number_format($invoice->total, 0, ',', '.') }}</td></tr>
                            <tr><td colspan="4" class="text-end">Terbayar</td>
                                <td class="text-end">Rp {{ number_format($invoice->paid_total, 0, ',', '.') }}</td></tr>
                            <tr class="fw-bold">
                                <td colspan="4" class="text-end">{{ $invoice->isOverpaid() ? 'Lebih Bayar' : 'Sisa Tagihan' }}</td>
                                <td class="text-end {{ $invoice->isOverpaid() ? 'text-info' : ((float) $invoice->remaining > 0 ? 'text-danger' : 'text-success') }}">
                                    Rp {{ number_format($invoice->isOverpaid() ? $invoice->overpaidAmount() : $invoice->remaining, 0, ',', '.') }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                @if($invoice->note)
                    <div class="alert alert-light border mt-2 mb-0"><strong>Catatan:</strong> {{ $invoice->note }}</div>
                @endif
                @if($invoice->internal_note)
                    <div class="alert alert-warning py-2 mt-2 mb-0">
                        <strong>Catatan internal</strong> (tidak tercetak): {{ $invoice->internal_note }}
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="col-md-4 grid-margin stretch-card">
        <div class="card">
            <div class="card-body">
                <h6 class="card-title">Riwayat</h6>
                <ul class="list-unstyled mb-0">
                    @foreach($invoice->logs as $log)
                    <li class="mb-2 pb-2 border-bottom">
                        <div><strong>{{ $log->eventLabel() }}</strong></div>
                        @if($log->from_status || $log->to_status)
                            <div><small class="text-muted">{{ $log->from_status ?? '—' }} → {{ $log->to_status }}</small></div>
                        @endif
                        @if($log->note)<div><small>{{ $log->note }}</small></div>@endif
                        <small class="text-muted">
                            {{ $log->created_at->format('d/m/Y H:i') }} · {{ $log->changedBy->name ?? 'Sistem' }}
                        </small>
                    </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection
