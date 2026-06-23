@php $items = $dashAnnouncements ?? collect(); @endphp
@if($items->isNotEmpty())
<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div id="announceCarousel" class="carousel slide w-100" data-bs-ride="carousel" data-bs-pause="hover">
            <div class="carousel-inner">
                @foreach($items as $i => $a)
                    @php
                        $words = max(1, str_word_count(strip_tags($a['body'])));
                        $interval = max(7000, min(20000, (int) round($words / 3.5 * 1000)));
                    @endphp
                    <div class="carousel-item {{ $i === 0 ? 'active' : '' }}" data-bs-interval="{{ $interval }}">
                        <div class="card border-start border-4 border-primary">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-1 flex-wrap">
                                    <h6 class="mb-0"><i data-feather="volume-2" class="icon-sm me-1"></i>{{ $a['title'] }}
                                        @if($a['is_new'])<span class="badge bg-danger ms-1">Baru</span>@endif
                                    </h6>
                                    <small class="text-muted">{{ $a['published_at'] }}</small>
                                </div>
                                <div class="announce-body text-muted">{!! $a['body'] !!}</div>
                                <small class="text-muted">— {{ $a['creator_name'] }}</small>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            @if($items->count() > 1)
                <button class="carousel-control-prev" type="button" data-bs-target="#announceCarousel" data-bs-slide="prev">
                    <span class="carousel-control-prev-icon bg-dark rounded" aria-hidden="true"></span>
                </button>
                <button class="carousel-control-next" type="button" data-bs-target="#announceCarousel" data-bs-slide="next">
                    <span class="carousel-control-next-icon bg-dark rounded" aria-hidden="true"></span>
                </button>
                <div class="carousel-indicators position-static mt-2">
                    @foreach($items as $i => $a)
                        <button type="button" data-bs-target="#announceCarousel" data-bs-slide-to="{{ $i }}" class="{{ $i === 0 ? 'active' : '' }} bg-dark"></button>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>

@push('custom-scripts')
<script>
    setTimeout(function () {
        fetch('{{ route('announcement.seen') }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ ids: @json($items->pluck('id')->all()) })
        }).catch(function () {});
    }, 2500);
</script>
@endpush
@endif
