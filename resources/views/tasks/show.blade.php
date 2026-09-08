@extends('layouts.master')
@section('title', 'Detail Tugas - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/plugins/dropzone/dropzone.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
@php
    $prioBadge = ['high' => 'bg-danger', 'normal' => 'bg-secondary', 'low' => 'bg-info'];
    $prioLabel = ['high' => 'Tinggi', 'normal' => 'Normal', 'low' => 'Rendah'];
    $statBadge = ['todo' => 'bg-secondary', 'in_progress' => 'bg-warning text-dark', 'done' => 'bg-success'];

    $lewat = $task->due_date && $task->status !== 'done' && $task->due_date->isPast();
@endphp

<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-1">{{ $task->title }}</h5>
        <small class="text-muted">
            Untuk <strong>{{ $task->user?->name ?? '—' }}</strong>
            @if($task->creator) · diberikan oleh {{ $task->creator->name }} @endif
            · dibuat {{ $task->created_at?->translatedFormat('j M Y') }}
        </small>
    </div>
    <a href="{{ route('task.board') }}" class="btn btn-sm btn-outline-secondary">← Papan Tugas</a>
</div>

<div class="row g-3">
    {{-- ── keadaan sekarang ─────────────────────────────────────────────── --}}
    <div class="col-lg-4">
        <div class="card mb-3"><div class="card-body">
            <h6 class="mb-3">Keadaan</h6>

            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-muted small">Status</span>
                <span class="badge {{ $statBadge[$task->status] ?? 'bg-secondary' }}">{{ \App\Models\Task::labelStatus($task->status) }}</span>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-muted small">Prioritas</span>
                <span class="badge {{ $prioBadge[$task->priority] ?? 'bg-secondary' }}">{{ $prioLabel[$task->priority] ?? $task->priority }}</span>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="text-muted small">Tenggat</span>
                <span class="{{ $lewat ? 'text-danger fw-bold' : '' }}">
                    {{ $task->due_date?->translatedFormat('j M Y') ?? '—' }}
                    @if($lewat) · lewat @endif
                </span>
            </div>

            {{-- Kemajuan yang DILAPORKAN, bukan yang ditebak dari status. `in_progress`
                 bisa berarti 10% atau 90%, dan hanya pelaksananya yang tahu yang mana. --}}
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-muted small">Kemajuan</span>
                <span class="small fw-bold">{{ $task->progress }}%</span>
            </div>
            <div class="progress" style="height:8px">
                <div class="progress-bar {{ $task->progress >= 100 ? 'bg-success' : '' }}"
                     role="progressbar" style="width:{{ $task->progress }}%"
                     aria-valuenow="{{ $task->progress }}" aria-valuemin="0" aria-valuemax="100"></div>
            </div>

            @if($task->description)
                <hr>
                <div class="text-muted small mb-1">Deskripsi</div>
                <div style="white-space:pre-line">{{ $task->description }}</div>
            @endif

            {{-- Kunci laporan harian menjaga SYARAT tugas, bukan percakapannya. Yang
                 tampil di Laporan Harian cuma judul dan prioritas; utas dan lampiran tak
                 pernah muncul di sana, jadi membungkamnya tak melindungi apa pun. --}}
            @if($terkunci)
                <hr>
                <div class="alert alert-light border small mb-0 py-2">
                    <i data-feather="lock" class="icon-xs me-1"></i>
                    Syarat tugas ini terkunci laporan harian yang sudah dikirim — judul,
                    prioritas, tenggat, dan statusnya tak bisa diubah lagi.
                    Melapor dan melampirkan berkas tetap bisa.
                </div>
            @endif
        </div></div>

        <div class="card mb-3"><div class="card-body">
            <h6 class="mb-2">Laporan</h6>
            <div class="text-muted small">
                {{ $ringkasan['jumlah'] }} laporan tercatat.
                @if($ringkasan['terakhir'])
                    Terakhir oleh <strong>{{ $ringkasan['terakhir']->pelaku() }}</strong>,
                    {{ $ringkasan['terakhir']->created_at?->diffForHumans() }}.
                @else
                    Belum ada yang melapor.
                @endif
            </div>
        </div></div>

        {{-- ── lampiran ─────────────────────────────────────────────────────
             Berkas milik TUGASNYA, bukan milik hari tempat ia kebetulan selesai.
             Kedua pihak boleh melampirkan; hanya pengunggahnya yang boleh mencabut. --}}
        <div class="card"><div class="card-body">
            <h6 class="mb-2">
                Berkas
                @if($task->files->count())
                    <span class="badge bg-light text-muted">{{ $task->files->count() }}</span>
                @endif
            </h6>

            @unless($terkunci)
                <p class="text-muted mb-2" style="font-size:12px">
                    Opsional — tak semua tugas berbentuk berkas. Pemberi dan penerima tugas
                    sama-sama boleh melampirkan.
                </p>
                <div id="taskDropzone" class="dropzone mb-2" style="min-height:100px"></div>
            @endunless

            <ul id="taskFiles" class="list-group list-group-flush">
                @forelse($task->files as $f)
                    <li class="list-group-item d-flex justify-content-between align-items-start px-0" data-file="{{ $f->id }}">
                        <a href="{{ $f->url }}" target="_blank" class="text-body" style="font-size:13px">
                            <i data-feather="paperclip" class="icon-xs me-1"></i>{{ $f->name }}
                            <span class="text-muted d-block" style="font-size:11px">
                                {{ $f->pelaku() }} · {{ $f->created_at?->translatedFormat('j M Y, H:i') }}
                            </span>
                        </a>
                        @if(! $terkunci && $f->bolehDihapus(auth()->user()))
                            <button class="btn btn-xs btn-outline-danger" data-del-berkas="{{ $f->id }}">Hapus</button>
                        @endif
                    </li>
                @empty
                    {{-- Kalimat ini yang mencegah orang mengira dirinya lupa sesuatu. --}}
                    <li class="list-group-item text-muted px-0" style="font-size:12px">
                        Belum ada berkas dilampirkan.
                    </li>
                @endforelse
            </ul>
        </div></div>
    </div>

    {{-- ── utas aktivitas ───────────────────────────────────────────────── --}}
    <div class="col-lg-8">
        <div class="card"><div class="card-body">
            <h6 class="mb-3">Aktivitas</h6>

            @if($task->updates->isEmpty())
                <p class="text-muted small mb-0">Belum ada aktivitas.</p>
            @else
                <ul class="list-unstyled mb-0 utas">
                    @foreach($task->updates as $entri)
                        <li class="utas-item {{ $entri->isSistem() ? 'utas-sistem' : '' }}">
                            <span class="utas-titik"></span>
                            <div class="utas-isi">
                                <div class="d-flex justify-content-between align-items-baseline gap-2 flex-wrap">
                                    <strong class="small">{{ $entri->pelaku() }}</strong>
                                    <span class="text-muted" style="font-size:.75rem">
                                        {{ $entri->created_at?->translatedFormat('j M Y, H:i') }}
                                    </span>
                                </div>
                                <div class="{{ $entri->isSistem() ? 'text-muted small' : '' }}" style="white-space:pre-line">{{ $entri->body }}</div>
                                @if($entri->progress !== null)
                                    <span class="badge bg-light text-dark border mt-1">Kemajuan {{ $entri->progress }}%</span>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            <hr>

            {{-- Selalu terbuka, termasuk pada tugas terkunci: laporan tak menyentuh satu
                 pun kolom yang dibaca Laporan Harian, dan pekerjaan yang belum benar-benar
                 selesai tak berhenti hanya karena harinya sudah ditutup. --}}
            <form method="POST" action="{{ route('task.report', $task->id) }}">
                @csrf
                <label for="taskBody" class="form-label small fw-bold">Tulis laporan</label>
                <textarea name="body" id="taskBody" rows="3" maxlength="4000"
                          class="form-control @error('body') is-invalid @enderror"
                          placeholder="Apa yang sudah dikerjakan, apa yang menghambat?">{{ old('body') }}</textarea>
                @error('body')<div class="invalid-feedback">{{ $message }}</div>@enderror

                <div class="d-flex align-items-end gap-2 mt-2 flex-wrap">
                    <div style="max-width:170px">
                        <label for="taskProgress" class="form-label small mb-1 text-muted">Kemajuan (opsional)</label>
                        <div class="input-group input-group-sm">
                            <input type="number" name="progress" id="taskProgress" min="0" max="100"
                                   class="form-control @error('progress') is-invalid @enderror"
                                   value="{{ old('progress') }}" placeholder="{{ $task->progress }}">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Kirim laporan</button>
                </div>
                @error('progress')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            </form>
        </div></div>
    </div>
</div>
@endsection

@push('style')
<style>
    /* Garis waktu satu kolom: titik + garis penghubung, entri sistem lebih redup
       supaya laporan manusia yang menonjol saat utasnya panjang. */
    .utas { position: relative; padding-left: 1.15rem; }
    .utas::before {
        content: ""; position: absolute; left: 4px; top: .4rem; bottom: .4rem;
        width: 1px; background: rgba(148, 163, 184, .35);
    }
    .utas-item { position: relative; padding-bottom: 1rem; }
    .utas-item:last-child { padding-bottom: 0; }
    .utas-titik {
        position: absolute; left: -1.15rem; top: .35rem;
        width: 9px; height: 9px; border-radius: 50%;
        background: var(--bs-primary, #0d6efd);
        box-shadow: 0 0 0 3px rgba(13, 110, 253, .12);
    }
    .utas-sistem .utas-titik { background: rgba(148, 163, 184, .9); box-shadow: none; }
</style>
@endpush

@push('plugin-scripts')
<script src="{{ asset('assets/plugins/dropzone/dropzone.min.js') }}"></script>
<script>if (window.Dropzone) Dropzone.autoDiscover = false;</script>
@endpush

@push('custom-scripts')
<script>
(function () {
    var token = document.querySelector('meta[name="_token"]').getAttribute('content');
    var daftar = document.getElementById('taskFiles');

    // Baris berkas dibangun lewat DOM, bukan innerHTML: nama berkas datang dari
    // pengguna, dan menempelkannya sebagai HTML berarti mempercayai nama itu.
    function tambahBaris(f) {
        var kosong = daftar.querySelector('li:not([data-file])');
        if (kosong) kosong.remove();

        var li = document.createElement('li');
        li.className = 'list-group-item d-flex justify-content-between align-items-start px-0';
        li.setAttribute('data-file', f.id);

        var a = document.createElement('a');
        a.href = f.url; a.target = '_blank'; a.className = 'text-body'; a.style.fontSize = '13px';
        var ikon = document.createElement('i');
        ikon.setAttribute('data-feather', 'paperclip');
        ikon.className = 'icon-xs me-1';
        a.appendChild(ikon);
        a.appendChild(document.createTextNode(f.name));
        var oleh = document.createElement('span');
        oleh.className = 'text-muted d-block';
        oleh.style.fontSize = '11px';
        oleh.textContent = f.oleh + ' · baru saja';
        a.appendChild(oleh);

        var tombol = document.createElement('button');
        tombol.className = 'btn btn-xs btn-outline-danger';
        tombol.setAttribute('data-del-berkas', f.id);
        tombol.textContent = 'Hapus';

        li.appendChild(a);
        li.appendChild(tombol);
        daftar.prepend(li);
        if (window.feather) feather.replace();
    }

    if (daftar) daftar.addEventListener('click', function (e) {
        var tombol = e.target.closest('[data-del-berkas]');
        if (! tombol) return;
        e.preventDefault();
        var baris = tombol.closest('[data-file]');
        fetch("{{ url('tasks/berkas') }}/" + tombol.getAttribute('data-del-berkas'), {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { if (r.ok && baris) baris.remove(); });
    });

    var dz = document.getElementById('taskDropzone');
    if (dz && window.Dropzone) {
        new Dropzone(dz, {
            url: "{{ route('task.files.store', $task->id) }}",
            maxFiles: 10, maxFilesize: 10,
            acceptedFiles: "image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip",
            addRemoveLinks: true,
            resizeWidth: 1600, resizeQuality: 0.8,
            headers: { 'X-CSRF-TOKEN': token },
            dictDefaultMessage: 'Tarik &amp; lepas berkas ke sini atau klik untuk pilih',
            dictRemoveFile: 'Batal',
            init: function () {
                this.on('success', function (file, resp) { tambahBaris(resp); this.removeFile(file); });
                this.on('error', function (file, msg) {
                    var m = (msg && msg.message) ? msg.message : 'Gagal mengunggah.';
                    var el = file.previewElement && file.previewElement.querySelector('[data-dz-errormessage]');
                    if (el) el.textContent = m;
                });
            }
        });
    }
})();
</script>
@endpush
