<?php

namespace App\Http\Controllers\Pages\Naskah;

use App\Http\Controllers\Controller;
use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Models\TitleProgressLog;
use App\Models\User;
use App\Services\ChapterRollupService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Pelacakan Naskah (Layar 2 / 2B) — papan monitoring, hanya-baca untuk semua role.
 * Aksi ada di Detail Naskah; kartu di sini menautkan ke sana, bukan ke halaman order.
 *
 * Satu kartu = satu JUDUL (grup order), bukan satu baris order — kerja tidak dobel.
 * Kartu duduk di kolom tahap paling belakang di antara order sejudul (bottleneck).
 */
class PelacakanNaskahController extends Controller
{
    /** Zona papan per spec §6. Urutan kolom = urutan tahap. */
    private const ZONA = [
        'artikel' => [
            ['label' => 'Antrian',    'catatan' => 'menunggu distribusi', 'warna' => 'z1', 'kolom' => ['menunggu_proses']],
            ['label' => 'Produksi',   'catatan' => 'admin & pelaksana',   'warna' => 'z2', 'kolom' => ['pembuatan', 'editing', 'submit', 'revisi']],
            ['label' => 'Finalisasi', 'catatan' => 'admin & superadmin',  'warna' => 'z3', 'kolom' => ['loa', 'publish']],
        ],
        'buku' => [
            ['label' => 'Antrian',              'catatan' => 'menunggu distribusi',      'warna' => 'z1', 'kolom' => ['menunggu_proses']],
            ['label' => 'Produksi per Bab',     'catatan' => 'pembuatan & editing',      'warna' => 'z2', 'kolom' => ['pembuatan', 'editing']],
            ['label' => 'Produksi Level Buku',  'catatan' => 'setelah semua bab selesai', 'warna' => 'z4', 'kolom' => ['layout', 'proofreading', 'isbn']],
            ['label' => 'Finalisasi',           'catatan' => 'admin & superadmin',       'warna' => 'z3', 'kolom' => ['cetak', 'terbit']],
        ],
    ];

    public function index(Request $request)
    {
        $tipe = $request->input('tipe') === 'buku' ? 'buku' : 'artikel';
        $view = in_array($request->input('view'), ['daftar', 'riwayat'], true)
            ? $request->input('view') : 'papan';

        $kartu = $this->kartu($this->filtered($request, $tipe));

        return view('naskah.pelacakan', [
            'tipe'    => $tipe,
            'tampil'  => $view,
            'zona'    => $this->zona($tipe),
            'kartu'   => $kartu,
            'riwayat' => $view === 'riwayat' ? $this->riwayat($tipe) : collect(),
            'filter'  => $request->only(['pj', 'pelaksana', 'prioritas', 'cari']),
            'orang'   => $this->orang(),
        ]);
    }

    /**
     * Kolom tiap zona diurutkan ulang menurut ARTICLE_STAGES/BOOK_STAGES, sehingga ZONA
     * cukup menyatakan tahap mana milik zona mana — bukan urutannya.
     *
     * Sebelum ini urutan tahap punya dua salinan, dan yang satu langsung basi begitu
     * yang lain diubah: memindahkan `revisi` ke belakang `submit` di modelnya
     * meninggalkan papan ini menampilkan urutan lama tanpa satu pun galat.
     *
     * array_intersect mempertahankan urutan argumen PERTAMA, jadi daftar tahaplah yang
     * menentukan — bukan urutan penulisan di ZONA.
     */
    private function zona(string $tipe): array
    {
        $urut = $tipe === 'buku'
            ? TitleProgress::BOOK_STAGES
            : TitleProgress::ARTICLE_STAGES;

        return array_map(function (array $z) use ($urut) {
            $z['kolom'] = array_values(array_intersect($urut, $z['kolom']));

            return $z;
        }, self::ZONA[$tipe]);
    }

