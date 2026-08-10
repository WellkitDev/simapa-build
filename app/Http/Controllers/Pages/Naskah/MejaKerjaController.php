<?php

namespace App\Http\Controllers\Pages\Naskah;

use App\Http\Controllers\Controller;
use App\Models\ChapterProgress;
use App\Models\TitleProgress;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Meja Kerja Saya (Layar 1) — menjawab tiga pertanyaan sekaligus: apa tugasku,
 * mana yang mendesak, apa aksiku.
 *
 * Tugas datang dari dua sumber yang setara di mata pengguna: naskah level judul
 * dan bab buku kolaborasi. Keduanya digabung jadi satu daftar, lalu diurutkan
 * terlambat → deadline terdekat → prioritas.
 */
class MejaKerjaController extends Controller
{
    public function index(Request $request)
    {
        $actor = $request->user();

        $tugas = $this->tugasJudul($actor)
            ->merge($this->tugasBab($actor))
            ->sortBy([
                fn ($a, $b) => ($b['terlambat'] <=> $a['terlambat']),
                fn ($a, $b) => ($this->deadlineKey($a) <=> $this->deadlineKey($b)),
                fn ($a, $b) => ($this->prioritasKey($b) <=> $this->prioritasKey($a)),
            ])
            ->values();

        return view('naskah.meja-kerja', [
            'tugas'   => $tugas,
            'antrian' => $actor->can('naskah.claim') ? $this->antrian() : collect(),
            'stat'    => $this->statistik($actor, $tugas),
            'izin'    => [
                'advance' => $actor->can('naskah.advance'),
                'upload'  => $actor->can('naskah.upload'),
                'claim'   => $actor->can('naskah.claim'),
            ],
            'akuId'   => $actor->id,
        ]);
    }

    /** Naskah tempat user jadi pelaksana ATAU PJ. */
    private function tugasJudul(User $actor): Collection
    {
        return TitleProgress::with(['orderDetail.order', 'orderDetail.authors', 'pj', 'pelaksana'])
            ->active()
            ->mine($actor->id)
            ->whereNotIn('status', TitleProgress::FINAL_STAGES)
            ->get()
            ->map(fn (TitleProgress $p) => [
                'jenis'     => 'judul',
                'model'     => $p,
                'terlambat' => $p->isOverdue(),
                'deadline'  => $p->status === 'pembuatan' ? $p->sla_due_at : $p->target_date,
                'prioritas' => $p->priority,
            ]);
    }

    /** Bab buku kolaborasi tempat user jadi pelaksana. */
    private function tugasBab(User $actor): Collection
    {
        return ChapterProgress::with(['chapter.title.orderDetails.order', 'chapter.authors', 'pelaksana'])
            ->where('pelaksana_user_id', $actor->id)
            ->where('status', '!=', 'selesai')
            ->get()
            ->map(fn (ChapterProgress $c) => [
                'jenis'     => 'bab',
                'model'     => $c,
                'terlambat' => $c->isOverdue(),
                'deadline'  => $c->sla_due_at,
                'prioritas' => 'normal',
            ]);
    }

    /**
     * Antrian belum ditugaskan — model campuran: admin boleh assign, produksi boleh
     * ambil sendiri. Bab yang author-nya belum dipetakan sengaja TIDAK muncul di sini,
     * karena memang belum boleh didistribusikan.
     */
    private function antrian(): Collection
    {
        $judul = TitleProgress::with(['orderDetail.order', 'orderDetail.authors', 'pj'])
            ->active()
            ->whereNull('pelaksana_user_id')
            ->whereIn('status', TitleProgress::QUEUE_STAGES)
            ->get()
            ->map(fn (TitleProgress $p) => ['jenis' => 'judul', 'model' => $p]);

        $bab = ChapterProgress::with(['chapter.title.orderDetails.order', 'chapter.authors'])
            ->whereNull('pelaksana_user_id')
            ->whereIn('status', ['menunggu', 'pembuatan'])
            ->get()
            ->filter(fn (ChapterProgress $c) => $c->chapter?->authors->isNotEmpty())
            ->map(fn (ChapterProgress $c) => ['jenis' => 'bab', 'model' => $c]);

        return $judul->merge($bab)->values();
    }

    /** Empat angka kartu statistik wireframe. */
    private function statistik(User $actor, Collection $tugas): array
    {
        $akhirPekanIni = now()->endOfWeek();

        return [
            'aktif'     => $tugas->count(),
            'terlambat' => $tugas->where('terlambat', true)->count(),
            'minggu'    => $tugas->filter(fn ($t) => $t['deadline'] !== null
                                && $t['deadline']->lte($akhirPekanIni))->count(),
            'selesai'   => TitleProgress::mine($actor->id)
                ->whereIn('status', TitleProgress::FINAL_STAGES)
                ->whereMonth('updated_at', now()->month)
                ->whereYear('updated_at', now()->year)
                ->count()
                + ChapterProgress::where('pelaksana_user_id', $actor->id)
                ->where('status', 'selesai')
                ->whereMonth('updated_at', now()->month)
                ->whereYear('updated_at', now()->year)
                ->count(),
        ];
    }

    /** Tanpa deadline = paling belakang, bukan paling depan. */
    private function deadlineKey(array $tugas): int
    {
        return $tugas['deadline']?->timestamp ?? PHP_INT_MAX;
    }

    private function prioritasKey(array $tugas): int
    {
        return match ($tugas['prioritas']) {
            'high'  => 3,
            'normal' => 2,
            default => 1,
        };
    }
}
