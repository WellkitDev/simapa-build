<?php

namespace App\Services;

use App\Models\ManuscriptRevision;
use App\Models\Title;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Membuka, menjawab, dan menutup putaran perbaikan.
 *
 * Putaran dibuat MALAS — hanya saat permintaan benar-benar dikirim atau tombol mundur
 * ditekan. Naskah yang lewat tahap Revisi tanpa ada revisian tak meninggalkan putaran
 * kosong yang harus dibaca orang lain nanti.
 */
class ManuscriptRevisionService
{
    public function __construct(private ManuscriptFileService $berkas) {}

    /**
     * @param  UploadedFile[]  $lampiran
     */
    public function buka(
        Title $title,
        string $stage,
        string $fromStage,
        User $actor,
        string $catatan,
        ?User $untuk = null,
        array $lampiran = []
    ): ManuscriptRevision {
        if (trim($catatan) === '') {
            throw ValidationException::withMessages([
                'request_note' => 'Catatan permintaan wajib diisi.',
            ]);
        }

        if (! in_array($stage, ManuscriptRevision::STAGES, true)) {
            throw ValidationException::withMessages(['stage' => 'Tahap putaran tidak sah.']);
        }

        return DB::transaction(function () use ($title, $stage, $fromStage, $actor, $catatan, $untuk, $lampiran) {
            $putaran = ManuscriptRevision::create([
                'title_id'     => $title->id,
                'round'        => ManuscriptRevision::nomorBerikutnya($title->id),
                'stage'        => $stage,
                'from_stage'   => $fromStage,
                'requested_by' => $actor->id,
                'assigned_to'  => $untuk?->id,
                'request_note' => $catatan,
            ]);

            foreach ($lampiran as $file) {
                $this->berkas->upload($title, null, 'revisi_minta', $file, $actor)
                    ->update(['manuscript_revision_id' => $putaran->id]);
            }

            app(RiwayatNaskahService::class)->catatJudul(
                $title, 'revisi_diminta', $actor,
                null, "putaran ke-{$putaran->round}",
                trim($catatan . ($untuk ? ' → ' . $untuk->name : ''))
            );

            return $putaran;
        });
    }

    /**
     * Jawaban boleh datang dari Pelaksana maupun PJ — perbaikannya bisa dikerjakan
     * siapa pun yang memegang berkasnya.
     *
     * @param  UploadedFile[]  $lampiran
     */
    public function jawab(ManuscriptRevision $putaran, User $actor, array $lampiran): ManuscriptRevision
    {
        if (! $putaran->terbuka()) {
            throw ValidationException::withMessages([
                'putaran' => 'Putaran ini sudah ditutup.',
            ]);
        }

        if ($lampiran === []) {
            throw ValidationException::withMessages([
                'berkas' => 'Pilih minimal satu berkas hasil revisi.',
            ]);
        }

        DB::transaction(function () use ($putaran, $actor, $lampiran) {
            foreach ($lampiran as $file) {
                $this->berkas->upload($putaran->title, null, 'revisi_hasil', $file, $actor)
                    ->update(['manuscript_revision_id' => $putaran->id]);
            }

            app(RiwayatNaskahService::class)->catatJudul(
                $putaran->title, 'revisi_dijawab', $actor,
                null, "putaran ke-{$putaran->round}",
                count($lampiran) . ' berkas hasil revisi'
            );
        });

        return $putaran->fresh();
    }

    /**
     * Pintu darurat. Tanpa ini, satu putaran yang salah buka mengunci naskah selamanya
     * dan hanya superadmin yang bisa membebaskannya — gerbang tanpa pintu darurat
     * membuat orang berhenti memakai sistemnya.
     */
    public function tutup(ManuscriptRevision $putaran, User $actor, string $catatan): ManuscriptRevision
    {
        if (trim($catatan) === '') {
            throw ValidationException::withMessages([
                'close_note' => 'Catatan wajib diisi saat menutup putaran tanpa berkas.',
            ]);
        }

        $putaran->update([
            'closed_at'  => now(),
            'closed_by'  => $actor->id,
            'close_note' => $catatan,
        ]);

        app(RiwayatNaskahService::class)->catatJudul(
            $putaran->title, 'revisi_ditutup', $actor,
            "putaran ke-{$putaran->round}", 'ditutup tanpa berkas',
            $catatan
        );

        return $putaran->fresh();
    }

    /**
     * Penutupan WAJAR: naskah maju melewati tahapnya. `close_note` sengaja dibiarkan
     * null — kosong-atau-tidak itulah yang membedakannya dari penutupan paksa lewat
     * pintu darurat, dan bedanya perlu terbaca di riwayat.
     */
    public function tutupOtomatis(Title $title, string $stage, User $actor): int
    {
        return ManuscriptRevision::where('title_id', $title->id)
            ->where('stage', $stage)
            ->whereNull('closed_at')
            ->update(['closed_at' => now(), 'closed_by' => $actor->id]);
    }

    /**
     * Putaran yang menahan laju naskah di tahap ini, bila ada.
     *
     * Hanya putaran `revisi` yang menggerbangi. Pengembalian ke Pembuatan meminta
     * naskahnya DIKERJAKAN ULANG, dan hasil kerja itu masuk lewat slot masuk/
     * hasil_editing yang sudah ada — menuntut berkas balasan di situ akan meminta orang
     * mengunggah naskah yang sama dua kali.
     */
    public function penahan(Title $title, string $stage): ?ManuscriptRevision
    {
        if ($stage !== 'revisi') {
            return null;
        }

        return ManuscriptRevision::where('title_id', $title->id)
            ->where('stage', 'revisi')
            ->whereNull('closed_at')
            ->orderBy('round')
            ->get()
            ->first(fn (ManuscriptRevision $r) => $r->menahan());
    }
}
