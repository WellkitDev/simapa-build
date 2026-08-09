<?php
// app/Services/TitleProgressService.php

namespace App\Services;

use App\Models\ChapterProgress;
use App\Models\OrderDetail;
use App\Models\User;
use App\Models\TitleProgress;
use App\Models\TitleProgressLog;
use App\Services\Concerns\AuthorizesNaskah;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TitleProgressService
{
    use AuthorizesNaskah;

    // ─────────────────────────────────────────────────────────────────────────
    // Penugasan Naskah v2: maju SATU langkah (advance) vs koreksi (correct).
    // Jalur lama changeStatus()/changeGroupStatus() di bawah masih melayani modul
    // Distribusi Artikel/Buku sampai cutover — sengaja tidak diubah.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Selesaikan tahap berjalan: target SELALU tahap berikutnya. Tidak ada dropdown
     * semua-tahap — mundur/lompat adalah wewenang correct(). Catatan opsional.
     *
     * @return int jumlah order sejudul yang benar-benar berpindah (0 = tak ada perubahan)
     */
    public function advance(TitleProgress $progress, User $actor, ?string $note = null): int
    {
        $this->requirePermission($actor, 'naskah.advance');
        $this->requireBidang($actor, $progress->bidang);

        $next = $progress->nextStage();
        if ($next === null) {
            throw ValidationException::withMessages(['status' => 'Naskah sudah berada di tahap akhir.']);
        }

        $this->assertLayoutUnlocked($progress, $next);

        return $this->applyGroup($progress, $next, $actor, $note, false, 'status_advanced');
    }

    /**
     * Koreksi tahap (mundur atau lompat), termasuk membuka naskah yang sudah final.
     * Hanya superadmin — `naskah.correct` tidak dihibahkan ke role mana pun dan
     * superadmin lolos lewat Gate::before. Catatan WAJIB.
     *
     * @return int jumlah order terdampak (0 = target sama dengan tahap sekarang)
     */
    public function correct(TitleProgress $progress, string $target, User $actor, string $note): int
    {
        $this->requirePermission($actor, 'naskah.correct');

        if (! $progress->isValidStatus($target)) {
            throw ValidationException::withMessages(['status' => 'Tahap tidak valid untuk jenis naskah ini.']);
        }

        if (trim($note) === '') {
            throw ValidationException::withMessages(['note' => 'Catatan wajib diisi untuk koreksi tahap.']);
        }

        // Submit tanpa perubahan bukan kesalahan — pemanggil menampilkan info ramah.
        if ($target === $progress->status) {
            return 0;
        }

        return $this->applyGroup($progress, $target, $actor, $note, true, 'status_corrected');
    }

    /**
     * Satu-satunya perpindahan otomatis: upload naskah masuk.
     *  - tahap `pembuatan` + diunggah pelaksananya → `editing` (bukti kerja selesai);
     *  - tahap `menunggu_proses` → langsung `editing`, melewati `pembuatan`, karena
     *    naskahnya datang dari klien (order tanpa jasa penulisan).
     *
     * @return int jumlah order terdampak (0 = tidak memicu apa pun)
     */
    public function autoAdvanceOnUpload(TitleProgress $progress, User $uploader, string $slot): int
    {
        if ($slot !== 'masuk') {
            return 0;
        }

        $memicu = match ($progress->status) {
            'pembuatan'       => (int) $progress->pelaksana_user_id === (int) $uploader->id,
            'menunggu_proses' => true,
            default           => false,
        };

        if (! $memicu) {
            return 0;
        }

        $affected = $this->applyGroup($progress, 'editing', $uploader, null, false, 'auto_advance_upload');

        if ($affected > 0) {
            app(Notifier::class)->naskahAutoAdvanced($progress->fresh(), $uploader);
        }

        return $affected;
    }

    /**
     * Selesaikan tahap satu bab (buku kolaborasi). Bab punya alurnya sendiri
     * (Menunggu → Pembuatan → Editing → Selesai); status buku menyusul lewat roll-up.
     *
     * @return string tahap baru bab
     */
    public function advanceChapter(ChapterProgress $chapter, User $actor, ?string $note = null): string
    {
        $this->requirePermission($actor, 'naskah.advance');
        $this->requireBidang($actor, 'buku');

        $next = $chapter->nextStage();
        if ($next === null) {
            throw ValidationException::withMessages(['status' => 'Bab ini sudah Selesai.']);
        }

        $this->applyChapterStatus($chapter, $next, $actor, $note, 'status_advanced');

        return $next;
    }

    /** Upload naskah bab oleh pelaksananya pada tahap Pembuatan → bab maju ke Editing. */
    public function autoAdvanceChapterOnUpload(ChapterProgress $chapter, User $uploader, string $slot): bool
    {
        if ($slot !== 'masuk' || $chapter->status !== 'pembuatan') {
            return false;
        }

        if ((int) $chapter->pelaksana_user_id !== (int) $uploader->id) {
            return false;
        }

        $this->applyChapterStatus($chapter, 'editing', $uploader, null, 'auto_advance_upload');

        return true;
    }

    private function applyChapterStatus(ChapterProgress $chapter, string $target, User $actor, ?string $note, string $event): void
    {
        $from = $chapter->status;

        DB::transaction(function () use ($chapter, $target, $actor, $note, $event, $from) {
            $chapter->update([
                'status'      => $target,
                'note'        => $note,
                'updated_by'  => $actor->id,
                'started_at'  => now(),
                'last_log_at' => now(),
                // SLA hanya bermakna selama bab dibuat.
                'sla_due_at'  => $target === 'pembuatan' ? $chapter->sla_due_at : null,
            ]);

            $this->logChapter($chapter, $event, $from, $target, $actor, $note);
        });

        if ($book = $chapter->chapter?->title) {
            app(ChapterRollupService::class)->recalc($book, $actor);
        }
    }

    /** Riwayat bab menempel pada TitleProgress buku — satu linimasa per naskah. */
    private function logChapter(ChapterProgress $chapter, string $event, string $from, string $to, User $actor, ?string $note): void
    {
        $progress = $chapter->chapter?->title?->orderDetails()->with('titleProgress')->get()
            ->map->titleProgress->filter()->first();

        if (! $progress) {
            return;
        }

        $this->log(
            $progress,
            $event,
            ChapterProgress::labelFor($from),
            $chapter->chapter->judul . ': ' . ChapterProgress::labelFor($to),
            $actor,
            $note
        );
    }

    /**
     * Buku kolaborasi: Layout→Terbit berjalan level buku dan baru terbuka setelah
     * SELURUH bab Selesai. Gerbang ini yang membuat tombol "Mulai Layout" bermakna.
     */
    private function assertLayoutUnlocked(TitleProgress $progress, string $next): void
    {
        if ($next !== 'layout') {
            return;
        }

        $book = $progress->orderDetail?->titleRef;
        if (! $book) {
            return;
        }

        $rollup = app(ChapterRollupService::class);
        if ($rollup->isCollaborative($book) && ! $rollup->chaptersDone($book)) {
            throw ValidationException::withMessages([
                'status' => 'Semua bab harus Selesai dulu sebelum masuk tahap Layout.',
            ]);
        }
    }

    /**
     * Terapkan tahap ke SELURUH order sejudul dalam satu transaksi.
     * Maju: hanya order yang masih tertinggal di belakang target ikut bergerak.
     * Koreksi: seluruh order disinkronkan ke target, termasuk yang sudah di depan.
     */
    private function applyGroup(
        TitleProgress $progress,
        string $target,
        User $actor,
        ?string $note,
        bool $isCorrection,
        string $event
    ): int {
        $group     = $this->groupOf($progress);
        $stages    = $progress->getStages();
        $targetIdx = array_search($target, $stages, true);
        $changed   = [];

        DB::transaction(function () use ($group, $stages, $target, $targetIdx, $actor, $note, $isCorrection, $event, &$changed) {
            foreach ($group as $p) {
                $idx = array_search($p->status, $stages, true);
                if ($p->status === $target) {
                    continue;
                }
                if (! $isCorrection && $idx !== false && $idx > $targetIdx) {
                    continue; // sudah lebih maju dari target
                }

                $from = $p->status;
                $this->applyStatus($p, $from, $target, $actor, $note, $isCorrection, $event);
                $this->syncArchiveFlag($p, $target, $actor);
                $changed[] = [$p, $from];
            }
        });

        foreach ($changed as [$p, $from]) {
            app(Notifier::class)->naskahStageChanged($p, $actor, $from, $target);
        }

        return count($changed);
    }

    /**
     * Naskah selesai pindah ke Arsip, tidak lenyap dari sistem. Koreksi mundur oleh
     * superadmin mengembalikannya ke papan.
     */
    private function syncArchiveFlag(TitleProgress $progress, string $target, User $actor): void
    {
        if (TitleProgress::isFinal($target)) {
            if ($progress->archived_at === null) {
                $progress->update(['archived_at' => now()]);
                $this->log($progress, 'diarsipkan', TitleProgress::labelFor($target), 'Arsip', $actor);
            }

            return;
        }

        if ($progress->archived_at !== null) {
            $progress->update(['archived_at' => null]);
        }
    }

    /** @return Collection<int,TitleProgress> */
    private function groupOf(TitleProgress $progress): Collection
    {
        $key = $progress->orderDetail?->group_key;
        if ($key === null) {
            return collect([$progress]);
        }

        return TitleProgress::with('orderDetail')
            ->whereHas('orderDetail', fn ($q) => $q->where('group_key', $key))
            ->get();
    }

    /**
     * Maju 1 langkah (advance) atau koreksi (superadmin). Menulis log.
     */
    public function changeStatus(TitleProgress $progress, string $target, User $actor, ?string $note = null): TitleProgress
    {
        if (!$progress->isValidStatus($target)) {
            throw ValidationException::withMessages(['status' => 'Status tidak valid untuk tipe naskah ini.']);
        }

        // Tahap akhir bersifat terminal untuk SEMUA role (termasuk superadmin) —
        // konsisten dengan perilaku controller sebelumnya. Koreksi naskah yang sudah
        // terbit/publish di luar cakupan fitur ini.
        $next = $progress->getNextStatus();
        if ($next === null) {
            throw ValidationException::withMessages(['status' => 'Naskah sudah berada di tahap akhir.']);
        }

        $current      = $progress->status;
        $isCorrection = ($target !== $next);

        $this->authorizeChange($actor, $current);

        if ($isCorrection && trim((string) $note) === '') {
            throw ValidationException::withMessages(['note' => 'Catatan wajib diisi untuk koreksi/lompat status.']);
        }

        $result = DB::transaction(fn () => $this->applyStatus($progress, $current, $target, $actor, $note, $isCorrection));

        app(Notifier::class)->naskahStageChanged($result, $actor, $current, $target);

        return $result;
    }

    /**
     * Tulis perubahan status + log untuk satu progress (tanpa validasi — pemanggil
     * yang memvalidasi). `$event` boleh ditimpa untuk perpindahan bernama khusus
     * (mis. `auto_advance_upload`) supaya riwayat menyebut sebabnya, bukan sekadar
     * "maju tahap".
     */
    private function applyStatus(TitleProgress $progress, string $from, string $target, User $actor, ?string $note, bool $isCorrection, ?string $event = null): TitleProgress
    {
        $progress->update([
            'status'        => $target,
            'assigned_role' => TitleProgress::getHandlerForStatus($target),
            'note'          => $note,
            'updated_by'    => $actor->id,
            'started_at'    => now(),
        ]);

        $this->log(
            $progress,
            $event ?? ($isCorrection ? 'status_corrected' : 'status_advanced'),
            Str::title(str_replace('_', ' ', $from)),
            Str::title(str_replace('_', ' ', $target)),
            $actor,
            $note,
            $isCorrection
        );

        return $progress;
    }

    /** Catat satu entri aktivitas + perbarui penanda aktivitas terbaru pada progress. */
    private function log(TitleProgress $progress, string $event, ?string $from, ?string $to, User $actor, ?string $note = null, bool $isCorrection = false): void
    {
        TitleProgressLog::create([
            'title_progress_id' => $progress->id,
            'event'             => $event,
            'from_value'        => $from,
            'to_value'          => $to,
            'changed_by'        => $actor->id,
            'note'              => $note,
            'is_correction'     => $isCorrection,
        ]);

        $progress->update(['last_log_at' => now()]);
    }

    /**
     * Gerbang role untuk memindahkan kartu. Sama untuk maju maupun koreksi/lompat —
     * koreksi oleh non-superadmin diizinkan tetapi wajib catatan & ditandai perlu ditinjau
     * (lihat changeStatus/changeGroupStatus + applyStatus).
     */
    private function authorizeChange(User $actor, string $current): void
    {
        if ($actor->hasRole('superadmin')) {
            return; // bebas: maju, mundur, lompat
        }
        if (TitleProgress::isFinal($current)) {
            throw new AuthorizationException('Naskah sudah final dan terkunci.');
        }
        if ($actor->hasRole('manager')) {
            return; // oversight: stage apa pun (non-final)
        }
        if ($actor->hasAnyRole(['production', 'admin']) && TitleProgress::getHandlerForStatus($current) === 'production') {
            return; // hanya kartu yang sedang jadi domain production
        }
        throw new AuthorizationException('Anda tidak berhak memindahkan naskah pada tahap ini.');
    }

    public function assignEditor(TitleProgress $progress, ?int $userId, User $actor): TitleProgress
    {
        if (!$actor->hasAnyRole(['production', 'manager', 'superadmin', 'admin'])) {
            throw new AuthorizationException();
        }

        $assignee = null;
        if ($userId !== null) {
            $assignee = User::find($userId);
            if (!$assignee || !$assignee->hasAnyRole(['production', 'manager', 'admin'])) {
                throw ValidationException::withMessages([
                    'assigned_user_id' => 'Editor harus user dengan role production, manager, atau admin.',
                ]);
            }
        }

        if ((int) $progress->pelaksana_user_id !== (int) $userId) {
            $from = optional(User::find($progress->pelaksana_user_id))->name ?? '—';
            $progress->update(['pelaksana_user_id' => $userId]);
            $this->log($progress, 'editor_assigned', $from, optional($assignee)->name ?? '—', $actor);
        }

        return $progress;
    }

    public function setPriority(TitleProgress $progress, string $priority, User $actor): TitleProgress
    {
        if (!$actor->hasAnyRole(['production', 'manager', 'superadmin', 'admin'])) {
            throw new AuthorizationException();
        }
        if (!in_array($priority, TitleProgress::PRIORITIES, true)) {
            throw ValidationException::withMessages(['priority' => 'Prioritas tidak valid.']);
        }

        if ($progress->priority !== $priority) {
            $from = ucfirst((string) $progress->priority);
            $progress->update(['priority' => $priority]);
            $this->log($progress, 'priority_changed', $from, ucfirst($priority), $actor);
        }

        return $progress;
    }

    /** Set/clear target tanggal terbit/publish. Kosong = hapus target. Marketing juga boleh. */
    public function setTargetDate(TitleProgress $progress, ?string $date, User $actor): TitleProgress
    {
        if (! $actor->hasAnyRole(['marketing', 'production', 'manager', 'superadmin', 'admin'])) {
            throw new AuthorizationException();
        }

        $value = null;
        if ($date !== null && trim($date) !== '') {
            try {
                $value = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', trim($date))->startOfDay();
            } catch (\Throwable $e) {
                throw ValidationException::withMessages(['target_date' => 'Tanggal target tidak valid.']);
            }
        }

        $oldDate = optional($progress->target_date)->toDateString();
        $newDate = optional($value)->toDateString();
        if ($oldDate !== $newDate) {
            $progress->update(['target_date' => $value]);
            $this->log($progress, 'target_set', $oldDate ?? '—', $newDate ?? '—', $actor);
        }

        return $progress;
    }

    /**
     * Buat TitleProgress untuk detail baru. Jika sudah ada order lain berjudul sama (grup)
     * yang sedang diproses, warisi status + penugasan grup agar tetap sinkron; jika tidak,
     * mulai dari 'menunggu_proses'.
     */
    public function createForDetail(OrderDetail $detail, ?int $actorId = null): TitleProgress
    {
        $bookTypes = ['bk_mandiri', 'bk_kolab'];
        $stages = in_array($detail->type, $bookTypes, true)
            ? TitleProgress::BOOK_STAGES
            : TitleProgress::ARTICLE_STAGES;

        $sibling = OrderDetail::where('group_key', $detail->group_key)
            ->where('id', '!=', $detail->id)
            ->whereHas('titleProgress')
            ->with('titleProgress')
            ->get()
            ->map->titleProgress
            ->sortBy(fn ($p) => array_search($p->status, $stages, true))
            ->first();

        $status = $sibling->status ?? 'menunggu_proses';

        $progress = TitleProgress::create([
            'order_detail_id'  => $detail->id,
            'status'           => $status,
            'assigned_role'    => TitleProgress::getHandlerForStatus($status),
            'pelaksana_user_id' => $sibling->pelaksana_user_id ?? null,
            'pj_user_id'       => $sibling->pj_user_id ?? null,
            'bidang'           => in_array($detail->type, $bookTypes, true) ? 'buku' : 'artikel',
            'priority'         => $sibling->priority ?? 'normal',
            'updated_by'       => $actorId,
            'started_at'       => now(),
        ]);

        // Buku: pastikan bab + progress bab (roll-up). Artikel: tak ada bab.
        if (in_array($detail->type, $bookTypes, true) && $detail->title_id) {
            app(\App\Services\ChapterManuscriptService::class)->ensureChapters($detail->titleRef);
        }

        return $progress;
    }

    // ─── Aksi serempak untuk satu grup judul (semua order judul yang sama) ───

    /**
     * Majukan/koreksi status SELURUH varian dalam satu grup judul sekaligus.
     * Status kanonik grup = stage paling awal (bottleneck) di antara varian.
     * Maju: varian yang masih tertinggal dibawa ke target. Koreksi (superadmin): semua disinkron.
     */
    public function changeGroupStatus(iterable $progresses, string $target, User $actor, ?string $note = null): void
    {
        $progresses = collect($progresses);
        if ($progresses->isEmpty()) {
            return;
        }

        $stages = $progresses->first()->getStages();

        if (!in_array($target, $stages, true)) {
            throw ValidationException::withMessages(['status' => 'Status tidak valid untuk tipe naskah ini.']);
        }

        $canonical    = $progresses->sortBy(fn ($p) => array_search($p->status, $stages, true))->first()->status;
        $canonicalIdx = array_search($canonical, $stages, true);
        $next         = $stages[$canonicalIdx + 1] ?? null;

        if ($next === null && $target === $canonical) {
            throw ValidationException::withMessages(['status' => 'Naskah sudah berada di tahap akhir.']);
        }

        $isCorrection = ($target !== $next);
        $this->authorizeChange($actor, $canonical);

        if ($isCorrection && trim((string) $note) === '') {
            throw ValidationException::withMessages(['note' => 'Catatan wajib diisi untuk koreksi/lompat status.']);
        }

        $targetIdx = array_search($target, $stages, true);

        $changed = [];
        DB::transaction(function () use ($progresses, $stages, $target, $targetIdx, $actor, $note, $isCorrection, &$changed) {
            foreach ($progresses as $p) {
                $idx = array_search($p->status, $stages, true);
                // Maju: hanya varian di belakang target. Koreksi: seluruh varian.
                if (($isCorrection || $idx < $targetIdx) && $p->status !== $target) {
                    $from = $p->status;
                    $this->applyStatus($p, $from, $target, $actor, $note, $isCorrection);
                    $changed[] = [$p, $from];
                }
            }
        });

        foreach ($changed as [$p, $from]) {
            app(Notifier::class)->naskahStageChanged($p, $actor, $from, $target);
        }
        if (! empty($changed)) {
            app(Notifier::class)->distribusiChanged($changed[0][0], $actor, 'Tahap naskah diperbarui');
        }
    }

    public function assignGroup(iterable $progresses, ?int $userId, User $actor): void
    {
        DB::transaction(function () use ($progresses, $userId, $actor) {
            foreach (collect($progresses) as $p) {
                $this->assignEditor($p, $userId, $actor);
            }
        });
    }

    public function setGroupPriority(iterable $progresses, string $priority, User $actor): void
    {
        DB::transaction(function () use ($progresses, $priority, $actor) {
            foreach (collect($progresses) as $p) {
                $this->setPriority($p, $priority, $actor);
            }
        });
    }

    public function setGroupTargetDate(iterable $progresses, ?string $date, User $actor): void
    {
        DB::transaction(function () use ($progresses, $date, $actor) {
            foreach (collect($progresses) as $p) {
                $this->setTargetDate($p, $date, $actor);
            }
        });
    }

    /** Bersihkan seluruh riwayat aktivitas grup. Hanya superadmin. */
    public function clearLogs(iterable $progresses, User $actor): void
    {
        if (! $actor->hasRole('superadmin')) {
            throw new AuthorizationException();
        }

        DB::transaction(function () use ($progresses) {
            foreach (collect($progresses) as $p) {
                $p->logs()->delete();
                $p->update(['last_log_at' => null]);
            }
        });
    }
}
