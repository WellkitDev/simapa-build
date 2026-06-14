@extends('layouts.master')
@section('title', 'Manuscript Tracker - SiMAPA')

@section('content')
@php
    $statusBadge = [
        'menunggu_proses' => 'secondary',
        'templating' => 'warning', 'editing' => 'warning', 'layout' => 'warning',
        'revisi' => 'warning', 'proofreading' => 'warning', 'isbn' => 'warning',
        'submit' => 'primary', 'cetak' => 'primary', 'loa' => 'primary',
        'publish' => 'success', 'terbit' => 'success',
    ];
    $prioBadge = ['high' => 'danger', 'normal' => 'secondary', 'low' => 'info'];
@endphp
<div class="page-content">
    @include('manuscript.partials.toolbar')

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Judul Naskah</th>
                            <th>Stage</th>
                            <th>Editor</th>
                            <th>Prioritas</th>
                            <th>Update</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($details as $detail)
                            @php $p = $detail->titleProgress; @endphp
                            <tr>
                                <td>
                                    <strong>{{ Str::limit($detail->title, 50) }}</strong><br>
                                    <small class="text-muted">{{ $detail->order->code_order ?? '—' }}</small>
                                </td>
                                <td><span class="badge bg-{{ $statusBadge[$p->status] ?? 'secondary' }}">{{ Str::title(str_replace('_', ' ', $p->status)) }}</span></td>
                                <td>{{ optional($p->assignedUser)->name ?? '—' }}</td>
                                <td><span class="badge bg-{{ $prioBadge[$p->priority] ?? 'secondary' }}">{{ ucfirst($p->priority) }}</span></td>
                                <td><small>{{ optional($p->started_at)->diffForHumans() }}</small></td>
                                <td class="text-end">
                                    <a href="{{ route('order.indexJudul.progress', $detail->id) }}" class="btn btn-sm btn-outline-primary">Detail</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-4">Tidak ada naskah.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
