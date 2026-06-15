{{-- resources/views/manuscript/partials/toolbar.blade.php --}}
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
        <h4 class="mb-0">Manuscript Tracker</h4>
        <small class="text-muted">{{ $groups->count() }} judul aktif · geser kartu untuk memajukan tahap</small>
    </div>
    <form method="GET" action="{{ route('manuscript.board') }}" class="d-flex flex-wrap gap-2 align-items-center">
        <input type="hidden" name="tipe" value="{{ $tipe }}">
        <input type="hidden" name="view" value="{{ $view }}">

        <div class="btn-group btn-group-sm">
            <a href="{{ route('manuscript.board', array_merge(request()->query(), ['tipe' => 'buku'])) }}"
               class="btn btn-{{ $tipe === 'buku' ? 'primary' : 'outline-primary' }}">Buku</a>
            <a href="{{ route('manuscript.board', array_merge(request()->query(), ['tipe' => 'artikel'])) }}"
               class="btn btn-{{ $tipe === 'artikel' ? 'primary' : 'outline-primary' }}">Artikel</a>
        </div>

        <select name="editor" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
            <option value="">Semua Editor</option>
            <option value="me" {{ request('editor') === 'me' ? 'selected' : '' }}>Tugas saya</option>
            @foreach($editors as $ed)
                <option value="{{ $ed->id }}" {{ (string) request('editor') === (string) $ed->id ? 'selected' : '' }}>
                    {{ $ed->name }}
                </option>
            @endforeach
        </select>

        <select name="priority" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
            <option value="">Semua Prioritas</option>
            @foreach(['high', 'normal', 'low'] as $pr)
                <option value="{{ $pr }}" {{ request('priority') === $pr ? 'selected' : '' }}>{{ ucfirst($pr) }}</option>
            @endforeach
        </select>

        @php $rq = request()->query(); unset($rq['review']); @endphp
        @if(request()->boolean('review'))
            <a href="{{ route('manuscript.board', $rq) }}" class="btn btn-sm btn-warning">
                ⚑ Perlu ditinjau @if(($reviewCount ?? 0) > 0)<span class="badge bg-dark ms-1">{{ $reviewCount }}</span>@endif
            </a>
        @else
            <a href="{{ route('manuscript.board', array_merge($rq, ['review' => 1])) }}" class="btn btn-sm btn-outline-warning">
                ⚑ Perlu ditinjau @if(($reviewCount ?? 0) > 0)<span class="badge bg-warning text-dark ms-1">{{ $reviewCount }}</span>@endif
            </a>
        @endif

        <div class="btn-group btn-group-sm">
            <a href="{{ route('manuscript.board', array_merge(request()->query(), ['scope' => 'mine'])) }}"
               class="btn btn-{{ ($scope ?? 'all') === 'mine' ? 'success' : 'outline-success' }}">Meja Saya</a>
            <a href="{{ route('manuscript.board', array_merge(request()->query(), ['scope' => 'all'])) }}"
               class="btn btn-{{ ($scope ?? 'all') === 'all' ? 'success' : 'outline-success' }}">Semua</a>
        </div>

        <div class="btn-group btn-group-sm">
            <a href="{{ route('manuscript.board', array_merge(request()->query(), ['view' => 'board'])) }}"
               class="btn btn-{{ $view === 'board' ? 'dark' : 'outline-dark' }}">Papan</a>
            <a href="{{ route('manuscript.board', array_merge(request()->query(), ['view' => 'list'])) }}"
               class="btn btn-{{ $view === 'list' ? 'dark' : 'outline-dark' }}">Daftar</a>
            <a href="{{ route('manuscript.board', array_merge(request()->query(), ['view' => 'log'])) }}"
               class="btn btn-{{ $view === 'log' ? 'dark' : 'outline-dark' }}">Log</a>
        </div>
    </form>
</div>

@if(session('success'))
    <div class="alert alert-success py-2">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert alert-danger py-2">{{ session('error') }}</div>
@endif
