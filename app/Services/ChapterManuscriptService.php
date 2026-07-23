<?php

namespace App\Services;

use App\Models\ChapterProgress;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\TitleProgressLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChapterManuscriptService
{
    /** Pastikan buku punya daftar bab + ChapterProgress. Auto-generate dari OrderDetail.chapters bila kosong. Idempotent. */
    public function ensureChapters(Title $book): void
    {
        if ($book->jenis !== 'buku') {
            return;
        }

        $chapters = $book->chapters()->get();

        if ($chapters->isEmpty()) {
            $n = max(1, (int) $book->orderDetails()->max('chapters'));
            for ($i = 1; $i <= $n; $i++) {
                $book->chapters()->create(['judul' => 'Bab ' . $i, 'urutan' => $i]);
            }
            $chapters = $book->chapters()->get();
        }

        $seedStatus = optional(
            $book->orderDetails()->with('titleProgress')->get()
                ->map->titleProgress->filter()->first()
        )->status ?? 'menunggu_proses';

        foreach ($chapters as $chapter) {
            if (! $chapter->progress()->exists()) {
                $chapter->progress()->create(['status' => $seedStatus, 'started_at' => now()]);
            }
        }

        // Pre-fill author bab dari author order (bab kosong saja) → hindari input ulang.
        app(ChapterAuthorService::class)->seedFromOrders($book);
    }

    /** Ubah status bab (maju/koreksi) dengan aturan & otorisasi seperti TitleProgress; roll-up buku. */
    public function changeStatus(ChapterProgress $cp, string $target, User $actor, ?string $note = null): ChapterProgress
    {
        $stages = TitleProgress::BOOK_STAGES;
        if (! in_array($target, $stages, true)) {
            throw ValidationException::withMessages(['status' => 'Status tidak valid.']);
        }

        $current = $cp->status;
        $idx     = array_search($current, $stages, true);
        $next    = $idx === false ? null : ($stages[$idx + 1] ?? null);

        if ($next === null && $target === $current) {
            throw ValidationException::withMessages(['status' => 'Bab sudah di tahap akhir.']);
        }

        $isCorrection = ($target !== $next);
        $this->authorize($actor, $current);

        if ($isCorrection && trim((string) $note) === '') {
            throw ValidationException::withMessages(['note' => 'Catatan wajib untuk koreksi/lompat.']);
        }

        DB::transaction(function () use ($cp, $current, $target, $actor, $note, $isCorrection) {
            $cp->update([
                'status'       => $target,
                'note'         => $note,
                'updated_by'   => $actor->id,
                'started_at'   => now(),
                'needs_review' => $isCorrection && ! $actor->hasRole('superadmin'),
                'last_log_at'  => now(),
            ]);
            $this->log($cp, $current, $target, $actor, $note, $isCorrection);
            $this->syncBookStatus($cp->chapter->title);
        });

        return $cp;
    }

    /** Assign editor bab (production/manager). */
    public function assignEditor(ChapterProgress $cp, ?int $userId, User $actor): ChapterProgress
    {
        if (! $actor->hasAnyRole(['production', 'manager', 'superadmin', 'admin'])) {
            throw new AuthorizationException();
        }
        if ($userId !== null) {
            $u = User::find($userId);
            if (! $u || ! $u->hasAnyRole(['production', 'manager', 'admin'])) {
                throw ValidationException::withMessages(['assigned_user_id' => 'Editor harus role production, manager, atau admin.']);
            }
        }

        $cp->update(['assigned_user_id' => $userId]);
        return $cp;
    }

    /** Terapkan satu editor ke semua bab buku (pintasan distribusi). */
    public function assignEditorAll(Title $book, ?int $userId, User $actor): void
    {
        foreach ($book->chapters()->with('progress')->get() as $ch) {
            if ($ch->progress) {
                $this->assignEditor($ch->progress, $userId, $actor);
            }
        }
    }

    /** Sinkron status TitleProgress buku (tiap order-variant) = bottleneck status bab. */
    public function syncBookStatus(Title $book): void
    {
        $stages = TitleProgress::BOOK_STAGES;
        $statuses = $book->chapters()->with('progress')->get()
            ->map(fn ($c) => optional($c->progress)->status)
            ->filter();

        if ($statuses->isEmpty()) {
            return;
        }

        $bottleneck = $statuses
            ->sortBy(fn ($s) => ($i = array_search($s, $stages, true)) === false ? PHP_INT_MAX : $i)
            ->first();

        foreach ($book->orderDetails()->with('titleProgress')->get() as $detail) {
            if ($detail->titleProgress) {
                $detail->titleProgress->update([
                    'status'        => $bottleneck,
                    'assigned_role' => TitleProgress::getHandlerForStatus($bottleneck),
                ]);
            }
        }
    }

    /**
     * Majukan manuskrip buku ke tahap target (maju-saja) — dipicu registrasi ISBN.
     * Menggerakkan bab (bila ada) + TitleProgress tiap order-variant; tak pernah memundurkan.
     */
    public function advanceBookToStage(Title $book, string $target, User $actor): void
    {
        if ($book->jenis !== 'buku') {
            return;
        }
        $stages    = TitleProgress::BOOK_STAGES;
        $targetIdx = array_search($target, $stages, true);
        if ($targetIdx === false) {
            return;
        }

        $moved = false;

        // Bab (bila ada) — maju-saja, agar roll-up tak menarik mundur kelak.
        foreach ($book->chapters()->with('progress')->get() as $chapter) {
            $cp = $chapter->progress;
            if (! $cp) {
                continue;
            }
            $idx = array_search($cp->status, $stages, true);
            if ($idx === false || $idx >= $targetIdx) {
                continue;
            }
            $cp->update(['status' => $target, 'updated_by' => $actor->id, 'started_at' => now(), 'last_log_at' => now()]);
            $moved = true;
        }

        // TitleProgress tiap order-variant (sumber manuscriptStatus) — maju-saja.
        foreach ($book->orderDetails()->with('titleProgress')->get() as $detail) {
            $tp = $detail->titleProgress;
            if (! $tp) {
                continue;
            }
            $idx = array_search($tp->status, $stages, true);
            if ($idx === false || $idx >= $targetIdx) {
                continue;
            }
            $tp->update(['status' => $target, 'assigned_role' => TitleProgress::getHandlerForStatus($target)]);
            $moved = true;
        }

        if ($moved) {
            $progress = $book->orderDetails()->with('titleProgress')->get()->map->titleProgress->filter()->first();
            if ($progress) {
                TitleProgressLog::create([
                    'title_progress_id' => $progress->id,
                    'event'             => 'isbn_sync',
                    'from_value'        => 'Registrasi ISBN',
                    'to_value'          => Str::title(str_replace('_', ' ', $target)),
                    'changed_by'        => $actor->id,
                    'note'              => 'Sinkron otomatis dari registrasi ISBN.',
                    'is_correction'     => false,
                ]);
            }
        }
    }

    private function authorize(User $actor, string $current): void
    {
        if ($actor->hasRole('superadmin')) {
            return;
        }
        if (TitleProgress::isFinal($current)) {
            throw new AuthorizationException('Bab sudah final dan terkunci.');
        }
        if ($actor->hasRole('manager')) {
            return;
        }
        if ($actor->hasAnyRole(['production', 'admin']) && TitleProgress::getHandlerForStatus($current) === 'production') {
            return;
        }
        throw new AuthorizationException('Anda tidak berhak memindahkan bab pada tahap ini.');
    }

    /** Catat perubahan bab ke log manuskrip buku (TitleProgress representatif). */
    private function log(ChapterProgress $cp, string $from, string $to, User $actor, ?string $note, bool $isCorrection): void
    {
        $progress = $cp->chapter->title->orderDetails()->with('titleProgress')->get()
            ->map->titleProgress->filter()->first();
        if (! $progress) {
            return;
        }

        TitleProgressLog::create([
            'title_progress_id' => $progress->id,
            'event'             => 'chapter_status',
            'from_value'        => Str::title(str_replace('_', ' ', $from)),
            'to_value'          => "Bab '{$cp->chapter->judul}' → " . Str::title(str_replace('_', ' ', $to)),
            'changed_by'        => $actor->id,
            'note'              => $note,
            'is_correction'     => $isCorrection,
        ]);
    }
}
