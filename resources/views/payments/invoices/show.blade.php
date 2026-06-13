@extends('layouts.master')
@section('title', 'Detail Invoice - SiMAPA')

@section('content')
<div class="row">
    <div class="col-md-8">

        {{-- Info Invoice --}}
        <div class="card mb-4">
            <div class="card-body">
                @php
                    $statusColors = [
                        'draft'       => 'secondary',
                        'diterbitkan' => 'primary',
                        'jatuh_tempo' => 'warning',
                        'lunas'       => 'success',
                        'dibatalkan'  => 'danger',
                        'refund'      => 'info',
                    ];
                    $color = $statusColors[$invoice->status] ?? 'secondary';
                @endphp
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4 class="mb-0">{{ $invoice->invoice_no }}</h4>
                    <span class="badge bg-{{ $color }} fs-6">
                        {{ Str::title(str_replace('_',' ',$invoice->status)) }}
                    </span>
                </div>

                <div class="row">
                    <div class="col-sm-4"><p class="text-muted mb-1">Tipe</p><p>{{ ucfirst($invoice->type) }}</p></div>
                    <div class="col-sm-4"><p class="text-muted mb-1">Order</p><p>{{ $invoice->order->code_order }}</p></div>
                    <div class="col-sm-4"><p class="text-muted mb-1">Judul</p>
                        <p>{{ Str::limit($invoice->order->details->title ?? '-', 40) }}</p>
                    </div>
                    <div class="col-sm-4"><p class="text-muted mb-1">Tanggal Terbit</p>
                        <p>{{ $invoice->issued_at?->format('d/m/Y') ?? '-' }}</p>
                    </div>
                    <div class="col-sm-4"><p class="text-muted mb-1">Jatuh Tempo</p>
                        <p class="{{ $invoice->isOverdue() ? 'text-danger fw-bold' : '' }}">
                            {{ $invoice->due_at?->format('d/m/Y') ?? '-' }}
                            @if($invoice->isOverdue())
                                <span class="badge bg-danger ms-1">Overdue</span>
                            @endif
                        </p>
                    </div>
                    <div class="col-sm-4"><p class="text-muted mb-1">Catatan</p><p>{{ $invoice->note ?? '-' }}</p></div>
                </div>

                @if($invoice->status === 'dibatalkan')
                    <div class="alert alert-danger mt-3 py-2">
                        Dibatalkan oleh <strong>{{ $invoice->cancelledBy->name ?? '-' }}</strong>
                        pada {{ $invoice->cancelled_at?->format('d/m/Y H:i') }}
                    </div>
                @endif
                @if($invoice->status === 'refund')
                    <div class="alert alert-info mt-3 py-2">
                        Refund diproses oleh <strong>{{ $invoice->refundedBy->name ?? '-' }}</strong>
                        pada {{ $invoice->refunded_at?->format('d/m/Y H:i') }}
                    </div>
                @endif

                {{-- Flash messages --}}
                @if(session('success'))
                    <div class="alert alert-success mt-3 py-2">{{ session('success') }}</div>
                @endif
                @if(session('warning'))
                    <div class="alert alert-warning mt-3 py-2">{{ session('warning') }}</div>
                @endif
                @if(session('error'))
                    <div class="alert alert-danger mt-3 py-2">{{ session('error') }}</div>
                @endif

                {{-- Aksi --}}
                <div class="d-flex gap-2 mt-3 flex-wrap">
                    @hasanyrole('manager|superadmin')
                        <a href="{{ route('invoice.edit', $invoice->id) }}" class="btn btn-sm btn-outline-secondary">Edit</a>

                        @if(!in_array($invoice->status, ['lunas','dibatalkan','refund']))
                        <form method="POST" action="{{ route('invoice.updateStatus', $invoice->id) }}" class="d-flex gap-2">
                            @csrf
                            <select name="status" class="form-select form-select-sm" style="width:auto">
                                @foreach(\App\Models\Invoice::STATUSES as $s)
                                    <option value="{{ $s }}" {{ $invoice->status === $s ? 'selected' : '' }}>
                                        {{ Str::title(str_replace('_',' ',$s)) }}
                                    </option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn btn-sm btn-primary">Update Status</button>
                        </form>
                        @endif
                    @endhasanyrole

                    @role('superadmin')
                        @if(!in_array($invoice->status, ['dibatalkan','refund']))
                        <form method="POST" action="{{ route('invoice.cancel', $invoice->id) }}"
                              onsubmit="return confirm('Batalkan invoice ini?')">
                            @csrf
                            <input type="hidden" name="note" value="Dibatalkan oleh superadmin.">
                            <button type="submit" class="btn btn-sm btn-danger">Cancel</button>
                        </form>
                        @endif

                        @if($invoice->status === 'lunas')
                        <form method="POST" action="{{ route('invoice.refund', $invoice->id) }}"
                              onsubmit="return confirm('Proses refund invoice ini?')">
                            @csrf
                            <input type="hidden" name="note" value="Refund diproses oleh superadmin.">
                            <button type="submit" class="btn btn-sm btn-outline-warning">Refund</button>
                        </form>
                        @endif
                    @endrole
                </div>
            </div>
        </div>

        {{-- Log History --}}
        <div class="card">
            <div class="card-header bg-transparent border-bottom">
                <h5 class="mb-0">Riwayat Status Invoice</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr><th>Dari</th><th>Ke</th><th>Oleh</th><th>Tanggal</th><th>Catatan</th></tr>
                        </thead>
                        <tbody>
                            @forelse($invoice->logs->sortByDesc('created_at') as $log)
                            <tr>
                                <td>
                                    <span class="badge bg-secondary">
                                        {{ $log->from_status ? Str::title(str_replace('_',' ',$log->from_status)) : 'Baru' }}
                                    </span>
                                </td>
                                <td><span class="badge bg-primary">{{ Str::title(str_replace('_',' ',$log->to_status)) }}</span></td>
                                <td>{{ $log->changedBy->name ?? '-' }}</td>
                                <td><small>{{ $log->created_at->format('d/m/Y H:i') }}</small></td>
                                <td>{{ $log->note ?? '-' }}</td>
                            </tr>
                            @empty
                            <tr><td colspan="5" class="text-center text-muted">Belum ada riwayat.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>
@endsection
