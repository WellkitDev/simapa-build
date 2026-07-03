@extends('layouts.master')
@section('title', 'Manuscript Tracker - SiMAPA')

@section('content')
@php
    $statusColors = [
        'menunggu_proses' => '#94A3B8',
        'templating' => '#F59E0B', 'editing' => '#F59E0B', 'layout' => '#F59E0B',
        'revisi' => '#FB923C', 'proofreading' => '#FB923C', 'isbn' => '#FB923C',
        'submit' => '#4C5FD5', 'cetak' => '#4C5FD5', 'loa' => '#4C5FD5',
        'publish' => '#22C55E', 'terbit' => '#22C55E',
    ];
@endphp
@include('manuscript.partials.toolbar')

<div style="overflow-x:auto">
        <div class="d-flex gap-4 pb-2" style="min-width:max-content; align-items:flex-start">
            @foreach($zones as $zone)
                <div class="manuscript-zone" style="flex-shrink:0; padding:12px 14px; border-radius:14px;
                    background:{{ $zone['tint'] }}; border:1px solid {{ $zone['accent'] }}40;
                    border-top:3px solid {{ $zone['accent'] }}; box-shadow:0 6px 18px rgba(15,23,42,.10)">
                    <div class="d-flex align-items-center gap-2 mb-3" style="padding:0 2px">
                        <span style="font-size:11px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:{{ $zone['accent'] }}">{{ $zone['label'] }}</span>
                        <span class="text-muted" style="font-size:10px">· {{ $zone['sub'] }}</span>
                    </div>
                    <div class="d-flex gap-3">
                        @foreach($zone['stages'] as $stage)
                            @php $cards = $byStatus[$stage] ?? collect(); @endphp
                            <div data-stage-col style="width:264px; flex-shrink:0">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <span style="width:8px;height:8px;border-radius:50%;display:inline-block;background:{{ $statusColors[$stage] ?? '#94A3B8' }}"></span>
                                    <strong style="font-size:13px">{{ Str::title(str_replace('_', ' ', $stage)) }}</strong>
                                    <span class="badge bg-light text-muted" data-count>{{ $cards->count() }}</span>
                                </div>
                                <div data-column data-status="{{ $stage }}" style="min-height:60px; background:rgba(255,255,255,.7); border-radius:8px; padding:8px">
                                    @forelse($cards as $detail)
                                        @include('manuscript.partials.card')
                                    @empty
                                        <div class="text-muted text-center py-3" style="font-size:11px">Tidak ada naskah</div>
                                    @endforelse
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>

{{-- Modal catatan untuk koreksi/lompat tahap --}}
<div class="modal fade" id="mtNoteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Koreksi / Lompat Tahap</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-2" style="font-size:13px">
                    Pemindahan ini <strong>melompati atau memundurkan</strong> urutan normal.
                    Wajib beri catatan alasannya.
                </p>
                <div id="mtNoteTarget" class="mb-2 fw-semibold text-primary" style="font-size:13px"></div>
                <textarea id="mtNoteText" class="form-control" rows="3" placeholder="Catatan / alasan (wajib)"></textarea>
                <div id="mtNoteErr" class="text-danger mt-1" style="font-size:12px; display:none">Catatan wajib diisi.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-sm btn-primary" id="mtNoteSubmit">Terapkan</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/plugins/sortablejs/Sortable.min.js') }}"></script>
@endpush

