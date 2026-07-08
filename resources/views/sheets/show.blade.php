@extends('layouts.master')
@section('title', 'Lembar: ' . $sheet->name . ' - SiMAPA')

@push('style')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jspreadsheet-ce@4/dist/jspreadsheet.css" type="text/css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jsuites@5/dist/jsuites.css" type="text/css" />
@endpush

@section('content')
    @php $vis = \App\Models\CustomSheet::VISIBILITIES; $isOwner = $sheet->owner_id === auth()->id(); @endphp
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h5 class="mb-0">{{ $sheet->name }}
                <span class="badge {{ $sheet->visibility === 'shared' ? 'bg-info' : 'bg-light text-dark border' }} ms-1">{{ $vis[$sheet->visibility] ?? $sheet->visibility }}</span>
            </h5>
            <small class="text-muted">Pemilik: {{ optional($sheet->owner)->name ?? '-' }} · <span id="saveStatus">{{ $canEdit ? 'Perubahan tersimpan otomatis' : 'Hanya-baca' }}</span></small>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-sm btn-outline-success" onclick="window.exportCsv && window.exportCsv()">Export CSV</button>
            @if ($isOwner)
                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#formSetelan">Setelan</button>
            @endif
            <a href="{{ route('sheet.index') }}" class="btn btn-sm btn-outline-secondary">Kembali</a>
        </div>
    </div>

    @if ($isOwner)
        <div class="collapse mb-3" id="formSetelan">
            <form method="POST" action="{{ route('sheet.update', $sheet->id) }}" class="border rounded p-3">
                @csrf @method('PUT')
                <div class="row g-2 align-items-end">
                    <div class="col-md-4"><label class="form-label small mb-1">Nama</label><input name="name" value="{{ $sheet->name }}" class="form-control form-control-sm" required></div>
                    <div class="col-md-3"><label class="form-label small mb-1">Visibilitas</label>
                        <select name="visibility" class="form-select form-select-sm">
                            @foreach ($vis as $k => $v)<option value="{{ $k }}" {{ $sheet->visibility === $k ? 'selected' : '' }}>{{ $v }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-md-5"><label class="form-label small mb-1">Dibagikan ke role (kosong = semua)</label>
                        <div class="d-flex gap-2 flex-wrap">
                            @foreach (\Spatie\Permission\Models\Role::pluck('name') as $rn)
                                <label class="small mb-0"><input type="checkbox" name="shared_roles[]" value="{{ $rn }}" {{ in_array($rn, (array) $sheet->shared_roles) ? 'checked' : '' }}> {{ ucfirst($rn) }}</label>
                            @endforeach
                        </div>
                    </div>
                </div>
                <button class="btn btn-sm btn-primary mt-2">Simpan Setelan</button>
            </form>
        </div>
    @endif

    <div class="card"><div class="card-body">
        <div id="spreadsheet"></div>
    </div></div>
@endsection

@push('custom-scripts')
    <script src="https://cdn.jsdelivr.net/npm/jspreadsheet-ce@4/dist/index.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsuites@5/dist/jsuites.js"></script>
    <script>
        (function () {
            const factory = window.jspreadsheet || window.jexcel;
            const holder = document.getElementById('spreadsheet');
            if (!factory) { holder.innerHTML = '<div class="text-danger p-3">Gagal memuat grid (butuh internet untuk library).</div>'; return; }
            const initData = @json($sheet->data ?: []);
            const initColumns = @json($sheet->columns ?: []);
            const canEdit = @json($canEdit);
            const saveUrl = @json(route('sheet.save', $sheet->id));
            const csrf = @json(csrf_token());
            let saveTimer = null;

            const options = {
                data: (initData && initData.length) ? initData : [['', '', '', '', '', '']],
                minDimensions: [6, 15],
                tableOverflow: true,
                tableWidth: '100%',
                columnSorting: true,
                editable: canEdit,
                allowInsertRow: canEdit, allowInsertColumn: canEdit,
                allowDeleteRow: canEdit, allowDeleteColumn: canEdit,
                onchange: scheduleSave, oninsertrow: scheduleSave, ondeleterow: scheduleSave,
                oninsertcolumn: scheduleSave, ondeletecolumn: scheduleSave, onsort: scheduleSave, onmoverow: scheduleSave,
            };
            if (initColumns && initColumns.length) { options.columns = initColumns; }
            const instance = factory(holder, options);
            window.exportCsv = function () { try { instance.download(); } catch (e) {} };

            function scheduleSave() {
                if (!canEdit) return;
                setStatus('Menyimpan…');
                clearTimeout(saveTimer);
                saveTimer = setTimeout(doSave, 800);
            }
            function doSave() {
                let cols = [];
                try { cols = instance.getConfig().columns || []; } catch (e) {}
                fetch(saveUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                    body: JSON.stringify({ data: instance.getData(), columns: cols })
                }).then(r => r.json()).then(j => setStatus(j.ok ? ('Tersimpan ' + (j.saved_at || '')) : 'Gagal simpan'))
                  .catch(() => setStatus('Gagal simpan (jaringan)'));
            }
            function setStatus(t) { const el = document.getElementById('saveStatus'); if (el) el.textContent = t; }
        })();
    </script>
@endpush
