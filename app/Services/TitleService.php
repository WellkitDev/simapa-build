<?php

namespace App\Services;

use App\Models\OrderDetail;
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

    /** Perbarui judul + bab, sinkronkan teks judul ke order tertaut, dan catat perubahannya. */
    public function update(Title $title, array $data, array $chapters, User $actor): void
    {
        $labels = [
            'title'       => 'Judul',
            'jenis'       => 'Jenis',
            'indeksasi'   => 'Indeksasi',
            'tipe_naskah' => 'Tipe naskah',
            'scope_id'    => 'Bidang ilmu',
            'assigned_to' => 'Distribusi',
        ];

        $next = [
            'title'       => $data['title'],
            'jenis'       => $data['jenis'],
            'indeksasi'   => $data['indeksasi'] ?? null,
            'tipe_naskah' => $data['tipe_naskah'],
            'scope_id'    => $this->resolveScopeId($data['scope_id'] ?? null),
            'assigned_to' => ! empty($data['assigned_to']) ? (int) $data['assigned_to'] : null,
        ];

        $changed = [];
        foreach ($labels as $field => $label) {
            if ((string) ($title->$field ?? '') !== (string) ($next[$field] ?? '')) {
                $changed[] = $label;
            }
        }

        $renamed = $title->title !== $next['title'];

        DB::transaction(function () use ($title, $next, $chapters, $renamed) {
            $title->update($next);

            if ($renamed) {
                $title->update(['slug' => Str::slug($title->title) . '-' . $title->id]);

                // withTrashed(): order yang dibatalkan pun tidak boleh menyimpan judul basi.
                // Kode judul SENGAJA tidak ikut berubah — sudah tercetak di invoice & arsip.
                OrderDetail::withTrashed()
                    ->where('title_id', $title->id)
                    ->update(['title' => $title->title]);
            }

            if ($title->jenis === 'buku') {
                $this->syncChapters($title, $chapters);
            } else {
                $title->chapters()->delete();
            }
        });

        TitleLog::create([
            'title_id'   => $title->id,
            'event'      => 'updated',
            'note'       => $changed ? implode(', ', $changed) . ' diperbarui' : 'Judul disimpan',
            'changed_by' => $actor->id,
            'created_at' => now(),
        ]);
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

    /**
     * Selaraskan daftar bab dengan yang dikirim formulir — TANPA menghapus lalu membuat
     * ulang.
     *
     * Versi lama melakukan `$title->chapters()->delete()` di setiap penyimpanan judul.
     * `tb_title_chapters` punya tiga anak ber-cascade, jadi satu baris itu memusnahkan:
     *
     *   - `tb_chapter_progress`        → tahap per bab, pelaksana, dan SLA-nya
     *   - `tb_title_chapter_authors`   → author bab yang dipasang tangan
     *   - `tb_manuscript_files.title_chapter_id` → berkas kehilangan babnya
     *
     * Semuanya lenyap hanya karena seseorang mengubah satu huruf di judul.
     *
     * `urutan` juga MULAI DARI 1, bukan 0. ChapterManuscriptService::ensureChapters()
     * sudah 1-based, dan `order_details.chapters` menyimpan NOMOR bab yang 1-based.
     * Selisih satu itulah yang membuat ChapterAuthorService::seedFromOrders() mencari
     * "order bab 0" yang tak pernah ada, lalu menggeser seluruh peta author satu langkah
     * dan menandai bab pertama "belum dipesan".
     *
     * Pencocokan memakai `id` yang dikirim formulir bila ada, dan jatuh ke posisi untuk
     * baris baru. Tanpa id, menghapus bab di TENGAH akan membuat sisa bab mewarisi judul
     * tetangganya — kemajuan bab 4 mendarat di bawah label "Bab 5".
     */
    private function syncChapters(Title $title, array $chapters): void
    {
        $lama = $title->chapters()->get()->keyBy('id');
        $tetap = [];
        $urutan = 1;

        foreach ($chapters as $ch) {
            $judul = trim((string) (is_array($ch) ? ($ch['judul'] ?? '') : $ch));
            if ($judul === '') {
                continue;
            }

            $id  = is_array($ch) ? ($ch['id'] ?? null) : null;
            $bab = $id !== null ? $lama->get((int) $id) : null;

            if ($bab) {
                // fill+isDirty: penyimpanan judul yang tak mengubah bab tak perlu
                // menyentuh satu baris pun.
                $bab->fill(['judul' => $judul, 'urutan' => $urutan]);
                if ($bab->isDirty()) {
                    $bab->save();
                }
                $tetap[] = $bab->id;
            } else {
                $tetap[] = $title->chapters()->create([
                    'judul' => $judul, 'urutan' => $urutan,
                ])->id;
            }

            $urutan++;
        }

        // Hanya bab yang benar-benar dibuang dari formulir yang dihapus.
        $dibuang = $lama->keys()->diff($tetap);
        if ($dibuang->isNotEmpty()) {
            $title->chapters()->whereIn('id', $dibuang)->delete();
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
     * Resolusi judul untuk order. Keputusan diambil dari BENTUK nilai, bukan tebakan:
     *   angka          → id judul yang sudah ada
     *   berawalan new: → judul baru, namanya = sisa string setelah prefix
     *   string polos   → judul baru (kompatibilitas: old(), form lama, prefill tagihan)
     *
     * $ctx: jenis, order_type, scope_id?, indeksasi?.
     */
    public function resolveForOrder(int|string $value, array $ctx, User $actor): Title
    {
        [$id, $name] = $this->parseTitleValue($value);

        if ($id !== null) {
            $existing = Title::find($id);
            if ($existing) {
                return $existing;
            }
        }

        // Pakai ulang judul dengan nama + jenis sama (hindari duplikat & salah-taut
        // lintas jenis: order jurnal tak boleh menaut judul buku bernama sama).
        $existing = Title::where('title', $name)->where('jenis', $ctx['jenis'])->first();
        if ($existing) {
            return $existing;
        }

        return Title::create([
            'title'       => $name,
            'code'        => app(TitleCodeService::class)->generate($name),
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
     * Nama judul dari nilai form — untuk validasi panjang & cek duplikat di controller.
     * Nilai berupa id dipetakan ke nama judulnya.
     */
    public function titleNameFrom(int|string $value): string
    {
        [$id, $name] = $this->parseTitleValue($value);

        if ($id !== null) {
            return Title::find($id)?->title ?? $name;
        }

        return $name;
    }

    /**
     * @return array{0: ?int, 1: string} [id judul bila nilainya angka, nama judul]
     */
    private function parseTitleValue(int|string $value): array
    {
        $raw = trim((string) $value);

        if (str_starts_with($raw, 'new:')) {
            return [null, trim(substr($raw, 4))];
        }

        // Sengaja preg_match, bukan is_numeric(): "2026" adalah id, " 2.5e3" bukan.
        if (preg_match('/^\d+$/', $raw) === 1) {
            return [(int) $raw, $raw];
        }

        return [null, $raw];
    }

    /**
     * Perbarui informasi publikasi judul + opsi jurnal, tulis log ringkasan perubahan,
     * dan beri tahu superadmin. $data: code?, target_terbit?, jurnal_target?, jurnal_link?,
     * template_link?, apc_info?, catatan_publikasi?. $journalOptions: array of [nama_jurnal, link?, apc?].
     */
    /**
     * @param bool $opsiDikirim formulir menyatakan dirinya berwenang atas Opsi Jurnal.
     *                          Absennya kunci `journal_options` TIDAK bisa dibedakan dari
     *                          "user menghapus semua opsi" — keduanya tiba tanpa input.
     *                          Penanda inilah yang memisahkan keduanya, supaya pengirim
     *                          sebagian (layar naskah) tak menghapus opsi milik judul.
     */
    public function updateInfo(Title $title, array $data, array $journalOptions, User $actor, bool $opsiDikirim = true): void
    {
        /*
         | Field yang TIDAK dikirim tidak disentuh.
         |
         | Dulu setiap field dibaca dengan `?? null`, sehingga kunci yang absen berarti
         | "kosongkan". Itu aman selama satu-satunya pengirim adalah form judul yang
         | selalu mengirim semuanya — tapi begitu layar naskah ikut memakai endpoint ini
         | dengan sebagian field saja, menyimpan satu link akan memusnahkan Target Terbit,
         | Jurnal Target, APC, Catatan, dan seluruh Opsi Jurnal judul itu.
         |
         | `array_key_exists`, bukan `isset`/`??`: string kosong yang DIKIRIM tetap berarti
         | "kosongkan field ini", sedangkan kunci yang absen berarti "jangan sentuh".
         | Keduanya harus tetap bisa dibedakan.
         */
        $labels = [
            'code' => 'Kode', 'target_terbit' => 'Target terbit', 'jurnal_target' => 'Jurnal target',
            'jurnal_link' => 'Link jurnal', 'template_link' => 'Template', 'apc_info' => 'APC',
            'catatan_publikasi' => 'Catatan',
            'link_terbit' => 'Link terbit',
        ];

        $next = [];
        foreach (array_keys($labels) as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $next[$field] = match ($field) {
                // Kode DIKIRIM tapi kosong tetap berarti "buat ulang dari judul" — itu
                // janji tombol "Kosongkan untuk buat ulang" di formulir. Yang berubah
                // hanya arti kunci yang ABSEN, yang kini tak menyentuh kode sama sekali.
                'code' => trim((string) $data['code']) !== ''
                    ? trim((string) $data['code'])
                    : app(TitleCodeService::class)->generate($title->title, $title->id),

                // Kosong → null, bukan '': date cast mengubah '' jadi hari ini, dan
                // butuhLinkTerbit() tak boleh tertipu string kosong.
                'target_terbit', 'link_terbit' => ($data[$field] ?? '') ?: null,

                default => $data[$field] ?? null,
            };
        }

        $changed = [];
        foreach ($labels as $field => $label) {
            if (! array_key_exists($field, $next)) {
                continue; // tak dikirim → tak berubah → tak perlu masuk catatan riwayat
            }

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

        DB::transaction(function () use ($title, $next, $journalOptions, $opsiDikirim) {
            if ($next !== []) {
                $title->update($next);
            }

            if (! $opsiDikirim) {
                return;
            }

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

        $this->cerminkanLinkKeDirektori($title->fresh());

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

    /**
     * Salin link terbit judul ke Direktori Jurnal, bila barisnya SUDAH ada.
     *
     * Sengaja tidak membuat baris baru: `tb_journal_submissions.journal_id` NOT NULL dan
     * ber-FK ke direktori, jadi membuatnya menuntut pemilihan jurnal terdaftar — dan
     * jurnal yang belum terdaftar akan mengunci naskahnya dari publish. Judul tetap jadi
     * sumber kanonik; direktori menyusul lewat modulnya sendiri.
     *
     * Buku tak ikut dicerminkan: cadangannya `tb_book_isbns.link_terbit`, milik modul
     * ISBN yang punya alur pengisiannya sendiri.
     */
    private function cerminkanLinkKeDirektori(Title $title): void
    {
        $link = trim((string) $title->link_terbit);
        if ($link === '' || $title->jenis === 'buku') {
            return;
        }

        // latest('id'), sama seperti Title::linkTerbit(): created_at yang kembar di detik
        // yang sama tidak memberi urutan yang pasti, id selalu memberi.
        \App\Models\JournalSubmission::where('title_id', $title->id)
            ->latest('id')->first()?->update(['link_publish' => $link]);
    }
}
