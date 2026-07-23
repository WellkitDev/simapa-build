<?php

namespace App\Services;

use App\Models\ManuscriptFile;
use App\Models\Title;
use App\Models\TitleChapter;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class ManuscriptFileService
{
    public function __construct(private GoogleDriveService $drive) {}

    public function upload(Title $title, ?TitleChapter $chapter, string $slot, UploadedFile $file, User $actor): ManuscriptFile
    {
        if (! array_key_exists($slot, ManuscriptFile::SLOTS)) {
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

        return ManuscriptFile::create([
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
