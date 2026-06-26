@php $items = $deadlines ?? collect(); @endphp
@if($items->isNotEmpty())
<div class="row">
    <div class="col-12 grid-margin stretch-card">
        <div class="card border-start border-4 border-warning">
            <div class="card-body">
                <h6 class="mb-2 text-warning"><i data-feather="clock" class="icon-sm me-1"></i>Tugas Mendekati Deadline ({{ $items->count() }})</h6>
                <ul class="list-group list-group-flush">
                    @foreach($items as $t)
                        @php $days = (int) \Illuminate\Support\Carbon::today()->diffInDays($t->due_date, false); @endphp
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-1">
                            <span style="font-size:13px">
                                {{ $t->title }}
                                @if($deadlineIsOverseer ?? false)<span class="text-muted">· {{ $t->user?->name }}</span>@endif
                            </span>
                            <span class="badge {{ $days <= 2 ? 'bg-danger' : 'bg-warning text-dark' }}">
                                {{ $days < 0 ? 'Lewat ' . abs($days) . 'h' : ($days === 0 ? 'Hari ini' : $days . ' hari lagi') }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</div>

@push('custom-scripts')
<script>
(function () {
    if (!window.Swal || sessionStorage.getItem('deadlineAlertShown')) return;
    sessionStorage.setItem('deadlineAlertShown', '1');
    var list = @json($items->map(fn ($t) => $t->title)->values());
    Swal.fire({
        icon: 'warning',
        title: 'Tugas mendekati deadline',
        html: '<ul style="text-align:left">' + list.map(function (t) { var li = document.createElement('li'); li.textContent = t; return li.outerHTML; }).join('') + '</ul>',
        confirmButtonText: 'Mengerti'
    });
})();
</script>
@endpush
@endif
