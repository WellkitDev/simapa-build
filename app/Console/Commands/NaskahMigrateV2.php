<?php

namespace App\Console\Commands;

use App\Models\ChapterProgress;
use App\Models\TitleProgress;
use App\Models\TitleProgressLog;
use App\Services\ChapterRollupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Migrasi data lama ke model Penugasan Naskah v2 (spec §8).
 *
 * Idempotent — aman dijalankan berulang; tiap langkah hanya menyentuh baris yang
 * memang belum sesuai. Tidak ada kolom lama yang dihapus di sini: pembersihan skema
 * menunggu cutover, sehingga rollback masih mungkin bila ada yang tak beres.
 */
class NaskahMigrateV2 extends Command
{
    protected $signature = 'naskah:migrate-v2 {--dry-run : Tampilkan rencana tanpa mengubah data}';

    protected $description = 'Migrasi data naskah lama ke model Penugasan Naskah v2 (bidang, PJ/pelaksana, arsip, status bab)';

    private bool $dry = false;

    public function handle(): int
    {
        $this->dry = (bool) $this->option('dry-run');

        if ($this->dry) {
            $this->warn('MODE UJI COBA — tidak ada data yang diubah.');
        }

        $ringkasan = [
            'Status "templating" → "editing"'      => $this->migrasiTemplating(),
            'Isi bidang dari tipe order'           => $this->isiBidang(),
            'Pisahkan PJ (admin) & pelaksana'      => $this->pisahkanPeran(),
            'Naskah publish/terbit → arsip'        => $this->arsipkanFinal(),
            'Status bab lama → CHAPTER_STAGES'     => $this->migrasiBab(),
            'Perbaiki author bab buku kolaborasi'  => $this->perbaikiAuthorBab(),
            'Hitung ulang roll-up buku kolaborasi' => $this->hitungRollup(),
        ];

        $this->newLine();
        $this->table(['Langkah', 'Baris'], collect($ringkasan)
            ->map(fn (int $n, string $label) => [$label, $n])->values()->all());

        $belumPunyaPj = TitleProgress::active()->whereNull('pj_user_id')->count();
        if ($belumPunyaPj > 0) {
            $this->warn("{$belumPunyaPj} naskah aktif belum punya PJ — tetapkan lewat Detail Naskah "
                      . '(filter "PJ: Semua" di Pelacakan membantu menemukannya).');
        }

        $this->info($this->dry ? 'Uji coba selesai.' : 'Migrasi selesai.');

        return self::SUCCESS;
    }

    /** Tahap 'templating' dihapus dari alur; data lama dipindahkan ke 'editing'. */
    private function migrasiTemplating(): int
    {
        $rows = TitleProgress::where('status', 'templating')->get();

        if (! $this->dry) {
            foreach ($rows as $p) {
                DB::transaction(function () use ($p) {
                    $p->update([
                        'status'        => 'editing',
                        'assigned_role' => TitleProgress::getHandlerForStatus('editing'),
                    ]);
                    TitleProgressLog::create([
                        'title_progress_id' => $p->id,
                        'event'             => 'status_corrected',
                        'from_value'        => 'Templating',
                        'to_value'          => 'Editing',
                        'changed_by'        => null,
                        'note'              => 'Migrasi v2: tahap Templating dihapus dari alur.',
                        'is_correction'     => true,
                    ]);
                });
            }
        }

        return $rows->count();
    }

    /** Bidang naskah diturunkan dari tipe order: bk_* → buku, selainnya artikel. */
    private function isiBidang(): int
    {
        $rows = TitleProgress::with('orderDetail')->whereNull('bidang')->get();

        if (! $this->dry) {
            foreach ($rows as $p) {
                $bidang = in_array($p->orderDetail?->type, ['bk_mandiri', 'bk_kolab'], true)
                    ? 'buku' : 'artikel';
                $p->update(['bidang' => $bidang]);
            }
        }

        return $rows->count();
    }

