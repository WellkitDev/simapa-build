<?php

namespace App\Services;

use App\Models\DocRequirement;
use App\Models\ManuscriptFile;
use App\Models\Title;
use App\Models\TitleDocChecklist;
use App\Models\TitleDocMark;
use App\Models\User;

class DocChecklistService
{
    public function __construct(private GoogleDriveService $drive) {}

    /** @param array $items list of ['requirement_id'=>int,'status'=>string,'catatan'=>?string,'file'=>?\Illuminate\Http\UploadedFile] */
    public function saveMarks(Title $title, array $items, User $actor): void
    {
        // Item ber-auto_source diabaikan: berkasnya milik modul lain dan statusnya
        // dihitung dari sana, jadi tak boleh ditimpa isian manual dari layar ini.
        $activeIds = DocRequirement::active()->whereNull('auto_source')->pluck('id')->all();

        foreach ($items as $item) {
            $rid = (int) ($item['requirement_id'] ?? 0);
            if (! in_array($rid, $activeIds, true)) {
                continue;
            }
            $status = in_array($item['status'] ?? '', array_keys(TitleDocMark::STATUSES), true) ? $item['status'] : 'belum';
            $attrs = [
                'status'     => $status,
                'catatan'    => $item['catatan'] ?? null,
                'updated_by' => $actor->id,
            ];
            $file = $item['file'] ?? null;
            if ($file) {
                $folder = app(DriveJudulFolderService::class)
                    ->folderKategori($title, DriveJudulFolderService::KELENGKAPAN);
                $attrs['file_url']  = $this->drive->uploadFile($file, $folder, false)['url'] ?? null;
                $attrs['file_name'] = $file->getClientOriginalName();
            }
            // Tanpa file baru: file_url/file_name tak diikutkan → nilai lama dipertahankan.
            TitleDocMark::updateOrCreate(
                ['title_id' => $title->id, 'doc_requirement_id' => $rid],
                $attrs
            );
        }
    }

    public function submit(Title $title, User $actor): TitleDocChecklist
    {
        return TitleDocChecklist::updateOrCreate(
            ['title_id' => $title->id],
            ['status' => 'diajukan', 'submitted_at' => now(), 'submitted_by' => $actor->id]
        );
    }

    /**
     * Berkas pemenuh item otomatis (mis. "Naskah Lengkap" ← slot Naskah Final).
     * Versi terbaru saja; null bila belum diunggah di modul sumbernya.
     */
    public function autoFile(Title $title, DocRequirement $requirement): ?ManuscriptFile
    {
        if (! $requirement->isAuto()) {
            return null;
        }

        return ManuscriptFile::where('title_id', $title->id)
            ->whereNull('title_chapter_id')
            ->where('slot', $requirement->auto_source === 'naskah_final' ? 'final' : $requirement->auto_source)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * @return array{done:int,total:int}
     *
     * Item otomatis dihitung dari keberadaan berkas sumbernya, bukan dari TitleDocMark —
     * kalau tidak, kelengkapan bisa mengaku 100% padahal naskah finalnya belum ada.
     */
    public function progress(Title $title, string $category): array
    {
        $requirements = DocRequirement::active()->where('category', $category)->get();

        $manual = $requirements->where('auto_source', null);
        $done = TitleDocMark::where('title_id', $title->id)
            ->whereIn('doc_requirement_id', $manual->pluck('id'))
            ->where('status', 'ada')
            ->count();

        foreach ($requirements->filter->isAuto() as $requirement) {
            if ($this->autoFile($title, $requirement) !== null) {
                $done++;
            }
        }

        return ['done' => $done, 'total' => $requirements->count()];
    }
}
