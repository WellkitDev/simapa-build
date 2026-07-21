@extends('layouts.master')
@section('title', 'Detail Arsip - SiMAPA')

@section('content')
@php $st = optional($title->archive)->status ?? 'draft'; @endphp
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0">@if($title->code)<span class="badge bg-dark me-1">{{ $title->code }}</span>@endif {{ $title->title }}</h5>
        <small class="text-muted">{{ ucfirst($title->jenis) }} · {{ ucfirst($title->tipe_naskah) }} · {{ $title->scope?->scope ?? '—' }}
            · <span class="badge {{ $st === 'disetujui' ? 'bg-success' : ($st === 'diajukan' ? 'bg-warning text-dark' : ($st === 'ditolak' ? 'bg-danger' : 'bg-secondary')) }}">{{ \App\Models\TitleArchive::STATUSES[$st] ?? $st }}</span>
        </small>
    </div>
    <div class="d-flex gap-2">
        @if($st === 'disetujui' && $canManage)
            <a href="{{ route('archive.pdf', $title->id) }}" target="_blank" class="btn btn-sm btn-outline-dark">Export PDF</a>
        @endif
        <a href="{{ route('archive.index') }}" class="btn btn-sm btn-outline-secondary">Kembali</a>
    </div>
</div>

{{-- Kelayakan + aksi Ajukan --}}
<div class="row"><div class="col-md-9 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <h6 class="card-title">Kelayakan Arsip</h6>
    <span class="badge {{ $isPaidOff ? 'bg-success' : 'bg-secondary' }}">Pembayaran {{ $isPaidOff ? 'Lunas' : 'Belum Lunas' }}</span>
    <span class="badge {{ $isFinal ? 'bg-success' : 'bg-secondary' }}">Manuskrip {{ $isFinal ? 'Final' : 'Belum Final' }}</span>
    @if($canManage && in_array($st, ['draft', 'ditolak'], true))
        @can('archive.submit')
        <form method="POST" action="{{ route('archive.submit', $title->id) }}" class="d-inline ms-2">@csrf
            <button class="btn btn-sm btn-primary" {{ $eligible ? '' : 'disabled' }}>Ajukan ke Arsip</button>
        </form>
        @unless($eligible)<small class="text-muted d-block mt-1">Bisa diajukan setelah pembayaran lunas dan manuskrip final.</small>@endunless
        @endcan
    @endif
    @if($st === 'ditolak' && optional($title->archive)->reject_note)
        <div class="alert alert-danger py-2 mt-2 mb-0">Ditolak: {{ $title->archive->reject_note }}</div>
    @endif
</div></div></div></div>

{{-- Info Order --}}
<div class="row"><div class="col-md-9 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <h6 class="card-title">Info Order</h6>
    <div class="table-responsive"><table class="table table-sm">
        <thead><tr><th>Kode Order</th><th>Marketing</th><th>Tanggal</th><th>Biaya</th><th>Pembayaran</th></tr></thead>
        <tbody>
        @forelse($title->orderDetails as $od)
            <tr>
                <td>{{ $od->order?->code_order ?? '—' }}</td>
                <td>{{ $od->order?->user?->name ?? '—' }}</td>
                <td>{{ optional($od->order?->ordered_at)->format('d M Y') ?? '—' }}</td>
                <td>Rp {{ number_format((int) $od->cost_amount, 0, ',', '.') }}</td>
                <td>@if($od->order && $od->order->isLunas())<span class="badge bg-success">Lunas</span>@else<span class="badge bg-secondary">Belum</span>@endif</td>
            </tr>
        @empty
            <tr><td colspan="5" class="text-muted">Belum ada order.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div></div></div></div>

