{{--
    Tabel bab buku kolaborasi (Layar 3B). Kolom Author menjawab pertanyaan nyata tim:
    "bab ini naskah dari siapa?". Bab tanpa author ditandai kuning dan TIDAK
    disembunyikan — tombol distribusinya dimatikan sampai author dipetakan.
--}}
<div class="card mt-3"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="text-uppercase text-muted small fw-bold mb-0">
            Progres per Bab — ✓ Selesai {{ $ringkasan['counts']['selesai'] }}
            · Editing {{ $ringkasan['counts']['editing'] }}
            · Pembuatan {{ $ringkasan['counts']['pembuatan'] }}
            · Menunggu {{ $ringkasan['counts']['menunggu'] }}
        </h6>
        <div class="d-flex gap-2">
            @if ($izin['assign'])
                <button class="btn btn-outline-primary btn-sm" data-bs-toggle="collapse"
                        data-bs-target="#formPelaksanaSemua">
                    Terapkan 1 pelaksana ke semua bab ▾
                </button>
            @endif
            @if ($izin['struktur'])
                <button class="btn btn-outline-primary btn-sm" data-bs-toggle="collapse"
                        data-bs-target="#formStrukturBab">
                    + Tambah / ubah struktur bab
                </button>
            @endif
        </div>
    </div>

    @if ($izin['assign'])
        <div class="collapse mt-3" id="formPelaksanaSemua">
            <form method="POST" action="{{ route('naskah.bab.pelaksanaSemua', $progress->order_detail_id) }}"
                  class="border rounded p-3 d-flex gap-2 align-items-end flex-wrap">
                @csrf
                <div>
                    <label class="form-label small fw-bold mb-1">Pelaksana untuk semua bab</label>
                    <select name="pelaksana_user_id" class="form-select form-select-sm" required style="min-width:220px">
                        <option value="">— pilih akun Produksi —</option>
                        @foreach ($pelaksanaOptions as $u)
                            <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="btn btn-sm btn-primary">Terapkan</button>
                <div class="form-text w-100 mt-1">
                    Bab yang author-nya belum dipetakan akan dilewati — jumlahnya dilaporkan setelah disimpan.
                </div>
            </form>
        </div>
    @endif

    @if ($izin['struktur'])
        <div class="collapse mt-3" id="formStrukturBab">
            <form method="POST" action="{{ route('naskah.bab.struktur', $progress->order_detail_id) }}"
                  class="border rounded p-3">
                @csrf
                <label class="form-label small fw-bold">Ubah judul bab</label>
                @foreach ($bab as $b)
                    @php
                        $bisaHapus = $b->manuscriptFiles->isEmpty()
                            && $b->progress?->pelaksana_user_id === null
                            && ($b->progress?->status ?? 'menunggu') === 'menunggu';
                    @endphp
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="text-muted small" style="width:28px">{{ $b->urutan }}</span>
                        <input type="text" name="judul[{{ $b->id }}]" value="{{ $b->judul }}"
                               class="form-control form-control-sm">
                        @if ($bisaHapus)
                            <div class="form-check text-nowrap">
                                <input class="form-check-input" type="checkbox" name="hapus[]"
                                       value="{{ $b->id }}" id="hapusBab{{ $b->id }}">
                                <label class="form-check-label small" for="hapusBab{{ $b->id }}">hapus</label>
                            </div>
                        @else
                            <span class="text-muted small text-nowrap" style="width:70px">sudah jalan</span>
                        @endif
                    </div>
                @endforeach

                <div class="d-flex align-items-end gap-2 mt-3">
                    <div>
                        <label class="form-label small fw-bold mb-1">Tambah bab</label>
                        <input type="number" name="tambah" min="0" max="50" value="0"
                               class="form-control form-control-sm" style="width:110px">
                    </div>
                    <button class="btn btn-sm btn-primary">Simpan struktur</button>
                </div>
                <div class="form-text mt-2">
                    Bab yang sudah punya pelaksana, file, atau sudah melewati tahap Menunggu tidak bisa dihapus.
                </div>
            </form>
        </div>
    @endif

    <div class="progress my-3" style="height:8px">
        <div class="progress-bar" style="width:{{ $ringkasan['persen'] }}%"></div>
    </div>

    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr class="text-uppercase text-muted small">
                    <th style="width:40px">No</th>
                    <th>Judul Bab</th>
                    <th>Author (naskah dari siapa)</th>
                    <th>Pelaksana</th>
                    <th>Status</th>
                    <th>Lama</th>
                    <th style="width:230px">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($bab as $b)
                    @php
                        $cp        = $b->progress;
                        $adaAuthor = $b->authors->isNotEmpty();
                    @endphp
                    <tr class="{{ ! $adaAuthor ? 'table-warning' : '' }}">
                        <td class="text-muted fw-bold">{{ $b->urutan }}</td>
                        <td><strong>{{ $b->judul }}</strong></td>
                        <td>
                            @if ($adaAuthor)
                                {{ $b->authors->pluck('name')->join(', ') }}
                            @else
                                <span class="text-warning fw-bold">⚠ Author belum dipetakan</span>
                            @endif
                        </td>
                        <td>
                            @if ($cp?->pelaksana)
                                {{ $cp->pelaksana->name }}
                            @elseif ($naskahMandiri)
                                {{-- Order naskah mandiri: author mengirim naskahnya sendiri,
                                     jadi memang tidak akan pernah ada pelaksana di sini. --}}
                                <span class="badge bg-secondary-subtle text-secondary border">Naskah Mandiri</span>
                            @elseif ($cp?->status === 'selesai')
                                — <small class="text-muted">(naskah dari author)</small>
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            <span class="badge bg-light text-dark border">
                                {{ \App\Models\ChapterProgress::labelFor($cp?->status) }}
                            </span>
                        </td>
                        <td class="small {{ $cp?->isOverdue() ? 'text-danger fw-bold' : 'text-muted' }}">
                            @if ($cp && $cp->status === 'pembuatan' && $cp->sla_due_at)
                                hari ke-{{ $cp->daysInStage() }}/7
                            @elseif ($cp && $cp->status !== 'menunggu')
                                {{ $cp->daysInStage() }} hari
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            @if (! $cp)
                                <span class="text-muted small">—</span>
                            @elseif (! $adaAuthor)
                                @if ($izin['author'])
                                    <form method="POST" action="{{ route('naskah.bab.author', $cp->id) }}" class="d-flex gap-1">
                                        @csrf
                                        <input type="text" name="author" class="form-control form-control-sm"
                                               placeholder="Nama author" required>
                                        <button class="btn btn-sm btn-outline-primary text-nowrap">Petakan Author</button>
                                    </form>
                                @else
                                    <span class="text-muted small">Menunggu pemetaan author</span>
                                @endif
                            @elseif ($cp->status === 'menunggu' && $izin['assign'])
                                <form method="POST" action="{{ route('naskah.bab.distribusi', $cp->id) }}" class="d-flex gap-1">
                                    @csrf
                                    <select name="pelaksana_user_id" class="form-select form-select-sm" required>
                                        <option value="">— pelaksana —</option>
                                        @foreach ($pelaksanaOptions as $u)
                                            <option value="{{ $u->id }}">{{ $u->name }}</option>
                                        @endforeach
                                    </select>
                                    <button class="btn btn-sm btn-outline-primary">Distribusikan</button>
                                </form>
                            @elseif ($cp->status === 'pembuatan' && (int) $cp->pelaksana_user_id === (int) auth()->id() && $izin['upload'])
                                {{-- Pelaksana bab mengunggah naskahnya di sini; unggahan
                                     itulah yang memajukan bab ke Editing secara otomatis. --}}
                                <form method="POST" action="{{ route('naskah.bab.file', $cp->id) }}"
                                      enctype="multipart/form-data" class="d-flex gap-1">
                                    @csrf
                                    <input type="hidden" name="slot" value="masuk">
                                    <input type="file" name="file" class="form-control form-control-sm"
                                           accept=".pdf,.doc,.docx,.zip" required>
                                    <button class="btn btn-sm btn-primary text-nowrap">⬆ Upload Naskah</button>
                                </form>
                            @elseif ($cp->status !== 'selesai' && $izin['advance'])
                                <form method="POST" action="{{ route('naskah.bab.selesaikan', $cp->id) }}">
                                    @csrf
                                    <button class="btn btn-sm btn-primary">✓ Selesaikan Bab</button>
                                </form>
                            @elseif ($izin['upload'])
                                <form method="POST" action="{{ route('naskah.bab.file', $cp->id) }}"
                                      enctype="multipart/form-data" class="d-flex gap-1">
                                    @csrf
                                    <input type="hidden" name="slot" value="final">
                                    <input type="file" name="file" class="form-control form-control-sm"
                                           accept=".pdf,.doc,.docx,.zip" required>
                                    <button class="btn btn-sm btn-outline-secondary text-nowrap">⬆ File bab</button>
                                </form>
                            @else
                                <span class="text-muted small">—</span>
                            @endif

                            @if ($b->manuscriptFiles->isNotEmpty())
                                <div class="small mt-1">
                                    @foreach ($b->manuscriptFiles as $f)
                                        <a href="{{ $f->drive_url }}" target="_blank" rel="noopener">
                                            {{ $f->slotLabel() }} v{{ $f->version }}
                                        </a>@if (! $loop->last) · @endif
                                    @endforeach
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <p class="text-muted small mb-0 mt-2">
        File per bab diunggah dari baris bab masing-masing. Tahap Layout → Terbit berjalan
        di level buku dan terbuka setelah semua bab Selesai.
    </p>
</div></div>
