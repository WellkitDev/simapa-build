@extends('layouts.master')
@section('title', 'Notifikasi - SiMAPA')

@section('content')
@php $iconMap = ['payment' => 'credit-card', 'tagihan' => 'file-text', 'naskah' => 'book-open', 'target' => 'target']; @endphp
<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="card-title mb-0">Semua Notifikasi</h6>
                @if(auth()->user()->unreadNotifications()->count() > 0)
                    <form action="{{ route('notifications.readAll') }}" method="POST" class="m-0">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-primary">Tandai semua dibaca</button>
                    </form>
                @endif
            </div>
            <div class="list-group list-group-flush">
                @forelse($notifications as $n)
                    <form action="{{ route('notifications.read', $n->id) }}" method="POST" class="m-0">
                        @csrf
                        <button type="submit"
                            class="list-group-item list-group-item-action d-flex align-items-start {{ is_null($n->read_at) ? 'bg-light' : '' }}">
                            <i data-feather="{{ $iconMap[$n->data['category'] ?? ''] ?? 'bell' }}" class="icon-sm me-3 mt-1"></i>
                            <span class="text-start">
                                <span class="d-block fw-bold">{{ $n->data['title'] ?? 'Notifikasi' }}</span>
                                <span class="d-block text-muted">{{ $n->data['message'] ?? '' }}</span>
                                <span class="d-block text-muted" style="font-size:11px">{{ $n->created_at->diffForHumans() }}</span>
                            </span>
                        </button>
                    </form>
                @empty
                    <p class="text-muted text-center py-4 mb-0">Belum ada notifikasi.</p>
                @endforelse
            </div>
            <div class="mt-3">{{ $notifications->links() }}</div>
        </div></div>
    </div>
</div>
@endsection
