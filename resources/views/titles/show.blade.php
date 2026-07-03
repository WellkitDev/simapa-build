@extends('layouts.master')
@section('title', 'Detail Judul - SiMAPA')

@section('content')
@php
    $sb = ['draft' => 'bg-secondary', 'menunggu' => 'bg-warning text-dark', 'disetujui' => 'bg-success', 'ditolak' => 'bg-danger'];
    $sl = ['draft' => 'Draf', 'menunggu' => 'Menunggu', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak'];
@endphp
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0">@if($title->code)<span class="badge bg-dark align-middle me-1">{{ $title->code }}</span>@endif {{ $title->title }}</h5>
        <small class="text-muted">{{ ucfirst($title->jenis) }} · {{ ucfirst($title->tipe_naskah) }} · {{ $title->scope?->scope ?? 'Tanpa bidang ilmu' }} · {{ $title->indeksasi ?: 'Tanpa indeksasi' }}</small>
    </div>
    <a href="{{ route('title.index') }}" class="btn btn-sm btn-outline-secondary">Kembali</a>
</div>

<div class="row"><div class="col-md-8 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <p class="mb-2">Status: <span class="badge {{ $sb[$title->status] ?? 'bg-secondary' }}">{{ $sl[$title->status] ?? $title->status }}</span></p>
    <p class="mb-2"><small class="text-muted">Dibuat oleh {{ $title->creator?->name ?? '—' }}@if($title->approver) · disetujui {{ $title->approver->name }}@endif</small></p>
    <p class="mb-2">Distribusi: <span class="badge bg-light text-dark border">{{ $title->assignedMarketing?->name ?? 'Semua marketing' }}</span></p>
    <p class="mb-2">Order tertaut: <strong>Jml Order</strong> {{ $ordersCount }} · <strong>Jml Author</strong> {{ $authorsCount }}</p>
    @if($orderAuthors->isNotEmpty())
        <p class="mb-2">Author (dari order): <span class="text-muted">{{ $orderAuthors->pluck('name')->join(', ') }}</span></p>
    @endif
    @php $mstat = $title->manuscriptStatus(); @endphp
    <p class="mb-2">Manuskrip:
        @if($mstat)<span class="badge {{ in_array($mstat, \App\Models\TitleProgress::FINAL_STAGES, true) ? 'bg-success' : 'bg-info' }}">{{ $title->manuscriptStatusLabel() }}</span>@else<span class="text-muted">Belum ada order</span>@endif
        @if($canOpenBoard)
            <a href="{{ route('manuscript.board', ['tipe' => $title->jenis === 'buku' ? 'buku' : 'artikel']) }}" class="btn btn-xs btn-outline-secondary ms-2">Buka Papan Manuskrip</a>
        @endif
    </p>
    @if($title->orderDetails->isNotEmpty())
        <h6 class="card-title mt-3">Order Tertaut</h6>
        <div class="table-responsive">
            <table class="table table-sm table-borderless mb-3">
                <thead><tr><th>Kode Order</th><th>Marketing</th><th>Tanggal</th><th>Manuskrip</th></tr></thead>
                <tbody>
                    @foreach($title->orderDetails as $od)
                        <tr>
                            <td>{{ $od->order?->code_order ?? '—' }}</td>
                            <td>{{ $od->order?->user?->name ?? '—' }}</td>
                            <td>{{ optional($od->order?->ordered_at)->format('d M Y') ?? '—' }}</td>
                            <td>{{ \App\Models\Title::stageLabel(optional($od->titleProgress)->status) ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    @if($title->status === 'ditolak' && $title->reject_note)
        <div class="alert alert-danger py-2"><strong>Ditolak:</strong> {{ $title->reject_note }}</div>
    @endif

    @if($title->jenis === 'buku')
        <div class="d-flex justify-content-between align-items-center mt-3">
            <h6 class="card-title mb-0">Bab & Author</h6>
            @if($canEditInfo && $title->chapters->isNotEmpty())
                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#chapAuthorForm">Edit Author Bab</button>
            @endif
        </div>
        <ol class="mb-2">
            @forelse($title->chapters as $ch)
                <li>{{ $ch->judul }}@if($ch->authors->isNotEmpty()) — <span class="text-muted">{{ $ch->authors->pluck('name')->join(', ') }}</span>@endif</li>
            @empty
                <li class="text-muted">Belum ada bab.</li>
            @endforelse
        </ol>
        @if($canEditInfo && $title->chapters->isNotEmpty())
            <div class="collapse mb-3" id="chapAuthorForm">
                <form method="POST" action="{{ route('title.chapters.authors', $title->id) }}">
                    @csrf @method('PUT')
                    @foreach($title->chapters as $ch)
                        <div class="mb-2">
                            <label class="form-label small mb-1">{{ $ch->urutan }}. {{ $ch->judul }}</label>
                            <select name="chapter_authors[{{ $ch->id }}][]" multiple class="form-select form-select-sm select2-authors" data-tags="true">
                                @foreach($allAuthors as $a)
                                    <option value="{{ $a->id }}" {{ $ch->authors->contains($a->id) ? 'selected' : '' }}>{{ $a->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                    <button type="submit" class="btn btn-sm btn-primary">Simpan Author Bab</button>
                    <small class="text-muted d-block mt-1">Bab terisi otomatis dari author order; sesuaikan di sini untuk bunga rampai (beda penulis per bab). Ketik nama untuk author baru; urutan pilihan = urutan author.</small>
                </form>
            </div>
        @endif
    @endif

    <div class="d-flex gap-2 flex-wrap">
        @if($canManage && $title->isEditable())
            <a href="{{ route('title.edit', $title->id) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
            <form action="{{ route('title.submit', $title->id) }}" method="POST" class="m-0">@csrf<button class="btn btn-sm btn-info">Ajukan</button></form>
        @endif
        @if($isApprover && $title->status === 'menunggu')
            <form action="{{ route('title.approve', $title->id) }}" method="POST" class="m-0">@csrf<button class="btn btn-sm btn-success">Setujui</button></form>
            <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="collapse" data-bs-target="#rejectForm">Tolak</button>
        @endif
    </div>

    @if($isApprover && $title->status === 'menunggu')
        <div class="collapse mt-2" id="rejectForm">
            <form action="{{ route('title.reject', $title->id) }}" method="POST">@csrf
                <textarea name="reject_note" class="form-control mb-2" rows="2" placeholder="Alasan penolakan" required></textarea>
                <button class="btn btn-sm btn-danger">Kirim Penolakan</button>
            </form>
        </div>
    @endif
</div></div></div></div>

@if($canViewInfo && $title->jenis === 'artikel')
<div class="row"><div class="col-md-8 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="card-title mb-0">Informasi Publikasi</h6>
        @if($canEditInfo)
            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#infoForm">Edit Informasi</button>
        @endif
    </div>

    <dl class="row mb-2">
        <dt class="col-sm-4 text-muted small">Kode</dt><dd class="col-sm-8">{{ $title->code ?? '—' }}</dd>
        <dt class="col-sm-4 text-muted small">Target Terbit</dt><dd class="col-sm-8">{{ optional($title->target_terbit)->format('d M Y') ?? '—' }}</dd>
        <dt class="col-sm-4 text-muted small">Jurnal Target</dt><dd class="col-sm-8">{{ $title->jurnal_target ?? '—' }}@if($title->jurnal_link) · <a href="{{ $title->jurnal_link }}" target="_blank" rel="noopener">link</a>@endif</dd>
        <dt class="col-sm-4 text-muted small">Template Artikel</dt><dd class="col-sm-8">@if($title->template_link)<a href="{{ $title->template_link }}" target="_blank" rel="noopener">{{ $title->template_link }}</a>@else — @endif</dd>
        <dt class="col-sm-4 text-muted small">APC</dt><dd class="col-sm-8">{{ $title->apc_info ?? '—' }}</dd>
        <dt class="col-sm-4 text-muted small">Catatan</dt><dd class="col-sm-8">{{ $title->catatan_publikasi ?? '—' }}</dd>
    </dl>

    <h6 class="text-muted small mt-2">Opsi Jurnal Lain</h6>
    @forelse($title->journalOptions as $opt)
        <div class="small mb-1">•
            @if($opt->journal_id && $opt->journal)
                <a href="{{ route('journal.show', $opt->journal_id) }}">{{ $opt->nama_jurnal }}</a> <span class="badge bg-light text-dark border" style="font-size:9px">direktori</span>
            @else
                {{ $opt->nama_jurnal }}
            @endif
            @if($opt->link) · <a href="{{ $opt->link }}" target="_blank" rel="noopener">link</a>@endif @if($opt->apc)· APC: {{ $opt->apc }}@endif</div>
    @empty
        <div class="small text-muted mb-1">Belum ada opsi jurnal.</div>
    @endforelse

    @if($canEditInfo)
    <div class="collapse mt-3" id="infoForm">
        <form method="POST" action="{{ route('title.info.update', $title->id) }}">
            @csrf @method('PUT')
            <div class="row">
                <div class="col-md-4 mb-2">
                    <label class="form-label">Kode</label>
                    <input type="text" name="code" class="form-control @error('code') is-invalid @enderror" value="{{ old('code', $title->code) }}" maxlength="16">
                    @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <small class="text-muted">Kosongkan untuk buat ulang dari judul.</small>
                </div>
                <div class="col-md-4 mb-2">
                    <label class="form-label">Target Terbit</label>
                    <input type="text" name="target_terbit" class="form-control flatpickr-date" value="{{ old('target_terbit', optional($title->target_terbit)->format('Y-m-d')) }}" placeholder="YYYY-MM-DD">
                </div>
                <div class="col-md-4 mb-2">
                    <label class="form-label">APC</label>
                    <input type="text" name="apc_info" class="form-control" value="{{ old('apc_info', $title->apc_info) }}">
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-2">
                    <label class="form-label">Jurnal Target</label>
                    <input type="text" name="jurnal_target" class="form-control" value="{{ old('jurnal_target', $title->jurnal_target) }}">
                </div>
                <div class="col-md-6 mb-2">
                    <label class="form-label">Link Jurnal</label>
                    <input type="text" name="jurnal_link" class="form-control" value="{{ old('jurnal_link', $title->jurnal_link) }}">
                </div>
            </div>
            <div class="mb-2">
                <label class="form-label">Link Template Artikel</label>
                <input type="text" name="template_link" class="form-control" value="{{ old('template_link', $title->template_link) }}">
            </div>
            <div class="mb-2">
                <label class="form-label">Catatan</label>
                <textarea name="catatan_publikasi" class="form-control" rows="2">{{ old('catatan_publikasi', $title->catatan_publikasi) }}</textarea>
            </div>

            <label class="form-label">Opsi Jurnal Lain</label>
            <div id="joList">
                @foreach($title->journalOptions as $i => $opt)
                    <div class="border rounded p-2 mb-1" data-jo-row>
                        <div class="row g-1">
                            <div class="col-md-11">
                                <select name="journal_options[{{ $i }}][journal_id]" class="form-select form-select-sm select2-journal">
                                    <option value="">— pilih dari direktori (opsional) —</option>
                                    @foreach($journals as $j)
                                        <option value="{{ $j->id }}" {{ $opt->journal_id == $j->id ? 'selected' : '' }}>{{ $j->nama }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger w-100" data-jo-remove>×</button></div>
                        </div>
                        <div class="row g-1 mt-1">
                            <div class="col-md-5"><input type="text" name="journal_options[{{ $i }}][nama_jurnal]" class="form-control form-control-sm" value="{{ $opt->nama_jurnal }}" placeholder="Nama jurnal (manual)"></div>
                            <div class="col-md-4"><input type="text" name="journal_options[{{ $i }}][link]" class="form-control form-control-sm" value="{{ $opt->link }}" placeholder="Link"></div>
                            <div class="col-md-3"><input type="text" name="journal_options[{{ $i }}][apc]" class="form-control form-control-sm" value="{{ $opt->apc }}" placeholder="APC"></div>
                        </div>
                    </div>
                @endforeach
            </div>
            <template id="joRowTpl">
                <div class="border rounded p-2 mb-1" data-jo-row>
                    <div class="row g-1">
                        <div class="col-md-11">
                            <select name="journal_options[__IDX__][journal_id]" class="form-select form-select-sm select2-journal">
                                <option value="">— pilih dari direktori (opsional) —</option>
                                @foreach($journals as $j)
                                    <option value="{{ $j->id }}">{{ $j->nama }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger w-100" data-jo-remove>×</button></div>
                    </div>
                    <div class="row g-1 mt-1">
                        <div class="col-md-5"><input type="text" name="journal_options[__IDX__][nama_jurnal]" class="form-control form-control-sm" placeholder="Nama jurnal (manual)"></div>
                        <div class="col-md-4"><input type="text" name="journal_options[__IDX__][link]" class="form-control form-control-sm" placeholder="Link"></div>
                        <div class="col-md-3"><input type="text" name="journal_options[__IDX__][apc]" class="form-control form-control-sm" placeholder="APC"></div>
                    </div>
                </div>
            </template>
            <small class="text-muted d-block mb-2">Pilih jurnal dari direktori (nama/link/APC otomatis), atau isi manual bila belum terdaftar.</small>
            <button type="button" class="btn btn-sm btn-outline-primary mb-3" id="joAdd">+ Opsi Jurnal</button>

            <div><button type="submit" class="btn btn-sm btn-primary">Simpan Informasi</button></div>
        </form>
    </div>
    @endif

    @if($title->logs->isNotEmpty())
        <h6 class="text-muted small mt-3">Riwayat Perubahan</h6>
        <ul class="list-unstyled small mb-0">
            @foreach($title->logs->take(10) as $log)
                <li class="mb-1">• {{ $log->note }} — <span class="text-muted">{{ $log->changedBy?->name ?? '—' }}, {{ optional($log->created_at)->format('d M Y H:i') }}</span></li>
            @endforeach
        </ul>
    @endif
</div></div></div></div>
@endif

@if($title->jenis === 'buku' && $canViewInfo)
<div class="row"><div class="col-md-8 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="card-title mb-0">Registrasi ISBN</h6>
        @if($canManageIsbn && $title->isbnEligible())
            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#isbnForm">Edit Registrasi ISBN</button>
        @endif
    </div>

    @if(! $title->isbnEligible())
        <p class="text-muted mb-0">Registrasi ISBN tersedia setelah manuskrip mencapai tahap ISBN.</p>
    @else
        @php $isbn = $title->bookIsbn; @endphp
        <dl class="row mb-2">
            <dt class="col-sm-4 text-muted small">Status</dt><dd class="col-sm-8">@if($isbn)<span class="badge bg-info">{{ $isbn->statusLabel() }}</span>@else<span class="text-muted">Belum didaftarkan</span>@endif</dd>
            <dt class="col-sm-4 text-muted small">No. Pendaftaran</dt><dd class="col-sm-8">{{ $isbn?->no_pendaftaran ?: '—' }}</dd>
            <dt class="col-sm-4 text-muted small">No. ISBN</dt><dd class="col-sm-8">{{ $isbn?->no_isbn ?: '—' }}</dd>
            <dt class="col-sm-4 text-muted small">No. Buku Cetak</dt><dd class="col-sm-8">{{ $isbn?->no_buku_cetak ?: '—' }}</dd>
            <dt class="col-sm-4 text-muted small">Penerbit</dt><dd class="col-sm-8">{{ $isbn?->penerbit ?: '—' }}</dd>
            <dt class="col-sm-4 text-muted small">Tgl Daftar</dt><dd class="col-sm-8">{{ optional($isbn?->tgl_daftar)->format('d M Y') ?? '—' }}</dd>
            <dt class="col-sm-4 text-muted small">Tgl ISBN</dt><dd class="col-sm-8">{{ optional($isbn?->tgl_isbn)->format('d M Y') ?? '—' }}</dd>
            <dt class="col-sm-4 text-muted small">Tgl Terbit</dt><dd class="col-sm-8">{{ optional($isbn?->tgl_terbit)->format('d M Y') ?? '—' }}</dd>
            <dt class="col-sm-4 text-muted small">Catatan</dt><dd class="col-sm-8">{{ $isbn?->catatan ?: '—' }}</dd>
        </dl>

        @if($canManageIsbn)
            <div class="collapse" id="isbnForm">
                <form method="POST" action="{{ $isbn ? route('isbn.update', $isbn->id) : route('isbn.store') }}">
                    @csrf
                    @if($isbn) @method('PUT') @else <input type="hidden" name="title_id" value="{{ $title->id }}"> @endif
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label small mb-1">Status</label>
                            <select name="status" id="isbnStatus" class="form-select form-select-sm">
                                @foreach(\App\Models\BookIsbn::STATUSES as $val => $lbl)
                                    <option value="{{ $val }}" {{ optional($isbn)->status === $val ? 'selected' : '' }}>{{ $lbl }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4"><label class="form-label small mb-1">No. Pendaftaran <span class="text-danger d-none" data-isbn-req="pendaftaran">*</span></label><input name="no_pendaftaran" id="isbnNoPendaftaran" value="{{ optional($isbn)->no_pendaftaran }}" class="form-control form-control-sm"></div>
                        <div class="col-md-4"><label class="form-label small mb-1">No. ISBN <span class="text-danger d-none" data-isbn-req="ber_isbn">*</span></label><input name="no_isbn" id="isbnNoIsbn" value="{{ optional($isbn)->no_isbn }}" class="form-control form-control-sm"></div>
                        <div class="col-md-4"><label class="form-label small mb-1">No. Buku Cetak <span class="text-danger d-none" data-isbn-req="cetak">*</span></label><input name="no_buku_cetak" id="isbnNoCetak" value="{{ optional($isbn)->no_buku_cetak }}" class="form-control form-control-sm"></div>
                        <div class="col-md-4"><label class="form-label small mb-1">Penerbit</label><input name="penerbit" value="{{ optional($isbn)->penerbit }}" class="form-control form-control-sm"></div>
                        <div class="col-md-4"><label class="form-label small mb-1">Tgl Daftar</label><input type="text" name="tgl_daftar" value="{{ optional(optional($isbn)->tgl_daftar)->format('Y-m-d') }}" class="form-control form-control-sm flatpickr-date" placeholder="YYYY-MM-DD"></div>
                        <div class="col-md-4"><label class="form-label small mb-1">Tgl ISBN</label><input type="text" name="tgl_isbn" value="{{ optional(optional($isbn)->tgl_isbn)->format('Y-m-d') }}" class="form-control form-control-sm flatpickr-date" placeholder="YYYY-MM-DD"></div>
                        <div class="col-md-4"><label class="form-label small mb-1">Tgl Terbit</label><input type="text" name="tgl_terbit" value="{{ optional(optional($isbn)->tgl_terbit)->format('Y-m-d') }}" class="form-control form-control-sm flatpickr-date" placeholder="YYYY-MM-DD"></div>
                        <div class="col-12"><label class="form-label small mb-1">Catatan</label><textarea name="catatan" rows="2" class="form-control form-control-sm">{{ optional($isbn)->catatan }}</textarea></div>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary mt-2">Simpan Registrasi ISBN</button>
                </form>
            </div>
        @endif
    @endif
</div></div></div></div>
@endif
@endsection

@push('plugin-styles')
<link href="{{ asset('assets/plugins/flatpickr/flatpickr.min.css') }}" rel="stylesheet" />
<link href="{{ asset('assets/plugins/select2/select2.min.css') }}" rel="stylesheet" />
@endpush
@push('plugin-scripts')
<script src="{{ asset('assets/plugins/flatpickr/flatpickr.min.js') }}"></script>
<script src="{{ asset('assets/plugins/select2/select2.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
$(function () {
    if (window.flatpickr) { flatpickr('.flatpickr-date', { dateFormat: 'Y-m-d', allowInput: true }); }
    if (window.jQuery && jQuery.fn.select2) {
        jQuery('.select2-authors').select2({ tags: true, width: '100%', placeholder: 'Pilih / ketik author…' });
    }
    var list = document.getElementById('joList');
    var addBtn = document.getElementById('joAdd');
    var tpl = document.getElementById('joRowTpl');
    var idx = list ? list.querySelectorAll('[data-jo-row]').length : 0;

    function initJournalSelect(scope) {
        if (!window.jQuery || !jQuery.fn.select2) return;
        jQuery(scope).find('.select2-journal').each(function () {
            if (!jQuery(this).hasClass('select2-hidden-accessible')) {
                jQuery(this).select2({ width: '100%', placeholder: 'Cari jurnal…', allowClear: true });
            }
        });
    }
    if (list) initJournalSelect(list);

    if (addBtn && tpl) addBtn.addEventListener('click', function () {
        var html = tpl.innerHTML.replace(/__IDX__/g, idx);
        var wrap = document.createElement('div');
        wrap.innerHTML = html.trim();
        var row = wrap.firstElementChild;
        list.appendChild(row);
        initJournalSelect(row);
        idx++;
    });
    if (list) list.addEventListener('click', function (e) {
        var b = e.target.closest('[data-jo-remove]');
        if (b) b.closest('[data-jo-row]').remove();
    });

    // Nomor yang wajib mengikuti status terpilih pada form registrasi.
    var isbnStatus = document.getElementById('isbnStatus');
    if (isbnStatus) {
        var isbnFields = { pendaftaran: 'isbnNoPendaftaran', ber_isbn: 'isbnNoIsbn', cetak: 'isbnNoCetak' };
        var applyIsbnRequired = function () {
            Object.keys(isbnFields).forEach(function (st) {
                var el = document.getElementById(isbnFields[st]);
                if (el) el.required = false;
            });
            document.querySelectorAll('[data-isbn-req]').forEach(function (s) { s.classList.add('d-none'); });
            var target = document.getElementById(isbnFields[isbnStatus.value]);
            if (target) target.required = true;
            var star = document.querySelector('[data-isbn-req="' + isbnStatus.value + '"]');
            if (star) star.classList.remove('d-none');
        };
        isbnStatus.addEventListener('change', applyIsbnRequired);
        applyIsbnRequired();
    }
});
</script>
@endpush