    /**
     * `assigned_user_id` lama menampung "editor" tanpa membedakan peran. Yang berrole
     * admin sebenarnya bertindak sebagai PJ, bukan pelaksana — dipindahkan ke kolom
     * yang tepat supaya papan tidak salah menyebut siapa yang mengerjakan.
     */
    private function pisahkanPeran(): int
    {
        $rows = TitleProgress::with('pelaksana')
            ->whereNotNull('pelaksana_user_id')
            ->whereNull('pj_user_id')
            ->get()
            ->filter(fn (TitleProgress $p) => $p->pelaksana?->hasRole('admin'));

        if (! $this->dry) {
            foreach ($rows as $p) {
                $p->update([
                    'pj_user_id'        => $p->pelaksana_user_id,
                    'pelaksana_user_id' => null,
                ]);
            }
        }

        return $rows->count();
    }

    /** Naskah yang sudah publish/terbit pindah ke Arsip — bukan hilang dari sistem. */
    private function arsipkanFinal(): int
    {
        $rows = TitleProgress::whereIn('status', TitleProgress::FINAL_STAGES)
            ->whereNull('archived_at')
            ->get();

        if (! $this->dry) {
            foreach ($rows as $p) {
                $p->update(['archived_at' => now()]);
            }
        }

        return $rows->count();
    }

    /** Bab lama memakai BOOK_STAGES; alur bab v2 hanya punya empat tahap. */
    private function migrasiBab(): int
    {
        $peta   = ChapterProgress::LEGACY_STAGE_MAP;
        $rows   = ChapterProgress::whereNotIn('status', ChapterProgress::CHAPTER_STAGES)->get();
        $diubah = 0;

        foreach ($rows as $cp) {
            $baru = $peta[$cp->status] ?? 'menunggu';
            if (! $this->dry) {
                $cp->update(['status' => $baru]);
            }
            $diubah++;
        }

        // Pelaksana bab: kolom lama assigned_user_id dipindahkan apa adanya.
        $pelaksana = ChapterProgress::whereNotNull('assigned_user_id')
            ->whereNull('pelaksana_user_id')->get();

        if (! $this->dry) {
            foreach ($pelaksana as $cp) {
                $cp->update(['pelaksana_user_id' => $cp->assigned_user_id]);
            }
        }

        return $diubah + $pelaksana->count();
    }

    /**
     * Penyemaian lama menyalin SELURUH author order ke setiap bab, sehingga kolom
     * "bab ini naskah dari siapa" tak menjawab apa pun. Petakan ulang satu author per
     * bab menurut urutan; bab yang tak kebagian dibiarkan kosong agar ditandai kuning
     * dan dipetakan manusia, bukan ditebak.
     */
    private function perbaikiAuthorBab(): int
    {
        $svc  = app(\App\Services\ChapterAuthorService::class);
        $buku = \App\Models\Title::where('jenis', 'buku')->get();
        $n    = 0;

        foreach ($buku as $b) {
            if ($this->dry) {
                // Hitung tanpa menulis: tiru syarat yang dipakai repair.
                $sets = $b->chapters()->with('authors')->get()
                    ->map(fn ($c) => $c->authors->pluck('id')->sort()->values()->all());

                $perluDiperbaiki = $b->orderDetails()->where('type', 'bk_kolab')->exists()
                    && $sets->isNotEmpty()
                    && ! $sets->contains([])
                    && $sets->unique(fn ($s) => implode(',', $s))->count() === 1
                    && count($sets->first()) >= 2;

                $n += $perluDiperbaiki ? 1 : 0;

                continue;
            }

            $n += $svc->repairCollaborativeMapping($b) ? 1 : 0;
        }

        return $n;
    }

    private function hitungRollup(): int
    {
        $rollup = app(ChapterRollupService::class);
        $buku   = \App\Models\Title::where('jenis', 'buku')->get()
            ->filter(fn ($b) => $rollup->isCollaborative($b));

        if (! $this->dry) {
            foreach ($buku as $b) {
                $rollup->recalc($b);
            }
        }

        return $buku->count();
    }
}
