<?php

namespace App\Services;

use App\Models\Title;
use App\Models\User;
use Illuminate\Support\Str;

class TitleService
{
    /** Buat judul (status draft) + bab bila buku. */
    public function create(array $data, array $chapters, User $actor): Title
    {
        $title = Title::create([
            'title'       => $data['title'],
            'jenis'       => $data['jenis'],
            'indeksasi'   => $data['indeksasi'] ?? null,
            'tipe_naskah' => $data['tipe_naskah'],
            'status'      => 'draft',
            'asal'        => 'distribusi',
            'created_by'  => $actor->id,
        ]);
        $title->update(['slug' => Str::slug($title->title) . '-' . $title->id]);

        if ($title->jenis === 'buku') {
            $this->syncChapters($title, $chapters);
        }

        return $title;
    }

    /** Perbarui judul + bab (dipanggil hanya saat editable). */
    public function update(Title $title, array $data, array $chapters): void
    {
        $title->update([
            'title'       => $data['title'],
            'jenis'       => $data['jenis'],
            'indeksasi'   => $data['indeksasi'] ?? null,
            'tipe_naskah' => $data['tipe_naskah'],
        ]);

        if ($title->jenis === 'buku') {
            $this->syncChapters($title, $chapters);
        } else {
            $title->chapters()->delete();
        }
    }

    private function syncChapters(Title $title, array $chapters): void
    {
        $title->chapters()->delete();
        $i = 0;
        foreach ($chapters as $ch) {
            $judul = is_array($ch) ? ($ch['judul'] ?? '') : $ch;
            if (trim((string) $judul) === '') {
                continue;
            }
            $title->chapters()->create(['judul' => $judul, 'urutan' => $i++]);
        }
    }

    /** Ajukan: admin/production -> menunggu; superadmin/manager -> langsung disetujui. */
    public function submit(Title $title, User $actor): void
    {
        if (! in_array($title->status, ['draft', 'ditolak'], true)) {
            return;
        }
        if ($actor->hasAnyRole(['superadmin', 'manager'])) {
            $title->update(['status' => 'disetujui', 'approved_by' => $actor->id, 'approved_at' => now(), 'reject_note' => null]);
        } else {
            $title->update(['status' => 'menunggu', 'reject_note' => null]);
        }
    }

    public function approve(Title $title, User $actor): void
    {
        if ($title->status !== 'menunggu') {
            return;
        }
        $title->update(['status' => 'disetujui', 'approved_by' => $actor->id, 'approved_at' => now()]);
    }

    public function reject(Title $title, User $actor, string $note): void
    {
        if ($title->status !== 'menunggu') {
            return;
        }
        $title->update(['status' => 'ditolak', 'reject_note' => $note, 'approved_by' => null, 'approved_at' => null]);
    }
}
