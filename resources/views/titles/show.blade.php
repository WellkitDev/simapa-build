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
            <a href="{{ route('naskah.pelacakan', ['tipe' => $title->jenis === 'buku' ? 'buku' : 'artikel']) }}" class="btn btn-xs btn-outline-secondary ms-2">Buka Pelacakan Naskah</a>
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
                @can('title.info')
                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#chapAuthorForm">Edit Author Bab</button>
                @endcan
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
            @can('title.info')
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
            @endcan
        @endif
    @endif

    <div class="d-flex gap-2 flex-wrap">
        @if($canManage && $title->isEditable())
            @can('title.edit')
            <a href="{{ route('title.edit', $title->id) }}" class="btn btn-sm btn-outline-secondary">Edit</a>
            @endcan
            @can('title.submit')
            <form action="{{ route('title.submit', $title->id) }}" method="POST" class="m-0">@csrf<button class="btn btn-sm btn-info">Ajukan</button></form>
            @endcan
        @endif
        @if($isApprover && $title->status === 'menunggu')
            @can('title.approve')
            <form action="{{ route('title.approve', $title->id) }}" method="POST" class="m-0">@csrf<button class="btn btn-sm btn-success">Setujui</button></form>
            <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="collapse" data-bs-target="#rejectForm">Tolak</button>
            @endcan
        @endif
    </div>

    @if($isApprover && $title->status === 'menunggu')
        @can('title.approve')
        <div class="collapse mt-2" id="rejectForm">
            <form action="{{ route('title.reject', $title->id) }}" method="POST">@csrf
                <textarea name="reject_note" class="form-control mb-2" rows="2" placeholder="Alasan penolakan" required></textarea>
                <button class="btn btn-sm btn-danger">Kirim Penolakan</button>
            </form>
        </div>
        @endcan
    @endif
</div></div></div></div>

@if($canViewInfo && $title->jenis === 'artikel')
<div class="row"><div class="col-md-8 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="card-title mb-0">Informasi Publikasi</h6>
        @if($canEditInfo)
            @can('title.info')
            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#infoForm">Edit Informasi</button>
            @endcan
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
    @can('title.info')
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
    @endcan
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
            @can($title->bookIsbn ? 'isbn.edit' : 'isbn.create')
            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#isbnForm">Edit Registrasi ISBN</button>
            @endcan
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
            @can($isbn ? 'isbn.edit' : 'isbn.create')
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
            @endcan
        @endif
    @endif
</div></div></div></div>
@endif

