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
                    {{-- Judul kolom dipadatkan supaya lebar berpindah ke Aksi, yang untuk
                         bab bernaskah mandiri harus memuat input berkas DAN tombol maju
                         sekaligus. "Judul Bab" dan "Author (naskah dari siapa)" memakan
                         ruang yang tak sebanding dengan isinya. --}}
                    <th style="width:40px">No</th>
                    <th>Bab</th>
                    <th>Author</th>
                    <th style="width:210px">Pelaksana</th>
                    <th>Status</th>
                    <th>Lama</th>
                    <th style="width:290px">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($bab as $b)
                    @php
                        $cp        = $b->progress;
                        $adaAuthor = $b->authors->isNotEmpty();
                        // Asal naskah bab ini, dari order yang memesannya (kolom
                        // order_details.chapters = nomor bab pada buku kolaborasi).
                        $sumber    = $cp?->sumberNaskah();
                        // Tahap tujuan tombol maju — dihitung sekali supaya labelnya
                        // jujur (bab mandiri di 'menunggu' menuju Editing, bukan Selesai).
                        $majuKe    = $cp?->nextStage();
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
                            {{--
                                Penugasan pelaksana hidup DI KOLOM INI, bukan di kolom Aksi.

                                Dua sebab. Pertama, di sinilah tempatnya secara makna —
                                kolom Aksi untuk gerakan alur kerja (unggah, majukan, ambil).
                                Kedua, dan ini yang jadi bug: formulir lama hanya dirender
                                saat `status === 'menunggu'`, padahal memasang pelaksana
                                langsung memindahkan bab ke `pembuatan`. Begitu terpasang,
                                formulirnya lenyap dan pelaksana bab TAK BISA DIUBAH LAGI —
                                bukan karena dilarang (AssignmentService::distribute()
                                mengizinkannya), melainkan karena UI tak pernah menawarkannya.
                            --}}
                            @if ($sumber === 'mandiri')
                                {{-- Naskahnya dikirim author sendiri (dari order bab ini),
                                     jadi memang tak akan pernah ada pelaksana. --}}
                                <span class="badge bg-info-subtle text-info border">Naskah Mandiri</span>
                            @elseif (! $cp || ! $adaAuthor)
                                <span class="text-muted small">—</span>
                            @else
                                @if ($cp->pelaksana)
                                    <div class="d-flex align-items-center gap-1">
                                        <span>{{ $cp->pelaksana->name }}</span>
                                        @if ($izin['assign'] && $cp->status !== 'selesai')
                                            <button type="button" class="btn btn-link btn-sm p-0 text-muted"
                                                    data-bs-toggle="collapse"
                                                    data-bs-target="#gantiPelaksana{{ $cp->id }}"
                                                    title="Ganti pelaksana bab ini">ubah</button>
                                        @endif
                                    </div>
                                @elseif ($sumber === 'dibuatkan')
                                    <span class="text-muted">Belum ditugaskan</span>
                                @else
                                    <span class="text-warning">Bab belum dipesan</span>
                                @endif

                                @if ($izin['assign'] && $cp->status !== 'selesai')
                                    <div class="{{ $cp->pelaksana ? 'collapse' : '' }} mt-1"
                                         id="gantiPelaksana{{ $cp->id }}">
                                        <form method="POST" action="{{ route('naskah.bab.distribusi', $cp->id) }}"
                                              class="d-flex gap-1">
                                            @csrf
                                            <select name="pelaksana_user_id" class="form-select form-select-sm" required>
                                                <option value="">— pelaksana —</option>
                                                @foreach ($pelaksanaOptions as $u)
                                                    <option value="{{ $u->id }}"
                                                        @selected((int) $cp->pelaksana_user_id === (int) $u->id)>{{ $u->name }}</option>
                                                @endforeach
                                            </select>
                                            <button class="btn btn-sm btn-outline-primary text-nowrap">
                                                {{ $cp->pelaksana ? 'Ganti' : 'Tugaskan' }}
                                            </button>
                                        </form>
                                    </div>
                                @endif
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
                            @elseif ($sumber === 'mandiri' && $cp->status !== 'selesai')
                                {{--
                                    Bab bernaskah mandiri: naskahnya datang dari author, jadi
                                    yang dibutuhkan unggahan — bukan pelaksana.

                                    SATU aksi utama pada satu waktu. Versi sebelumnya selalu
                                    menumpuk formulir unggah DAN tombol maju sekaligus, karena
                                    cabang ini melayani dua keadaan (menunggu dan editing)
                                    dengan tampilan yang sama. Dua kotak berjejal di sel
                                    selebar 290px, dan pembacanya harus menebak mana yang
                                    dimaksudkan untuknya.

                                    Keadaannya sebenarnya sederhana: selama naskahnya belum
                                    masuk, satu-satunya yang berarti adalah mengunggah —
                                    memajukan bab tanpa naskah tak berarti apa-apa. Begitu
                                    naskahnya ada, giliran tombol maju yang jadi utama, dan
                                    unggahan turun jadi tautan kecil untuk menggantinya.
                                --}}
                                @php
                                    // 'gagal' tidak dihitung: berkasnya memang tak sampai.
                                    $naskahMasuk = $b->manuscriptFiles
                                        ->where('slot', 'masuk')
                                        ->whereIn('status', ['selesai', 'antre'])
                                        ->isNotEmpty();
                                @endphp

                                @if (! $naskahMasuk)
                                    @if ($izin['upload'])
                                        <form method="POST" action="{{ route('naskah.bab.file', $cp->id) }}"
                                              enctype="multipart/form-data" class="d-flex gap-1">
                                            @csrf
                                            <input type="hidden" name="slot" value="masuk">
                                            <input type="file" name="file" class="form-control form-control-sm"
                                                   accept=".pdf,.doc,.docx,.zip" required>
                                            <button class="btn btn-sm btn-primary text-nowrap">⬆ Naskah</button>
                                        </form>
                                        <div class="form-text mt-1">Naskah dikirim author sendiri.</div>
                                    @else
                                        <span class="text-muted small">Menunggu naskah dari author</span>
                                    @endif
                                @else
                                    @if ($izin['advance'] && $majuKe)
                                        <form method="POST" action="{{ route('naskah.bab.selesaikan', $cp->id) }}">
                                            @csrf
                                            <button class="btn btn-sm {{ $majuKe === 'selesai' ? 'btn-primary' : 'btn-outline-primary' }} text-nowrap">
                                                {{ $majuKe === 'selesai'
                                                    ? '✓ Selesaikan Bab'
                                                    : '→ Majukan ke ' . \App\Models\ChapterProgress::labelFor($majuKe) }}
                                            </button>
                                        </form>
                                    @endif
                                    @if ($izin['upload'])
                                        {{-- Turun jadi tautan: mengganti naskah itu perkecualian,
                                             bukan pekerjaan sehari-hari. --}}
                                        <button type="button" class="btn btn-link btn-sm p-0 text-muted mt-1"
                                                data-bs-toggle="collapse" data-bs-target="#gantiNaskah{{ $cp->id }}">
                                            ganti naskah
                                        </button>
                                        <div class="collapse mt-1" id="gantiNaskah{{ $cp->id }}">
                                            <form method="POST" action="{{ route('naskah.bab.file', $cp->id) }}"
                                                  enctype="multipart/form-data" class="d-flex gap-1">
                                                @csrf
                                                <input type="hidden" name="slot" value="masuk">
                                                <input type="file" name="file" class="form-control form-control-sm"
                                                       accept=".pdf,.doc,.docx,.zip" required>
                                                <button class="btn btn-sm btn-outline-primary text-nowrap">⬆</button>
                                            </form>
                                        </div>
                                    @endif
                                    @if (! $izin['advance'] && ! $izin['upload'])
                                        <span class="text-muted small">Naskah sudah masuk</span>
                                    @endif
                                @endif
                            {{-- Penugasan pelaksana PINDAH ke kolom Pelaksana. Kolom ini
                                 hanya untuk gerakan alur kerja, dan dua pintu ke aksi yang
                                 sama hanya membuat orang bertanya mana yang benar. --}}
                            @elseif ($cp->pelaksana_user_id === null && $cp->status !== 'selesai' && $izin['claim'])
                                {{-- Model campuran: admin boleh menugaskan, produksi boleh
                                     mengambil sendiri. Tanpa tombol ini produksi tak punya
                                     jalan menggarap bab dari halaman bukunya. --}}
                                <form method="POST" action="{{ route('naskah.bab.claim', $cp->id) }}">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-primary">✋ Ambil Bab Ini</button>
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
                            @elseif ($cp->status !== 'selesai' && $izin['advance'] && $majuKe)
                                {{-- Label diturunkan dari tahap TUJUAN, bukan dipatok
                                     "Selesaikan Bab". CHAPTER_STAGES = menunggu →
                                     pembuatan → editing → selesai, jadi tombol ini sering
                                     bukan menyelesaikan apa pun — ia memajukan satu
                                     langkah. Cabang bab mandiri di atas sudah jujur soal
                                     ini sejak awal; cabang inilah yang tertinggal. --}}
                                <form method="POST" action="{{ route('naskah.bab.selesaikan', $cp->id) }}">
                                    @csrf
                                    <button class="btn btn-sm {{ $majuKe === 'selesai' ? 'btn-primary' : 'btn-outline-primary' }} text-nowrap">
                                        {{ $majuKe === 'selesai'
                                            ? '✓ Selesaikan Bab'
                                            : '→ Majukan ke ' . \App\Models\ChapterProgress::labelFor($majuKe) }}
                                    </button>
                                </form>
                            {{-- Berkas final bab hanya boleh diunggah PJ (yang bertanggung
                                 jawab atas naskahnya) dan pelaksana bab ini sendiri.
                                 Sebelumnya cukup `$izin['upload']`, yang terbuka untuk
                                 SEMUA role produksi — jadi siapa pun bisa menimpa berkas
                                 bab milik orang lain, dan tak ada jejak siapa yang
                                 seharusnya bertanggung jawab atasnya. --}}
                            @elseif ($izin['upload'] && ($izin['advance'] || (int) $cp->pelaksana_user_id === (int) auth()->id()))
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
                                        {{-- Hanya berkas yang benar-benar mendarat yang
                                             ditautkan. Yang masih antre belum punya
                                             drive_file_id, dan tautannya cuma memuat ulang
                                             halaman yang sama. --}}
                                        @if ($f->status === 'selesai')
                                            <a href="{{ route('naskah.berkas', $f->id) }}" target="_blank" rel="noopener">
                                                {{ $f->slotLabel() }} v{{ $f->version }}
                                            </a>
                                        @else
                                            <span class="text-muted">
                                                {{ $f->slotLabel() }} v{{ $f->version }}
                                                ({{ $f->status === 'antre' ? 'antre' : 'gagal' }})
                                            </span>
                                        @endif
                                        @if (! $loop->last) · @endif
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