    /** Arsip — naskah selesai & dibatalkan. Tidak pernah hilang, selalu bisa dicari. */
    public function arsip(Request $request)
    {
        $hanya = in_array($request->input('hanya'), ['batal', 'ditarik'], true)
            ? $request->input('hanya')
            : 'selesai';

        /*
         | Tab "Ditarik" berdiri sendiri, bukan digabung ke Selesai maupun Dibatalkan.
         |
         | Naskah yang ditarik sudah hilang dari papan lewat scopeActive(). Tanpa tab
         | sendiri ia juga tak masuk kedua tab lama — `archived_at` biasanya masih kosong
         | (penarikan bisa terjadi jauh sebelum tahap akhir) dan `cancelled_at` memang tak
         | pernah diisi jalur refund. Naskahnya akan lenyap sama sekali dari UI.
         |
         | Tab Selesai juga harus MENOLAK yang ditarik: naskah yang sudah terbit lalu
         | di-refund punya `archived_at`, jadi tanpa saringan ia muncul di dua tempat.
         */
        $rows = TitleProgress::with(['orderDetail.order', 'pj', 'pelaksana', 'cancelledBy'])
            ->when($hanya === 'batal',
                fn (Builder $q) => $q->whereNotNull('cancelled_at'))
            ->when($hanya === 'ditarik',
                fn (Builder $q) => $q->whereNotNull('withdrawn_at'))
            ->when($hanya === 'selesai',
                fn (Builder $q) => $q->whereNotNull('archived_at')
                                     ->whereNull('cancelled_at')
                                     ->whereNull('withdrawn_at'))
            ->orderByDesc('archived_at')
            ->orderByDesc('cancelled_at')
            ->get();

        return view('naskah.arsip', ['rows' => $rows, 'hanya' => $hanya]);
    }

    // ─── Internal ───

    /** @return Collection<int,TitleProgress> */
    private function filtered(Request $request, string $tipe): Collection
    {
        $bookTypes = ['bk_mandiri', 'bk_kolab'];

        return TitleProgress::with([
                'orderDetail.order', 'orderDetail.titleRef', 'pj', 'pelaksana',
            ])
            ->active()
            ->whereHas('orderDetail', function (Builder $q) use ($tipe, $bookTypes) {
                $tipe === 'buku'
                    ? $q->whereIn('type', $bookTypes)
                    : $q->whereNotIn('type', $bookTypes);
            })
            ->when($request->filled('pj'), fn (Builder $q) => $q->where('pj_user_id', $request->input('pj')))
            ->when($request->filled('pelaksana'), fn (Builder $q) => $q->where('pelaksana_user_id', $request->input('pelaksana')))
            ->when($request->filled('prioritas'), fn (Builder $q) => $q->where('priority', $request->input('prioritas')))
            ->when($request->filled('cari'), function (Builder $q) use ($request) {
                $cari = '%' . $request->input('cari') . '%';
                $q->whereHas('orderDetail', fn (Builder $d) => $d
                    ->where('title', 'like', $cari)
                    ->orWhereHas('order', fn (Builder $o) => $o->where('code_order', 'like', $cari)));
            })
            ->get();
    }

    /**
     * Satu kartu per grup judul. Tahap kartu = tahap paling awal di antara order
     * sejudul — kartu selalu duduk di kolom bottleneck, bukan yang paling maju.
     *
     * @return Collection<string,array>
     */
    private function kartu(Collection $progresses): Collection
    {
        $rollup = app(ChapterRollupService::class);

        return $progresses
            ->groupBy(fn (TitleProgress $p) => $p->orderDetail?->group_key ?? 'tp:' . $p->id)
            ->map(function (Collection $varian) use ($rollup) {
                $stages = $varian->first()->getStages();
                $utama  = $varian->sortBy(fn ($p) => array_search($p->status, $stages, true))->first();
                $book   = $utama->orderDetail?->titleRef;
                $kolab  = $book !== null && $rollup->isCollaborative($book);

                return [
                    'progress'  => $utama,
                    'jumlah'    => $varian->count(),
                    'status'    => $utama->status,
                    'kolab'     => $kolab,
                    'ringkasan' => $kolab ? $rollup->summary($book) : null,
                    // Dihitung dari SELURUH order sejudul, bukan dari order perwakilan —
                    // satu judul bisa dicakup order "dibuatkan" dan "mandiri" sekaligus.
                    'jenisNaskah' => OrderDetail::jenisNaskahGrup($varian->map->orderDetail),
                ];
            })
            ->values()
            ->groupBy('status');
    }

    private function riwayat(string $tipe): Collection
    {
        $bookTypes = ['bk_mandiri', 'bk_kolab'];

        return TitleProgressLog::with(['changedBy', 'titleProgress.orderDetail.order'])
            ->whereHas('titleProgress.orderDetail', function (Builder $q) use ($tipe, $bookTypes) {
                $tipe === 'buku'
                    ? $q->whereIn('type', $bookTypes)
                    : $q->whereNotIn('type', $bookTypes);
            })
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();
    }

    /** Daftar orang untuk filter PJ / Pelaksana. */
    private function orang(): array
    {
        return [
            'pj' => User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))
                ->orderBy('name')->get(['id', 'name']),
            'pelaksana' => User::whereHas('roles', fn ($q) => $q->where('name', 'production'))
                ->orderBy('name')->get(['id', 'name']),
        ];
    }
}