@if($title->jenis === 'buku' && $canViewInfo)
<div class="row"><div class="col-md-8 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-1">
        <h6 class="card-title mb-0">Cek Kelengkapan Data</h6>
        <span class="badge {{ optional($docChecklist)->status === 'diajukan' ? 'bg-success' : 'bg-secondary' }}">
            {{ optional($docChecklist)->status === 'diajukan' ? 'Diajukan ' . optional($docChecklist->submitted_at)->format('d M Y') : 'Draft' }}
        </span>
    </div>
    <p class="text-muted small mb-0">
        Dokumen yang diperlukan untuk pengajuan ISBN &amp; HKI.
        @if($naskahDetailId)
            Berkas naskahnya sendiri dikelola di
            <a href="{{ route('naskah.show', $naskahDetailId) }}">Pelacakan Naskah</a>.
        @endif
    </p>

    @if($canMarkDocs)
    @can('title.doc.edit')
    <form method="POST" action="{{ route('title.doc.save', $title->id) }}" enctype="multipart/form-data">
        @csrf @method('PUT')
    @endcan
    @endif

    @foreach(\App\Models\DocRequirement::CATEGORIES as $catKey => $catLabel)
        @php
            $items = $docRequirements[$catKey] ?? collect();
            $prog  = $docProgress[$catKey];
            $pct   = $prog['total'] > 0 ? round($prog['done'] / $prog['total'] * 100) : 0;
        @endphp
        <div class="d-flex justify-content-between align-items-center mt-4 mb-2">
            <span class="fw-semibold text-body">{{ $catLabel }}</span>
            <span class="d-flex align-items-center gap-2">
                <span class="progress" style="width:88px; height:6px">
                    <span class="progress-bar {{ $pct == 100 ? 'bg-success' : 'bg-info' }}" role="progressbar" style="width:{{ $pct }}%"></span>
                </span>
                <span class="text-muted small">{{ $prog['done'] }}/{{ $prog['total'] }}</span>
            </span>
        </div>

        <div class="list-group list-group-flush">
            @forelse($items as $i => $req)
                @php
                    $mark = $docMarks[$req->id] ?? null;
                    $st   = optional($mark)->status ?? 'belum';
                    // Item otomatis: berkasnya milik Pelacakan Naskah, bukan diunggah di sini.
                    $auto     = $req->isAuto();
                    $autoFile = $auto ? ($docAutoFiles[$req->id] ?? null) : null;
                @endphp
                <div class="list-group-item px-0 py-3">
                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <div class="flex-grow-1" style="min-width:0">
                            <div class="fw-semibold" style="font-size:13px">{{ $i + 1 }}. {{ $req->label }}</div>
                            @if($req->description)<div class="text-muted mt-1" style="font-size:11px; line-height:1.4">{{ $req->description }}</div>@endif
                            @if($auto)
                                <div class="text-muted mt-1" style="font-size:11px; line-height:1.4">
                                    Terisi otomatis dari <strong>{{ $req->autoSourceLabel() }}</strong> —
                                    tidak perlu diunggah ulang di sini.
                                </div>
                            @endif
                        </div>
                        <div class="flex-shrink-0" style="width:132px">
                            @if($auto)
                                <span class="badge w-100 {{ $autoFile ? 'bg-success' : 'bg-secondary' }}">
                                    {{ $autoFile ? 'Ada (otomatis)' : 'Belum' }}
                                </span>
                            @elseif($canMarkDocs)
                                @can('title.doc.edit')
                                    <select name="marks[{{ $req->id }}][status]" class="form-select form-select-sm">
                                        @foreach(\App\Models\TitleDocMark::STATUSES as $sv => $sl)
                                            <option value="{{ $sv }}" {{ $st === $sv ? 'selected' : '' }}>{{ $sl }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <span class="badge w-100 {{ $st === 'ada' ? 'bg-success' : ($st === 'tidak_perlu' ? 'bg-light text-dark border' : 'bg-secondary') }}">{{ \App\Models\TitleDocMark::STATUSES[$st] ?? $st }}</span>
                                @endcan
                            @else
                                <span class="badge w-100 {{ $st === 'ada' ? 'bg-success' : ($st === 'tidak_perlu' ? 'bg-light text-dark border' : 'bg-secondary') }}">{{ \App\Models\TitleDocMark::STATUSES[$st] ?? $st }}</span>
                            @endif
                        </div>
                    </div>

                    @if($auto)
                        @if($autoFile)
                            <a href="{{ $autoFile->drive_url }}" target="_blank" rel="noopener"
                               class="d-inline-block text-truncate mt-2" style="max-width:100%; font-size:11px">
                                📎 {{ $autoFile->original_name ?: 'Naskah Final' }} (v{{ $autoFile->version }})
                            </a>
                        @elseif($naskahDetailId)
                            <a href="{{ route('naskah.show', $naskahDetailId) }}"
                               class="d-inline-block mt-2" style="font-size:11px">
                                Unggah di Pelacakan Naskah →
                            </a>
                        @endif
                    @else
                        @if(optional($mark)->file_url)
                            <a href="{{ $mark->file_url }}" target="_blank" rel="noopener" class="d-inline-block text-truncate mt-2" style="max-width:100%; font-size:11px">📎 {{ $mark->file_name ?: 'file' }}</a>
                        @endif

                        @if($canMarkDocs)
                            @can('title.doc.edit')
                                <div class="row g-2 mt-1">
                                    <div class="col-sm-6"><input type="file" name="marks[{{ $req->id }}][file]" class="form-control form-control-sm" aria-label="Unggah dokumen {{ $req->label }}"></div>
                                    <div class="col-sm-6"><input type="text" name="marks[{{ $req->id }}][catatan]" value="{{ optional($mark)->catatan }}" class="form-control form-control-sm" placeholder="Catatan (opsional)"></div>
                                </div>
                            @elseif(optional($mark)->catatan)
                                <div class="text-muted mt-1" style="font-size:11px">Catatan: {{ $mark->catatan }}</div>
                            @endcan
                        @elseif(optional($mark)->catatan)
                            <div class="text-muted mt-1" style="font-size:11px">Catatan: {{ $mark->catatan }}</div>
                        @endif
                    @endif
                </div>
            @empty
                <div class="list-group-item px-0 py-2 text-muted small">Belum ada item.</div>
            @endforelse
        </div>
    @endforeach

    @if($canMarkDocs)
        @can('title.doc.edit')
        <hr class="my-3">
        <button type="submit" class="btn btn-sm btn-primary">Simpan Kelengkapan</button>
    </form>
        @endcan
        @can('title.doc.submit')
        <form method="POST" action="{{ route('title.doc.submit', $title->id) }}" class="d-inline ms-2">@csrf
            <button type="submit" class="btn btn-sm btn-success">Submit &amp; Ajukan</button>
        </form>
        @endcan
    @endif

    @if($canManageDocReq)
        @canany(['doc-req.create', 'doc-req.edit', 'doc-req.delete'])
        <div class="mt-3">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#docTplForm">Kelola Template Dokumen</button>
            <div class="collapse mt-2" id="docTplForm">
                <p class="text-muted small mb-2">Item berlaku untuk semua buku.</p>
                @foreach(\App\Models\DocRequirement::CATEGORIES as $catKey => $catLabel)
                    <div class="text-muted small fw-semibold mt-2">{{ $catLabel }}</div>
                    @foreach(($docRequirements[$catKey] ?? collect()) as $req)
                        <div class="d-flex gap-1 mb-1 align-items-center">
                            @can('doc-req.edit')
                            <form method="POST" action="{{ route('doc-req.update', $req->id) }}" class="d-flex gap-1 flex-grow-1 m-0">
                                @csrf @method('PUT')
                                <input type="hidden" name="category" value="{{ $req->category }}">
                                <input name="label" value="{{ $req->label }}" class="form-control form-control-sm">
                                <input name="position" value="{{ $req->position }}" class="form-control form-control-sm" style="max-width:64px" title="Urutan">
                                <button class="btn btn-sm btn-outline-primary">Simpan</button>
                            </form>
                            @endcan
                            @can('doc-req.delete')
                            <form method="POST" action="{{ route('doc-req.destroy', $req->id) }}" class="m-0" data-confirm="Hapus item ini?">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">×</button></form>
                            @endcan
                        </div>
                    @endforeach
                    @can('doc-req.create')
                    <form method="POST" action="{{ route('doc-req.store') }}" class="d-flex gap-1 mt-1">
                        @csrf
                        <input type="hidden" name="category" value="{{ $catKey }}">
                        <input name="label" placeholder="Item baru untuk {{ $catLabel }}…" class="form-control form-control-sm">
                        <button class="btn btn-sm btn-outline-success">+ Tambah</button>
                    </form>
                    @endcan
                @endforeach
            </div>
        </div>
        @endcanany
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
