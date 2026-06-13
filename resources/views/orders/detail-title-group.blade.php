@extends('layouts.master')
@section('title', 'Detail Judul - SiMAPA')

@section('content')
<div class="page-content">
    <div class="row">
        <div class="col-md-12">

            @php
                $statusColors = [
                    'menunggu_proses' => 'secondary',
                    'templating' => 'warning', 'editing' => 'warning', 'layout' => 'warning',
                    'revisi' => 'warning', 'proofreading' => 'warning', 'isbn' => 'warning',
                    'submit' => 'primary', 'cetak' => 'primary', 'loa' => 'primary',
                    'publish' => 'success', 'terbit' => 'success',
                ];
            @endphp

            {{-- Header agregat --}}
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-baseline mb-3">
                        <h3 class="mb-0 text-capitalize">{{ $summary->title }}</h3>
                        <span class="badge bg-info">{{ $summary->type_label }}</span>
                    </div>
                    <div class="row">
                        <div class="col-sm-4">
                            <p class="text-muted mb-1">Total Author</p>
                            <h5>{{ $summary->total_author }}</h5>
                        </div>
                        <div class="col-sm-4">
                            <p class="text-muted mb-1">Status Progres</p>
                            <h5>
                                <span class="badge bg-{{ $statusColors[$summary->bottleneck_status] ?? 'secondary' }}">
                                    {{ Str::title(str_replace('_', ' ', $summary->bottleneck_status)) }}
                                </span>
                                @if ($summary->is_mixed)
                                    <span class="badge bg-light text-muted">beragam</span>
                                @endif
                            </h5>
                        </div>
                        <div class="col-sm-4">
                            <p class="text-muted mb-1">Update Terakhir</p>
                            <h5><small>{{ $summary->last_update ? \Carbon\Carbon::parse($summary->last_update)->diffForHumans() : '-' }}</small></h5>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Daftar penulis / order --}}
            <div class="card">
                <div class="card-header bg-transparent border-bottom">
                    <h5 class="mb-0">Daftar Penulis &amp; Order</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Penulis</th>
                                    <th>Posisi</th>
                                    <th>Kode Order</th>
                                    <th>Marketing</th>
                                    <th>Status Order</th>
                                    <th>Update</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($details as $detail)
                                    @php
                                        $st = optional($detail->titleProgress)->status ?? 'menunggu_proses';
                                    @endphp
                                    <tr>
                                        <td>{{ $loop->iteration }}</td>
                                        <td>
                                            @forelse ($detail->authors as $author)
                                                <div><strong>{{ $author->name }}</strong></div>
                                            @empty
                                                <span class="text-muted">-</span>
                                            @endforelse
                                        </td>
                                        <td>
                                            @foreach ($detail->authors as $author)
                                                <span class="badge bg-light text-primary">Ke-{{ $author->pivot->position }}</span>
                                            @endforeach
                                        </td>
                                        <td>{{ $detail->order->code_order }}</td>
                                        <td>{{ $detail->order->user->name ?? '-' }}</td>
                                        <td>
                                            <span class="badge bg-{{ $statusColors[$st] ?? 'secondary' }}">
                                                {{ Str::title(str_replace('_', ' ', $st)) }}
                                            </span>
                                        </td>
                                        <td><small>{{ optional($detail->titleProgress)->updated_at ? $detail->titleProgress->updated_at->diffForHumans() : '-' }}</small></td>
                                        <td>
                                            <a href="{{ route('order.indexJudul.progress', $detail->id) }}"
                                                class="btn btn-sm btn-outline-primary">Progress</a>
                                            <a href="{{ route('order.book.show', $detail->order->code_order) }}"
                                                class="btn btn-sm btn-outline-secondary">Order</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="text-center text-muted">Belum ada order.</td></tr>
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
