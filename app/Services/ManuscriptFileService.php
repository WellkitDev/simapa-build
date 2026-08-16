<?php

namespace App\Services;

use App\Models\ManuscriptFile;
use App\Models\Title;
use App\Models\TitleChapter;
use App\Models\User;
use App\Services\TitleProgressService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class ManuscriptFileService
{
    public function __construct(private GoogleDriveService $drive) {}

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

        $uploaded = $this->drive->uploadFile($file, null, false);
        if (! $uploaded) {
            throw ValidationException::withMessages(['file' => 'Gagal mengunggah file ke Drive.']);
        }

        $record = ManuscriptFile::create([
            'title_id'         => $title->id,
            'title_chapter_id' => $chapterId,
            'slot'             => $slot,
            'version'          => $next,
            'original_name'    => $file->getClientOriginalName(),
            'drive_file_id'    => $uploaded['id'] ?? null,
            'drive_url'        => $uploaded['url'] ?? null,
            'file_size'        => $file->getSize(),
            'uploaded_by'      => $actor->id,
            'created_at'       => now(),
        ]);

        $this->autoAdvance($title, $chapter, $slot, $actor);

        return $record;
    }

    /**
     * Upload naskah masuk = bukti kerja yang memajukan tahap otomatis (keputusan #4,
     * satu-satunya transisi otomatis). Bab digerakkan sendiri lalu status buku dihitung
     * ulang; naskah level judul digerakkan lewat TitleProgressService yang sekaligus
     * menyebarkannya ke seluruh order sejudul.
     */
    private function autoAdvance(Title $title, ?TitleChapter $chapter, string $slot, User $actor): void
    {
        $stages = app(TitleProgressService::class);

        if ($chapter !== null) {
            if ($chapter->progress) {
                $stages->autoAdvanceChapterOnUpload($chapter->progress, $actor, $slot);
            }

            return;
        }

        $progress = $title->orderDetails()->with('titleProgress')->get()
            ->map->titleProgress->filter()->first();

        if ($progress) {
            $stages->autoAdvanceOnUpload($progress, $actor, $slot);
        }
    }

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
