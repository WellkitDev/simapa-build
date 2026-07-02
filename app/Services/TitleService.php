<?php

namespace App\Services;

use App\Models\Scope;
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
            'scope_id'    => $this->resolveScopeId($data['scope_id'] ?? null),
            'assigned_to' => ! empty($data['assigned_to']) ? (int) $data['assigned_to'] : null,
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
            'scope_id'    => $this->resolveScopeId($data['scope_id'] ?? null),
            'assigned_to' => ! empty($data['assigned_to']) ? (int) $data['assigned_to'] : null,
        ]);

        if ($title->jenis === 'buku') {
            $this->syncChapters($title, $chapters);
        } else {
            $title->chapters()->delete();
        }
    }

    /** Terima id scope yang ada, atau buat baru dari nama bidang ilmu (pola order). */
    private function resolveScopeId($value): ?int
    {
        if (empty($value)) {
            return null;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        return Scope::firstOrCreate(['scope' => $value])->id;
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

    /**
     * Resolusi judul untuk order: id yang ada → judul tsb; nama baru → buat Title (asal=order, disetujui)
     * dari field order yang sedang diisi. $ctx: jenis, order_type, scope_id?, indeksasi?.
     */
    public function resolveForOrder(int|string $value, array $ctx, User $actor): Title
    {
        if (is_numeric($value)) {
            $existing = Title::find((int) $value);
            if ($existing) {
                return $existing;
            }
        }

        // Jika nama (bukan id), cek apakah judul dengan nama yang sama sudah ada
        if (! is_numeric($value)) {
            $existing = Title::where('title', (string) $value)->first();
            if ($existing) {
                return $existing;
            }
        }

        return Title::create([
            'title'       => (string) $value,
            'jenis'       => $ctx['jenis'],
            'tipe_naskah' => str_contains($ctx['order_type'] ?? '', 'kolab') ? 'kolaborasi' : 'mandiri',
            'scope_id'    => $ctx['scope_id'] ?? null,
            'indeksasi'   => $ctx['indeksasi'] ?? null,
            'status'      => 'disetujui',
            'asal'        => 'order',
            'created_by'  => $actor->id,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ]);
    }
}