{{-- Info Manuskrip --}}
<div class="row"><div class="col-md-9 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <h6 class="card-title">Info Manuskrip</h6>
    <p class="mb-1">Status: <span class="badge {{ $isFinal ? 'bg-success' : 'bg-info' }}">{{ $title->manuscriptStatusLabel() ?? 'Belum ada order' }}</span></p>
    @if($title->jenis === 'buku' && $title->chapters->isNotEmpty())
        <ol class="mb-0 small">@foreach($title->chapters as $ch)<li>{{ $ch->judul }}</li>@endforeach</ol>
    @endif
</div></div></div></div>

{{-- Artefak Penyelesaian --}}
<div class="row"><div class="col-md-9 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <h6 class="card-title">Artefak Penyelesaian</h6>
    @if($canManage)
    @can('archive.artifacts')
    <form method="POST" action="{{ route('archive.artifacts', $title->id) }}" enctype="multipart/form-data">
        @csrf @method('PUT')
        @foreach($artifacts as $a)
            <div class="border rounded p-2 mb-2">
                <div class="fw-semibold small mb-1">{{ $a['label'] }}</div>
                <div class="row g-2">
                    <div class="col-md-5">
                        @if($a['type'] === 'file')
                            @if($a['value'])<a href="{{ $a['value'] }}" target="_blank" rel="noopener" class="d-block text-truncate small">📎 {{ $a['file_name'] ?: 'file' }}</a>@endif
                            <input type="file" name="fixed[{{ $a['key'] }}][file]" class="form-control form-control-sm">
                        @else
                            <input type="text" name="fixed[{{ $a['key'] }}][value]" value="{{ $a['value'] }}" class="form-control form-control-sm" placeholder="{{ $a['type'] === 'link' ? 'https://…' : 'Nilai' }}">
                        @endif
                    </div>
                    <div class="col-md-4">
                        <select name="fixed[{{ $a['key'] }}][pic_user_id]" class="form-select form-select-sm">
                            <option value="">— PIC —</option>
                            @foreach($staff as $u)<option value="{{ $u->id }}" {{ (int) $a['pic_user_id'] === $u->id ? 'selected' : '' }}>{{ $u->name }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-md-3"><input type="text" name="fixed[{{ $a['key'] }}][note]" value="{{ $a['note'] }}" class="form-control form-control-sm" placeholder="Catatan"></div>
                </div>
            </div>
        @endforeach

        <div class="mt-2"><span class="fw-semibold small">Lainnya</span></div>
        <div id="customList">
            @foreach($customArtifacts as $c)
                <div class="row g-2 mb-1" data-custom-row>
                    <div class="col-md-3"><input name="custom[][label]" value="{{ $c->label }}" class="form-control form-control-sm" placeholder="Label"></div>
                    <div class="col-md-2"><select name="custom[][type]" class="form-select form-select-sm"><option value="link" {{ $c->type === 'link' ? 'selected' : '' }}>Link</option><option value="text" {{ $c->type === 'text' ? 'selected' : '' }}>Teks</option></select></div>
                    <div class="col-md-3"><input name="custom[][value]" value="{{ $c->value }}" class="form-control form-control-sm" placeholder="Nilai"></div>
                    <div class="col-md-3"><select name="custom[][pic_user_id]" class="form-select form-select-sm"><option value="">— PIC —</option>@foreach($staff as $u)<option value="{{ $u->id }}" {{ $c->pic_user_id === $u->id ? 'selected' : '' }}>{{ $u->name }}</option>@endforeach</select></div>
                    <div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger" data-remove-custom>×</button></div>
                </div>
            @endforeach
        </div>
        <template id="customTpl">
            <div class="row g-2 mb-1" data-custom-row>
                <div class="col-md-3"><input name="custom[][label]" class="form-control form-control-sm" placeholder="Label"></div>
                <div class="col-md-2"><select name="custom[][type]" class="form-select form-select-sm"><option value="link">Link</option><option value="text">Teks</option></select></div>
                <div class="col-md-3"><input name="custom[][value]" class="form-control form-control-sm" placeholder="Nilai"></div>
                <div class="col-md-3"><select name="custom[][pic_user_id]" class="form-select form-select-sm"><option value="">— PIC —</option>@foreach($staff as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select></div>
                <div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger" data-remove-custom>×</button></div>
            </div>
        </template>
        <button type="button" class="btn btn-xs btn-outline-secondary" id="addCustom">+ Lainnya</button>
        <div class="mt-2"><button type="submit" class="btn btn-sm btn-primary">Simpan Artefak</button></div>
    </form>
    @else
        <dl class="row mb-0">
            @foreach($artifacts as $a)
                <dt class="col-sm-4 small text-muted">{{ $a['label'] }}</dt>
                <dd class="col-sm-8">@if($a['type'] === 'file' && $a['value'])<a href="{{ $a['value'] }}" target="_blank" rel="noopener">📎 {{ $a['file_name'] ?: 'file' }}</a>@elseif($a['value'])@if($a['type'] === 'link')<a href="{{ $a['value'] }}" target="_blank" rel="noopener">{{ $a['value'] }}</a>@else{{ $a['value'] }}@endif @else — @endif</dd>
            @endforeach
        </dl>
    @endcan
    @else
        <dl class="row mb-0">
            @foreach($artifacts as $a)
                <dt class="col-sm-4 small text-muted">{{ $a['label'] }}</dt>
                <dd class="col-sm-8">@if($a['type'] === 'file' && $a['value'])<a href="{{ $a['value'] }}" target="_blank" rel="noopener">📎 {{ $a['file_name'] ?: 'file' }}</a>@elseif($a['value'])@if($a['type'] === 'link')<a href="{{ $a['value'] }}" target="_blank" rel="noopener">{{ $a['value'] }}</a>@else{{ $a['value'] }}@endif @else — @endif</dd>
            @endforeach
        </dl>
    @endif
</div></div></div></div>

{{-- Persetujuan --}}
@if($canApprove && $st === 'diajukan')
<div class="row"><div class="col-md-9 col-12 grid-margin stretch-card"><div class="card border-primary"><div class="card-body">
    <h6 class="card-title">Persetujuan Arsip</h6>
    @can('archive.approve')
    <form method="POST" action="{{ route('archive.approve', $title->id) }}" class="mb-2">@csrf
        <textarea name="approval_note" class="form-control form-control-sm mb-2" rows="2" placeholder="Informasi/bukti selesai (opsional)"></textarea>
        <button class="btn btn-sm btn-success">Approve — Masuk Arsip</button>
    </form>
    <form method="POST" action="{{ route('archive.reject', $title->id) }}">@csrf
        <textarea name="reject_note" class="form-control form-control-sm mb-2" rows="2" placeholder="Alasan penolakan" required></textarea>
        <button class="btn btn-sm btn-outline-danger">Tolak</button>
    </form>
    @endcan
</div></div></div></div>
@elseif($st === 'disetujui')
<div class="row"><div class="col-md-9 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <h6 class="card-title">Disetujui</h6>
    <p class="mb-0 small text-muted">Oleh {{ optional($title->archive->approver)->name ?? '—' }} · {{ optional($title->archive->approved_at)->format('d M Y H:i') }}
        @if($title->archive->approval_note)<br>Catatan: {{ $title->archive->approval_note }}@endif
    </p>
</div></div></div></div>
@endif
@endsection

@push('custom-scripts')
<script>
$(function () {
    var list = document.getElementById('customList');
    var tpl = document.getElementById('customTpl');
    var add = document.getElementById('addCustom');
    if (add && tpl && list) add.addEventListener('click', function () {
        list.appendChild(tpl.content.cloneNode(true));
    });
    if (list) list.addEventListener('click', function (e) {
        var b = e.target.closest('[data-remove-custom]');
        if (b) b.closest('[data-custom-row]').remove();
    });
});
</script>
@endpush
