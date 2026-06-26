@extends('layouts.master')
@section('title', 'Report Harian - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/plugins/flatpickr/flatpickr.min.css') }}" rel="stylesheet" />
<link href="{{ asset('assets/plugins/dropzone/dropzone.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@php
    $prioBadge = ['high' => 'bg-danger', 'normal' => 'bg-secondary', 'low' => 'bg-info'];
    $prioLabel = ['high' => 'Tinggi', 'normal' => 'Normal', 'low' => 'Rendah'];
    $cards = [
        'selesai'    => ['Selesai hari ini', 'check-circle', 'text-success'],
        'dibuat'     => ['Ditugaskan / dibuat', 'plus-circle', 'text-primary'],
        'dikerjakan' => ['Sedang dikerjakan', 'loader', 'text-warning'],
    ];
@endphp

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0">Report Harian</h5>
        <small class="text-muted">{{ $owner->name }}{{ $isOwner ? ' (saya)' : '' }} · {{ $date->translatedFormat('l, d M Y') }}</small>
    </div>
    <form method="GET" class="d-flex gap-1 align-items-center">
        @if(! $isOwner)<input type="hidden" name="user_id" value="{{ $owner->id }}">@endif
        <a href="{{ route('report.daily', array_filter(['user_id' => $isOwner ? null : $owner->id, 'date' => $date->copy()->subDay()->toDateString()])) }}" class="btn btn-sm btn-outline-secondary">&laquo;</a>
        <input type="text" name="date" id="reportDate" value="{{ $date->toDateString() }}" class="form-control form-control-sm" style="width:140px">
        <a href="{{ route('report.daily', array_filter(['user_id' => $isOwner ? null : $owner->id, 'date' => $date->copy()->addDay()->toDateString()])) }}" class="btn btn-sm btn-outline-secondary">&raquo;</a>
        <button class="btn btn-sm btn-primary">Lihat</button>
    </form>
</div>

<div class="row g-3 mb-3">
    @foreach($cards as $key => [$label, $icon, $color])
        <div class="col-md-4">
            <div class="card h-100"><div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <i data-feather="{{ $icon }}" class="icon-sm {{ $color }}"></i>
                    <strong>{{ $label }}</strong>
                    <span class="badge bg-light text-muted">{{ $recap['counts'][$key] }}</span>
                </div>
                @forelse($recap[$key] as $task)
                    <div class="d-flex justify-content-between align-items-center border-bottom py-1">
                        <span style="font-size:13px">{{ $task->title }}</span>
                        <span class="badge {{ $prioBadge[$task->priority] }}">{{ $prioLabel[$task->priority] }}</span>
                    </div>
                @empty
                    <div class="text-muted text-center py-2" style="font-size:12px">{{ $key === 'dikerjakan' && ! $date->isToday() ? 'Hanya untuk hari ini' : 'Tidak ada' }}</div>
                @endforelse
            </div></div>
        </div>
    @endforeach
</div>

