<?php

namespace App\Services;

use App\Models\DocRequirement;
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
        $activeIds = DocRequirement::active()->pluck('id')->all();

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
                $attrs['file_url']  = $this->drive->uploadFile($file, null, false)['url'] ?? null;
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

    /** @return array{done:int,total:int} */
    public function progress(Title $title, string $category): array
    {
        $reqIds = DocRequirement::active()->where('category', $category)->pluck('id');
        $done = TitleDocMark::where('title_id', $title->id)
            ->whereIn('doc_requirement_id', $reqIds)
            ->where('status', 'ada')
            ->count();

        return ['done' => $done, 'total' => $reqIds->count()];
    }
}
