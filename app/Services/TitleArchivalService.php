<?php

namespace App\Services;

use App\Models\JournalSubmission;
use App\Models\Title;
use App\Models\TitleArchive;
use App\Models\TitleArchiveArtifact;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class TitleArchivalService
{
    public function __construct(private GoogleDriveService $drive) {}

    /** Daftar artefak baku (dengan prefill dari data existing) untuk render form. */
    public function defaultArtifacts(Title $title): array
    {
        $existing = $title->archiveArtifacts->keyBy('key');
        $submission = JournalSubmission::where('title_id', $title->id)->latest()->first();
        $prefill = [
            'isbn'         => optional($title->bookIsbn)->no_isbn,
            'loa'          => optional($submission)->loa_url,
            'publish_link' => optional($submission)->link_publish,
            'apc_bukti'    => optional($submission)->bukti_bayar_url,
        ];

        $out = [];
        foreach (TitleArchive::artifactsFor($title->jenis) as $key => $def) {
            $row = $existing->get($key);
            $out[] = [
                'key'         => $key,
                'label'       => $def['label'],
                'type'        => $def['type'],
                'value'       => $row->value ?? ($prefill[$key] ?? null),
                'file_name'   => $row->file_name ?? null,
                'pic_user_id' => $row->pic_user_id ?? null,
                'note'        => $row->note ?? null,
            ];
        }
        return $out;
    }

    public function saveArtifacts(Title $title, array $fixed, array $custom, User $actor): void
    {
        foreach (TitleArchive::artifactsFor($title->jenis) as $key => $def) {
            $item = $fixed[$key] ?? [];
            $attrs = [
                'label'       => $def['label'],
                'type'        => $def['type'],
                'pic_user_id' => ($item['pic_user_id'] ?? '') ?: null,
                'note'        => $item['note'] ?? null,
                'is_custom'   => false,
            ];
            if ($def['type'] === 'file') {
                if (! empty($item['file'])) {
                    $attrs['value']     = $this->drive->uploadFile($item['file'], null, false)['url'] ?? null;
                    $attrs['file_name'] = $item['file']->getClientOriginalName();
                }
                // tanpa file baru: value/file_name tak diikutkan → dipertahankan
            } else {
                $attrs['value'] = ($item['value'] ?? '') ?: null;
            }
            TitleArchiveArtifact::updateOrCreate(['title_id' => $title->id, 'key' => $key], $attrs);
        }

        // "Lainnya" (custom): ganti seluruh set.
        TitleArchiveArtifact::where('title_id', $title->id)->where('is_custom', true)->delete();
        $pos = 0;
        foreach ($custom as $c) {
            $label = trim((string) ($c['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            TitleArchiveArtifact::create([
                'title_id'    => $title->id,
                'key'         => null,
                'label'       => $label,
                'type'        => in_array($c['type'] ?? '', ['link', 'text'], true) ? $c['type'] : 'text',
                'value'       => ($c['value'] ?? '') ?: null,
                'pic_user_id' => ($c['pic_user_id'] ?? '') ?: null,
                'note'        => $c['note'] ?? null,
                'is_custom'   => true,
                'position'    => $pos++,
            ]);
        }
    }

    public function submit(Title $title, User $actor): TitleArchive
    {
        if (! $title->archiveEligible()) {
            throw ValidationException::withMessages(['archive' => 'Belum bisa diarsipkan: pastikan pembayaran lunas dan manuskrip final (terbit/publish).']);
        }
        $archive = TitleArchive::updateOrCreate(
            ['title_id' => $title->id],
            ['status' => 'diajukan', 'submitted_by' => $actor->id, 'submitted_at' => now()]
        );
        app(Notifier::class)->titleArchiveSubmitted($archive, $actor);
        return $archive;
    }

    public function approve(Title $title, User $actor, ?string $note): TitleArchive
    {
        return TitleArchive::updateOrCreate(
            ['title_id' => $title->id],
            ['status' => 'disetujui', 'approved_by' => $actor->id, 'approved_at' => now(), 'approval_note' => $note]
        );
    }

    public function reject(Title $title, User $actor, string $note): TitleArchive
    {
        return TitleArchive::updateOrCreate(
            ['title_id' => $title->id],
            ['status' => 'ditolak', 'reject_note' => $note]
        );
    }
}