@push('custom-scripts')
<script>
(function () {
    const token  = document.querySelector('meta[name="_token"]').getAttribute('content');
    const base   = "{{ url('management/manuscript') }}";
    const stages = @json($stages);

    const modalEl = document.getElementById('mtNoteModal');
    const noteModal = (modalEl && window.bootstrap) ? new bootstrap.Modal(modalEl) : null;
    let pending = null; // { item, fromCol, oldIndex, id, target }

    function toast(msg, ok) {
        const el = document.createElement('div');
        el.className = 'alert alert-' + (ok ? 'success' : 'danger');
        el.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:9999;min-width:240px;box-shadow:0 2px 8px rgba(0,0,0,.15)';
        el.textContent = msg;
        document.body.appendChild(el);
        setTimeout(() => el.remove(), 4000);
    }

    function refreshCounts() {
        document.querySelectorAll('[data-stage-col]').forEach((col) => {
            const list = col.querySelector('[data-column]');
            const badge = col.querySelector('[data-count]');
            if (list && badge) badge.textContent = list.querySelectorAll('[data-id]').length;
        });
    }

    function revert(p) {
        const ref = p.fromCol.children[p.oldIndex] || null;
        p.fromCol.insertBefore(p.item, ref);
    }

    function sendMove(p, note) {
        fetch(base + '/' + p.id + '/move', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ status: p.target, note: note }),
        })
        .then(async (res) => {
            const data = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(data.message || 'Gagal memindahkan naskah.');
            p.item.setAttribute('data-status', p.target);
            toast(data.message || 'Naskah dipindahkan.', true);
            refreshCounts();
        })
        .catch((err) => { revert(p); toast(err.message, false); });
    }

    if (noteModal) {
        document.getElementById('mtNoteSubmit').addEventListener('click', () => {
            const txt = document.getElementById('mtNoteText').value.trim();
            const err = document.getElementById('mtNoteErr');
            if (!txt) { err.style.display = 'block'; return; }
            err.style.display = 'none';
            const p = pending; pending = null; // tandai sudah diproses agar tidak di-revert saat modal tutup
            noteModal.hide();
            sendMove(p, txt);
        });
        modalEl.addEventListener('hidden.bs.modal', () => {
            if (pending) { revert(pending); pending = null; } // ditutup tanpa Terapkan → batal
        });
    }

    document.querySelectorAll('[data-column]').forEach((col) => {
        new Sortable(col, {
            group: 'manuscript',
            animation: 150,
            ghostClass: 'opacity-50',
            filter: '[data-no-drag]',
            preventOnFilter: false,
            onEnd: function (evt) {
                const item = evt.item, toCol = evt.to, fromCol = evt.from;
                if (toCol === fromCol) return;

                const target = toCol.getAttribute('data-status');
                const source = fromCol.getAttribute('data-status');
                const p = { item, fromCol, oldIndex: evt.oldIndex, id: item.getAttribute('data-id'), target };

                const isCorrection = (stages.indexOf(target) !== stages.indexOf(source) + 1);

                if (!isCorrection) { sendMove(p, null); return; }

                // Lompat/mundur → minta catatan wajib.
                if (!noteModal) { revert(p); toast('Lompat tahap perlu catatan — gunakan halaman detail.', false); return; }
                pending = p;
                document.getElementById('mtNoteText').value = '';
                document.getElementById('mtNoteErr').style.display = 'none';
                document.getElementById('mtNoteTarget').textContent = 'Pindah ke: ' + target.replace(/_/g, ' ');
                noteModal.show();
            },
        });
    });

    if (!location.search.includes('view=') && window.innerWidth < 768) {
        const sep = location.search ? '&' : '?';
        location.replace(location.pathname + location.search + sep + 'view=list');
    }

    const CHAPTER_STAGES = @json(collect(\App\Models\TitleProgress::BOOK_STAGES)
        ->map(fn ($s) => ['value' => $s, 'label' => \App\Models\Title::stageLabel($s)])->values());

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-chapter-edit]');
        if (!btn) return;
        e.preventDefault();
        const cp      = btn.getAttribute('data-cp');
        const current = btn.getAttribute('data-current');
        const next    = btn.getAttribute('data-next');
        const judul   = btn.getAttribute('data-judul') || 'Bab';

        const options = CHAPTER_STAGES
            .filter((s) => s.value !== current)
            .map((s) => '<option value="' + s.value + '">' + s.label + '</option>')
            .join('');

        Swal.fire({
            titleText: 'Ubah tahap: ' + judul,
            html:
                '<select id="swal-stage" class="form-select form-select-sm mb-2">' + options + '</select>' +
                '<textarea id="swal-note" class="form-control form-control-sm" rows="2" placeholder="Catatan (wajib bila mundur/lompat)"></textarea>',
            showCancelButton: true,
            confirmButtonText: 'Simpan',
            cancelButtonText: 'Batal',
            focusConfirm: false,
            preConfirm: () => {
                const target = document.getElementById('swal-stage').value;
                const note   = document.getElementById('swal-note').value.trim();
                if (target !== next && note === '') {
                    Swal.showValidationMessage('Catatan wajib untuk lompat/mundur.');
                    return false;
                }
                return { target, note };
            },
        }).then((result) => {
            if (!result.isConfirmed) return;
            fetch(base + '/chapter/' + cp + '/advance', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ status: result.value.target, note: result.value.note }),
            })
            .then(async (res) => { const d = await res.json().catch(() => ({})); if (!res.ok) throw new Error(d.message || 'Gagal.'); toast(d.message || 'Bab diperbarui.', true); setTimeout(() => location.reload(), 500); })
            .catch((err) => toast(err.message, false));
        });
    });

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-chapter-advance]');
        if (!btn) return;
        e.preventDefault();
        fetch(base + '/chapter/' + btn.getAttribute('data-cp') + '/advance', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ status: btn.getAttribute('data-next') }),
        })
        .then(async (res) => { const d = await res.json().catch(() => ({})); if (!res.ok) throw new Error(d.message || 'Gagal.'); toast(d.message || 'Bab diperbarui.', true); setTimeout(() => location.reload(), 500); })
        .catch((err) => toast(err.message, false));
    });

    document.addEventListener('change', function (e) {
        const sel = e.target.closest('[data-chapter-assign]');
        if (!sel) return;
        fetch(base + '/chapter/' + sel.getAttribute('data-cp') + '/assign', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ assigned_user_id: sel.value }),
        })
        .then(async (res) => { const d = await res.json().catch(() => ({})); if (!res.ok) throw new Error(d.message || 'Gagal.'); toast(d.message || 'Editor bab diperbarui.', true); })
        .catch((err) => toast(err.message, false));
    });
})();
</script>
@endpush
