<?php

namespace App\Services;

use App\Models\Scope;
use App\Models\Title;
use App\Models\TitleLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
        $title->update([
            'slug' => Str::slug($title->title) . '-' . $title->id,
            'code' => app(TitleCodeService::class)->generate($title->title, $title->id),
        ]);

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

        // Jika nama (bukan id), pakai ulang judul dengan nama + jenis sama (hindari duplikat &
        // salah-taut lintas jenis: order jurnal tak boleh menaut judul buku bernama sama).
        if (! is_numeric($value)) {
            $existing = Title::where('title', (string) $value)
                ->where('jenis', $ctx['jenis'])
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        return Title::create([
            'title'       => (string) $value,
            'code'        => app(TitleCodeService::class)->generate((string) $value),
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

    /**
     * Perbarui informasi publikasi judul + opsi jurnal, tulis log ringkasan perubahan,
     * dan beri tahu superadmin. $data: code?, target_terbit?, jurnal_target?, jurnal_link?,
     * template_link?, apc_info?, catatan_publikasi?. $journalOptions: array of [nama_jurnal, link?, apc?].
     */
    public function updateInfo(Title $title, array $data, array $journalOptions, User $actor): void
    {
        // Kode: kosong → regenerasi dari judul.
        $newCode = trim((string) ($data['code'] ?? ''));
        $code = $newCode !== '' ? $newCode : app(TitleCodeService::class)->generate($title->title, $title->id);

        $labels = [
            'code' => 'Kode', 'target_terbit' => 'Target terbit', 'jurnal_target' => 'Jurnal target',
            'jurnal_link' => 'Link jurnal', 'template_link' => 'Template', 'apc_info' => 'APC',
            'catatan_publikasi' => 'Catatan',
        ];
        $next = [
            'code'              => $code,
            'target_terbit'     => ($data['target_terbit'] ?? '') ?: null, // absent/kosong → null (hindari '' → hari ini via date cast)
            'jurnal_target'     => $data['jurnal_target'] ?? null,
            'jurnal_link'       => $data['jurnal_link'] ?? null,
            'template_link'     => $data['template_link'] ?? null,
            'apc_info'          => $data['apc_info'] ?? null,
            'catatan_publikasi' => $data['catatan_publikasi'] ?? null,
        ];

        $changed = [];
        foreach ($labels as $field => $label) {
            $old = $field === 'target_terbit'
                ? (string) (optional($title->target_terbit)->toDateString() ?? '')
                : (string) ($title->$field ?? '');
            $new = (string) ($next[$field] ?? '');
            if ($old !== $new) {
                $changed[] = $label;
            }
        }

        // Snapshot opsi jurnal sebelum, untuk deteksi perubahan.
        $before = $title->journalOptions()->orderBy('urutan')->get()
            ->map(fn ($o) => $o->nama_jurnal . '|' . $o->link . '|' . $o->apc)->implode(';;');

        DB::transaction(function () use ($title, $next, $journalOptions) {
            $title->update($next);

            $title->journalOptions()->delete();
            $i = 0;
            foreach ($journalOptions as $opt) {
                $journalId = ! empty($opt['journal_id']) ? (int) $opt['journal_id'] : null;
                $journal   = $journalId ? \App\Models\Journal::find($journalId) : null;
                $nama      = $journal ? $journal->nama : trim((string) ($opt['nama_jurnal'] ?? ''));
                if ($nama === '') {
                    continue;
                }
                $title->journalOptions()->create([
                    'journal_id'  => $journal?->id,
                    'nama_jurnal' => $nama,
                    'link'        => $journal ? $journal->link : ($opt['link'] ?? null),
                    'apc'         => $journal ? $journal->apc_reguler : ($opt['apc'] ?? null),
                    'urutan'      => $i++,
                ]);
            }
        });

        $after = $title->journalOptions()->orderBy('urutan')->get()
            ->map(fn ($o) => $o->nama_jurnal . '|' . $o->link . '|' . $o->apc)->implode(';;');
        if ($before !== $after) {
            $changed[] = 'Opsi jurnal';
        }

        $note = $changed ? implode(', ', array_unique($changed)) . ' diperbarui' : 'Info publikasi disimpan';

        TitleLog::create([
            'title_id'   => $title->id,
            'event'      => 'info_updated',
            'note'       => $note,
            'changed_by' => $actor->id,
            'created_at' => now(),
        ]);

        app(Notifier::class)->titleInfoUpdated($title->fresh(), $actor);
    }
}
