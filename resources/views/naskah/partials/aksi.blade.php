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
        @if ($izin['advance'] && $progress->status === 'editing')
            <button class="btn btn-outline-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#formRevisi">
                ↩ Perlu Revisi (wajib pilih alasan)
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

    @if ($izin['advance'] && $progress->status === 'editing')
        <div class="collapse mt-3" id="formRevisi">
            <form method="POST" action="{{ route('naskah.revisi', $progress->order_detail_id) }}" class="border rounded p-3">
                @csrf
                <label class="form-label small fw-bold">Alasan revisi</label>
                <select name="alasan" class="form-select form-select-sm mb-2" required>
                    <option value="">— pilih alasan —</option>
                    <option value="Permintaan reviewer jurnal">Permintaan reviewer jurnal</option>
                    <option value="Permintaan klien">Permintaan klien</option>
                    <option value="Perbaikan kualitas internal">Perbaikan kualitas internal</option>
                    <option value="Kelengkapan data belum cukup">Kelengkapan data belum cukup</option>
                </select>
                <button class="btn btn-sm btn-primary">Tandai perlu revisi</button>
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