<div class="row g-3">
    <div class="col-md-7">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Catatan Harian</h6>
            <form method="POST" action="{{ route('report.note') }}">
                @csrf
                <input type="hidden" name="date" value="{{ $date->toDateString() }}">
                <textarea name="note" class="form-control" rows="5" placeholder="Catatan tambahan (mis. meeting, kendala)..." {{ ($submitted || ! $isOwner) ? 'disabled' : '' }}>{{ $report?->note }}</textarea>
                @if($isOwner && ! $submitted)
                    <div class="mt-2 d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-outline-primary">Simpan Catatan</button>
                    </div>
                @endif
            </form>
            @if($isOwner)
                <hr>
                @if($submitted)
                    <span class="badge bg-success">Terkirim {{ optional($report->submitted_at)->translatedFormat('d M Y H:i') }}</span>
                @else
                    <form method="POST" action="{{ route('report.submit') }}" data-confirm="Kirim report hari ini? Setelah dikirim tidak bisa diubah.">
                        @csrf
                        <input type="hidden" name="date" value="{{ $date->toDateString() }}">
                        <button type="submit" class="btn btn-sm btn-success">Kirim Report Hari Ini</button>
                    </form>
                @endif
            @elseif($submitted)
                <hr><span class="badge bg-success">Sudah dikirim</span>
            @endif
        </div></div>
    </div>
    <div class="col-md-5">
        <div class="card"><div class="card-body">
            <h6 class="card-title">Bukti / Lampiran @if($files->count())<span class="badge bg-success">{{ $files->count() }} bukti terlampir</span>@endif</h6>
            @if($isOwner && ! $submitted)
                <p class="text-muted mb-2" style="font-size:12px">Lampirkan bukti pekerjaan (screenshot/file). <strong>Wajib minimal 1</strong> sebelum Kirim Report.</p>
                <div id="reportDropzone" class="dropzone mb-2" style="min-height:120px"></div>
            @endif
            <ul id="savedFiles" class="list-group list-group-flush">
                @forelse($files as $f)
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0" data-file="{{ $f->id }}">
                        <a href="{{ $f->url }}" target="_blank" style="font-size:13px"><i data-feather="paperclip" class="icon-xs me-1"></i>{{ $f->name }}</a>
                        @if($isOwner && ! $submitted)<button class="btn btn-xs btn-outline-danger" data-del-file="{{ $f->id }}">Hapus</button>@endif
                    </li>
                @empty
                    <li class="list-group-item text-muted text-center px-0" style="font-size:12px">Belum ada bukti dilampirkan.</li>
                @endforelse
            </ul>
            @if(! $isOwner)
                <small class="text-muted d-block mt-1">Daftar bukti yang dikirim karyawan (read-only).</small>
            @endif
        </div></div>
    </div>
</div>
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/plugins/flatpickr/flatpickr.min.js') }}"></script>
<script src="{{ asset('assets/plugins/dropzone/dropzone.min.js') }}"></script>
<script>if (window.Dropzone) Dropzone.autoDiscover = false;</script>
@endpush
@push('custom-scripts')
<script>
$(function () {
    var token = document.querySelector('meta[name="_token"]').getAttribute('content');
    if (window.flatpickr) flatpickr('#reportDate', { dateFormat: 'Y-m-d' });

    var saved = document.getElementById('savedFiles');
    function appendSaved(f) {
        var li = document.createElement('li');
        li.className = 'list-group-item d-flex justify-content-between align-items-center px-0';
        li.setAttribute('data-file', f.id);
        var a = document.createElement('a');
        a.href = f.url;
        a.target = '_blank';
        a.style.fontSize = '13px';
        var icon = document.createElement('i');
        icon.setAttribute('data-feather', 'paperclip');
        icon.className = 'icon-xs me-1';
        a.appendChild(icon);
        a.appendChild(document.createTextNode(f.name));
        var btn = document.createElement('button');
        btn.className = 'btn btn-xs btn-outline-danger';
        btn.setAttribute('data-del-file', f.id);
        btn.textContent = 'Hapus';
        li.appendChild(a);
        li.appendChild(btn);
        saved.prepend(li);
        if (window.feather) feather.replace();
    }
    function delFile(id, el) {
        fetch("{{ url('reports/daily/files') }}/" + id, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { if (r.ok && el) el.remove(); });
    }
    if (saved) saved.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-del-file]');
        if (btn) { e.preventDefault(); delFile(btn.getAttribute('data-del-file'), btn.closest('[data-file]')); }
    });

    var dzEl = document.getElementById('reportDropzone');
    if (dzEl && window.Dropzone) {
        new Dropzone(dzEl, {
            url: "{{ route('report.files.store') }}",
            maxFiles: 10, maxFilesize: 10,
            acceptedFiles: "image/*,.pdf,.doc,.docx,.xls,.xlsx",
            addRemoveLinks: true,
            resizeWidth: 1600, resizeQuality: 0.8,
            params: { date: "{{ $date->toDateString() }}" },
            headers: { 'X-CSRF-TOKEN': token },
            dictDefaultMessage: 'Tarik &amp; lepas file ke sini atau klik untuk pilih',
            dictRemoveFile: 'Batal',
            init: function () {
                this.on('success', function (file, resp) { appendSaved(resp); this.removeFile(file); });
                this.on('error', function (file, msg) {
                    var m = (msg && msg.message) ? msg.message : 'Gagal mengunggah.';
                    var el = file.previewElement && file.previewElement.querySelector('[data-dz-errormessage]');
                    if (el) el.textContent = m;
                });
            }
        });
    }
    if (window.feather) feather.replace();
});
</script>
@endpush
