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
                            <th>Order</th>
                            <th>Stage</th>
                            <th>Editor</th>
                            <th>Prioritas</th>
                            <th>Update</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($groups as $detail)
                            @php $p = $detail->titleProgress; @endphp
                            <tr>
                                <td>
                                    <strong>{{ Str::limit($detail->title, 50) }}</strong>
                                    <small class="text-muted">· {{ $detail->group_author_count ?? $detail->authors->count() }} penulis</small>
                                </td>
                                <td><span class="badge bg-secondary">{{ $detail->group_order_count ?? 1 }}</span></td>
                                <td>
                                    <span class="badge bg-{{ $statusBadge[$p->status] ?? 'secondary' }}">{{ Str::title(str_replace('_', ' ', $p->status)) }}</span>
                                    @if($detail->group_is_mixed ?? false)<span class="badge bg-light text-muted">beragam</span>@endif
                                    @if($p->needs_review)<span class="badge bg-warning text-dark" title="Perlu ditinjau superadmin">⚑ tinjau</span>@endif
                                </td>
                                <td>{{ optional($p->assignedUser)->name ?? '—' }}</td>
                                <td><span class="badge bg-{{ $prioBadge[$p->priority] ?? 'secondary' }}">{{ ucfirst($p->priority) }}</span></td>
                                <td><small>{{ optional($p->started_at)->diffForHumans() }}</small></td>
                                <td class="text-end">
                                    <a href="{{ route('order.indexJudul.detail', $detail->id) }}" class="btn btn-sm btn-outline-primary">Detail</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-4">Tidak ada naskah.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
