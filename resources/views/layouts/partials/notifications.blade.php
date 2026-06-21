@php $iconMap = ['payment' => 'credit-card', 'tagihan' => 'file-text', 'naskah' => 'book-open']; @endphp
<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle position-relative" href="#" id="notifDropdown" role="button"
        data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
        <i data-feather="bell"></i>
        @if(($navUnread ?? 0) > 0)
            <span class="badge bg-danger rounded-pill"
                style="position:absolute;top:2px;right:0;font-size:9px">{{ $navUnread > 9 ? '9+' : $navUnread }}</span>
        @endif
    </a>
    <div class="dropdown-menu dropdown-menu-end p-0" aria-labelledby="notifDropdown" style="min-width:320px">
        <div class="px-3 py-2 border-bottom d-flex justify-content-between align-items-center">
            <h6 class="mb-0">Notifikasi</h6>
            @if(($navUnread ?? 0) > 0)
                <form action="{{ route('notifications.readAll') }}" method="POST" class="m-0">
                    @csrf
                    <button type="submit" class="btn btn-link btn-sm p-0 text-decoration-none">Tandai semua</button>
                </form>
            @endif
        </div>
        <div style="max-height:320px;overflow-y:auto">
            @forelse(($navRecent ?? collect()) as $n)
                <form action="{{ route('notifications.read', $n->id) }}" method="POST" class="m-0">
                    @csrf
                    <button type="submit"
                        class="dropdown-item d-flex align-items-start py-2 {{ is_null($n->read_at) ? 'bg-light' : '' }}"
                        style="white-space:normal">
                        <i data-feather="{{ $iconMap[$n->data['category'] ?? ''] ?? 'bell' }}" class="icon-sm me-2 mt-1"></i>
                        <span>
                            <span class="d-block fw-bold tx-13">{{ $n->data['title'] ?? 'Notifikasi' }}</span>
                            <span class="d-block text-muted tx-12">{{ \Illuminate\Support\Str::limit($n->data['message'] ?? '', 60) }}</span>
                            <span class="d-block text-muted" style="font-size:10px">{{ $n->created_at->diffForHumans() }}</span>
                        </span>
                    </button>
                </form>
            @empty
                <p class="text-muted text-center py-3 mb-0">Belum ada notifikasi.</p>
            @endforelse
        </div>
        <a href="{{ route('notifications.index') }}" class="dropdown-item text-center border-top py-2">Lihat semua</a>
    </div>
</li>
