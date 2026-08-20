<?php

namespace App\Services;

use App\Jobs\UnggahBerkasKeDrive;
use App\Models\ManuscriptFile;
use App\Models\Title;
use App\Models\TitleChapter;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ManuscriptFileService
{
    /** Tempat berkas menunggu giliran diunggah, relatif terhadap disk `local`. */
    private const FOLDER_ANTRE = 'unggahan-antre';

    /**
     * Menerima berkas dan MENGANTREKANNYA — tidak menghubungi Google Drive.
     *
     * Sebelumnya method ini memanggil Drive di dalam request, jadi halaman
     * menggantung selama berkas 20 MB dikirim ke jaringan. Sekarang berkasnya
     * dipindahkan ke disk server (berkas sementara milik PHP sudah lenyap saat
     * worker jalan semenit kemudian), barisnya dibuat berstatus 'antre', dan
     * sisanya dikerjakan UnggahBerkasKeDrive.
     *
     * Barisnya sengaja dibuat SEKARANG, bukan nanti oleh job: tanpa itu berkas yang
     * baru dipilih tak muncul di layar sama sekali dan orang mengira unggahannya
     * hilang.
     */
    public function upload(Title $title, ?TitleChapter $chapter, string $slot, UploadedFile $file, User $actor): ManuscriptFile
    {
        if (! in_array($slot, ManuscriptFile::slotSah(), true)) {
            throw ValidationException::withMessages(['slot' => 'Slot naskah tidak valid.']);
        }

        $chapterId = $chapter?->id;
        $next = (int) ManuscriptFile::where('title_id', $title->id)
            ->where('title_chapter_id', $chapterId)
            ->where('slot', $slot)
            ->max('version') + 1;

        $lokal = $file->store(self::FOLDER_ANTRE, 'local');
        if (! $lokal) {
            throw ValidationException::withMessages([
                'file' => 'Berkas gagal disimpan di server. Periksa ruang disk dan izin folder storage.',
            ]);
        }

        $record = ManuscriptFile::create([
            'title_id'         => $title->id,
            'title_chapter_id' => $chapterId,
            'slot'             => $slot,
            'status'           => 'antre',
            'version'          => $next,
            'original_name'    => $file->getClientOriginalName(),
            'local_path'       => $lokal,
            'file_size'        => $file->getSize(),
            'uploaded_by'      => $actor->id,
            'created_at'       => now(),
        ]);

        UnggahBerkasKeDrive::dispatch($record->id);

        return $record;
    }

    /**
     * Dipanggil job SESUDAH berkas benar-benar mendarat di Drive.
     *
     * Upload naskah masuk = bukti kerja yang memajukan tahap otomatis (keputusan #4,
     * satu-satunya transisi otomatis). Sengaja TIDAK dijalankan saat pengantrean:
     * tahap yang maju atas berkas yang kemudian gagal diunggah adalah kebohongan
     * yang tak seorang pun menyaksikan terjadinya.
     */
    public function majukanTahapSetelahUnggah(ManuscriptFile $berkas): void
    {
        $title  = Title::find($berkas->title_id);
        $actor  = User::find($berkas->uploaded_by);
        if (! $title || ! $actor) {
            return;
        }

        $stages = app(TitleProgressService::class);

        if ($berkas->title_chapter_id !== null) {
            $chapter = TitleChapter::with('progress')->find($berkas->title_chapter_id);
            if ($chapter?->progress) {
                $stages->autoAdvanceChapterOnUpload($chapter->progress, $actor, $berkas->slot);
            }

            return;
        }

        $progress = $title->orderDetails()->with('titleProgress')->get()
            ->map->titleProgress->filter()->first();

        if ($progress) {
            $stages->autoAdvanceOnUpload($progress, $actor, $berkas->slot);
        }
    }

    /** Versi terbaru sebuah slot, apa pun statusnya — layar perlu menampilkan yang masih antre. */
    public function latest(Title $title, ?int $chapterId, string $slot): ?ManuscriptFile
    {
        return ManuscriptFile::where('title_id', $title->id)
            ->where('title_chapter_id', $chapterId)
            ->where('slot', $slot)
            ->orderByDesc('version')->first();
    }

    /** @return Collection<int,ManuscriptFile> */
    public function versions(Title $title, ?int $chapterId, string $slot): Collection
    {
        return ManuscriptFile::where('title_id', $title->id)
            ->where('title_chapter_id', $chapterId)
            ->where('slot', $slot)
            ->orderByDesc('version')->get();
    }
}
