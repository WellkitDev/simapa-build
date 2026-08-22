{{--
    Kartu Aksi — hanya dirender untuk yang berwenang. Marketing tidak pernah melihat
    blok ini (halaman tetap terbuka penuh, hanya-baca). Flag izin datang dari controller;
    view tidak memeriksa role sendiri.

    Tombol maju TUNGGAL: targetnya selalu tahap berikutnya. Mundur/lompat lewat jalur
    Koreksi yang terpisah dan wajib catatan.
--}}
@php
    $adaAksi = $izin['advance'] || $izin['assign'] || $izin['priority'] || $izin['target']
               || $izin['hold'] || $izin['cancel'] || $izin['correct'] || $izin['claim'];
@endphp

@if (! $adaAksi)
    <div class="card mb-3"><div class="card-body">
        <p class="text-muted small mb-0">
            Halaman ini hanya-baca untukmu. Kamu akan menerima notifikasi saat naskah terbit atau publish.
        </p>
    </div></div>
@else
<div class="card mb-3"><div class="card-body">
    <h6 class="text-uppercase text-muted small fw-bold mb-3">Aksi</h6>

    @if ($progress->cancelled_at)
        <p class="text-muted small mb-0">Naskah sudah dibatalkan — tidak ada aksi yang tersedia.</p>
    @else

    @if ($izin['advance'] && $next)
        <form method="POST" action="{{ route('naskah.selesaikan', $progress->order_detail_id) }}">
            @csrf

            {{-- Data jurnal direbut di sini, bukan di modul terpisah: inilah layar tempat
                 orang produksi benar-benar bekerja. Sebelumnya tahap bergerak tanpa
                 meninggalkan jejak jurnal sama sekali — 15 artikel produksi sampai ke
                 tahap jurnal dengan nol catatan submission. --}}
            @php
                $mintaJurnal = \App\Services\JurnalSubmissionService::tahapMintaData($progress->status)
                    && ! in_array($progress->orderDetail?->type, ['bk_mandiri', 'bk_kolab'], true);
                $daftarJurnal = $mintaJurnal ? \App\Models\Journal::orderBy('nama')->get(['id', 'nama']) : collect();
            @endphp

            @if ($mintaJurnal && $progress->status === 'submit')
                <div class="border rounded p-2 mb-2 bg-light">
                    <label class="form-label small fw-bold mb-1">Jurnal tujuan <span class="text-danger">*</span></label>
                    @if ($daftarJurnal->isNotEmpty())
                        <select name="journal_id" class="form-select form-select-sm mb-2">
                            <option value="">— pilih dari Direktori Jurnal —</option>
                            @foreach ($daftarJurnal as $j)
                                <option value="{{ $j->id }}" @selected(old('journal_id') == $j->id)>{{ $j->nama }}</option>
                            @endforeach
                        </select>
                        <div class="small text-muted mb-1">atau ketik nama jurnal baru:</div>
                    @endif
                    <input type="text" name="nama_jurnal" class="form-control form-control-sm mb-2"
                           value="{{ old('nama_jurnal') }}" placeholder="Nama jurnal (otomatis masuk Direktori Jurnal)">
                    <div class="row g-2">
                        <div class="col-6">
                            <input type="date" name="tgl_submit" class="form-control form-control-sm"
                                   value="{{ old('tgl_submit', now()->toDateString()) }}">
                        </div>
                        <div class="col-6">
                            <input type="text" name="ojs_akun" class="form-control form-control-sm"
                                   value="{{ old('ojs_akun') }}" placeholder="Akun OJS (opsional)">
                        </div>
                    </div>
                </div>
            @endif

            @if ($mintaJurnal && $progress->status === 'loa')
                {{-- Wajib hanya bila linknya memang belum ada di mana pun. Title::linkTerbit()
                     punya tiga sumber; menandai `required` tanpa syarat membuat peramban
                     memblokir naskah yang linknya sudah tercatat di Direktori Judul. --}}
                @php $wajibLink = \App\Services\JurnalSubmissionService::butuhLink($progress); @endphp
                <div class="border rounded p-2 mb-2 bg-light">
                    <label class="form-label small fw-bold mb-1">
                        Link artikel terbit @if ($wajibLink)<span class="text-danger">*</span>@endif
                    </label>
                    <input type="url" name="link_publish" class="form-control form-control-sm mb-2"
                           value="{{ old('link_publish') }}" placeholder="https://jurnal.../artikel/123"
                           @if ($wajibLink) required @endif>
                    <input type="date" name="tgl_terbit" class="form-control form-control-sm"
                           value="{{ old('tgl_terbit', now()->toDateString()) }}">
                    <div class="form-text">
                        @if ($wajibLink)
                            Wajib: naskah tak boleh ditandai Publish tanpa alamat terbitnya.
                        @else
                            Link terbit sudah tercatat. Isi hanya bila ingin menggantinya.
                        @endif
                    </div>
                </div>
            @endif

            <button class="btn btn-primary w-100 py-2 mb-2">
                ✓ Selesaikan {{ $progress->stageLabelId() }} → lanjut ke {{ \App\Models\TitleProgress::labelFor($next) }}
            </button>
            <textarea name="note" rows="2" class="form-control form-control-sm"
                      placeholder="Catatan (opsional saat maju normal)"></textarea>
        </form>
    @elseif ($izin['advance'])
        <p class="text-muted small">Naskah sudah berada di tahap akhir.</p>
    @endif

    <div class="d-flex flex-wrap gap-2 mt-3">
        {{-- Targetnya diturunkan dari MUNDUR_SAH, bukan ditulis di sini: tombol lama
             hanya memeriksa `status === 'editing'` tanpa melihat jenis naskah, dan pada
             BUKU ia memajukan naskah ke Layout — karena buku tak punya tahap revisi. --}}
        @php $targetMundur = \App\Services\TitleProgressService::MUNDUR_SAH[$progress->status] ?? null; @endphp
        @if ($izin['advance'] && $targetMundur)
            <button class="btn btn-outline-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#formKembalikan">
                ↩ Kembalikan ke {{ \App\Models\TitleProgress::labelFor($targetMundur) }}
            </button>
        @endif
        @if ($izin['correct'])
            <button class="btn btn-outline-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#formKoreksi">
                ⇄ Koreksi tahap (wajib catatan)
            </button>
        @endif
        @if ($izin['claim'] && $progress->pelaksana_user_id === null)
            <form method="POST" action="{{ route('naskah.claim', $progress->order_detail_id) }}">
                @csrf
                <button class="btn btn-outline-primary btn-sm">✋ Ambil Tugas Ini</button>
            </form>
        @endif
    </div>

    @if ($izin['advance'] && $targetMundur)
        <div class="collapse mt-3" id="formKembalikan">
            <form method="POST" action="{{ route('naskah.kembalikan', $progress->order_detail_id) }}"
                  enctype="multipart/form-data" class="border rounded p-3">
                @csrf
                <label class="form-label small fw-bold">
                    Alasan mengembalikan ke {{ \App\Models\TitleProgress::labelFor($targetMundur) }}
                </label>
                <textarea name="alasan" rows="2" required class="form-control form-control-sm mb-2"
                          placeholder="Apa yang perlu diperbaiki? (wajib — dibaca pelaksana)"></textarea>
                <input type="file" name="berkas[]" multiple class="form-control form-control-sm mb-2"
                       accept=".pdf,.doc,.docx,.zip">
                <div class="form-text mb-2">
                    Berkas dan catatan ini ditujukan ke
                    <strong>{{ $progress->pelaksana?->name ?? 'pelaksana naskah' }}</strong>.
                </div>
                <button class="btn btn-sm btn-primary">Kembalikan naskah</button>
            </form>
        </div>
    @endif

    @if ($izin['correct'])
        <div class="collapse mt-3" id="formKoreksi">
            <form method="POST" action="{{ route('naskah.koreksi', $progress->order_detail_id) }}" class="border rounded p-3">
                @csrf
                <label class="form-label small fw-bold">Koreksi ke tahap</label>
                <select name="status" class="form-select form-select-sm mb-2">
                    @foreach ($stages as $s)
                        <option value="{{ $s }}" @selected($s === $progress->status)>
                            {{ \App\Models\TitleProgress::labelFor($s) }}
                        </option>
                    @endforeach
                </select>
                <textarea name="note" rows="2" class="form-control form-control-sm mb-2"
                          placeholder="Catatan wajib — alasan koreksi" required></textarea>
                <button class="btn btn-sm btn-primary">Simpan koreksi</button>
            </form>
        </div>
    @endif

    <hr class="my-3">

    <div class="row g-2">
        @if ($izin['assign'])
            <div class="col-md-6">
                <form method="POST" action="{{ route('naskah.distribusi', $progress->order_detail_id) }}">
                    @csrf
                    <label class="form-label small fw-bold mb-1">Pelaksana</label>
                    <div class="d-flex gap-1">
                        <select name="pelaksana_user_id" class="form-select form-select-sm" required>
                            <option value="">— pilih akun Produksi —</option>
                            @foreach ($pelaksanaOptions as $u)
                                <option value="{{ $u->id }}" @selected($progress->pelaksana_user_id == $u->id)>{{ $u->name }}</option>
                            @endforeach
                        </select>
                        <button class="btn btn-sm btn-outline-primary">Simpan</button>
                    </div>
                </form>
                @if ($progress->pelaksana_user_id)
                    <form method="POST" action="{{ route('naskah.tarik', $progress->order_detail_id) }}" class="mt-1">
                        @csrf
                        <button class="btn btn-link btn-sm p-0 text-danger">Tarik tugas dari pelaksana</button>
                    </form>
                @endif
            </div>
            <div class="col-md-6">
                <form method="POST" action="{{ route('naskah.operPj', $progress->order_detail_id) }}">
                    @csrf
                    <label class="form-label small fw-bold mb-1">Penanggung jawab</label>
                    <div class="d-flex gap-1">
                        <select name="pj_user_id" class="form-select form-select-sm" required>
                            <option value="">— pilih akun Admin —</option>
                            @foreach ($adminOptions as $u)
                                <option value="{{ $u->id }}" @selected($progress->pj_user_id == $u->id)>{{ $u->name }}</option>
                            @endforeach
                        </select>
                        <button class="btn btn-sm btn-outline-primary">Oper</button>
                    </div>
                </form>
            </div>
        @endif

        @if ($izin['priority'])
            <div class="col-md-6">
                <form method="POST" action="{{ route('naskah.prioritas', $progress->order_detail_id) }}">
                    @csrf
                    <label class="form-label small fw-bold mb-1">Prioritas</label>
                    <div class="d-flex gap-1">
                        <select name="priority" class="form-select form-select-sm">
                            @foreach (['high' => 'High', 'normal' => 'Normal', 'low' => 'Low'] as $k => $v)
                                <option value="{{ $k }}" @selected(($progress->priority ?? 'normal') === $k)>{{ $v }}</option>
                            @endforeach
                        </select>
                        <button class="btn btn-sm btn-outline-primary">Simpan</button>
                    </div>
                </form>
            </div>
        @endif

        @if ($izin['target'])
            <div class="col-md-6">
                <form method="POST" action="{{ route('naskah.target', $progress->order_detail_id) }}">
                    @csrf
                    <label class="form-label small fw-bold mb-1">Target {{ $buku ? 'terbit' : 'publish' }}</label>
                    <div class="d-flex gap-1">
                        <input type="date" name="target_date" class="form-control form-control-sm"
                               value="{{ $progress->target_date?->format('Y-m-d') }}">
                        <button class="btn btn-sm btn-outline-primary">Simpan</button>
                    </div>
                </form>
            </div>
        @endif
    </div>

    @if ($izin['hold'] || $izin['cancel'])
        <hr class="my-3">
        <div class="d-flex flex-wrap gap-2">
            @if ($izin['hold'])
                <form method="POST" action="{{ route('naskah.hold', $progress->order_detail_id) }}" class="d-flex gap-1">
                    @csrf
                    <input type="text" name="alasan" class="form-control form-control-sm"
                           placeholder="Alasan (opsional)" style="max-width:220px">
                    <button class="btn btn-sm btn-outline-warning text-nowrap">
                        {{ $progress->is_on_hold ? '▶ Lanjutkan' : '⏸ Tahan sementara' }}
                    </button>
                </form>
            @endif
            @if ($izin['cancel'])
                <form method="POST" action="{{ route('naskah.batal', $progress->order_detail_id) }}" class="d-flex gap-1"
                      data-confirm="Batalkan naskah ini? Aksi berlaku untuk semua order sejudul.">
                    @csrf
                    <input type="text" name="cancel_reason" class="form-control form-control-sm"
                           placeholder="Alasan pembatalan (wajib)" style="max-width:220px" required>
                    <button class="btn btn-sm btn-outline-danger text-nowrap">✕ Batalkan naskah</button>
                </form>
            @endif
        </div>
    @endif

    <p class="text-muted small mt-3 mb-0">
        Tanpa approval — perpindahan langsung jalan. Notifikasi otomatis terkirim ke PJ,
        superadmin, dan (saat publish/terbit) ke marketing.
    </p>

    @endif
</div></div>
@endif
