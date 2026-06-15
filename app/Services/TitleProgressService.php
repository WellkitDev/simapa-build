<?php
// app/Services/TitleProgressService.php

namespace App\Services;

use App\Models\OrderDetail;
use App\Models\User;
use App\Models\TitleProgress;
use App\Models\TitleProgressLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TitleProgressService
{
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

        return DB::transaction(fn () => $this->applyStatus($progress, $current, $target, $actor, $note, $isCorrection));
    }

    /** Tulis perubahan status + log untuk satu progress (tanpa validasi — pemanggil yang memvalidasi). */
    private function applyStatus(TitleProgress $progress, string $from, string $target, User $actor, ?string $note, bool $isCorrection): TitleProgress
    {
        $progress->update([
            'status'        => $target,
            'assigned_role' => TitleProgress::getHandlerForStatus($target),
            'note'          => $note,
            'updated_by'    => $actor->id,
            'started_at'    => now(),
            // Koreksi/lompat oleh non-superadmin ditandai untuk ditinjau; aksi superadmin
            // atau langkah maju biasa membersihkan tanda.
            'needs_review'  => $isCorrection && ! $actor->hasRole('superadmin'),
        ]);

        TitleProgressLog::create([
            'title_progress_id' => $progress->id,
            'from_status'       => $from,
            'to_status'         => $target,
            'changed_by'        => $actor->id,
            'note'              => $note,
            'is_correction'     => $isCorrection,
        ]);

        return $progress;
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
        if ($actor->hasRole('manager')) {
            return; // oversight: stage apa pun
        }
        if ($actor->hasRole('production') && TitleProgress::getHandlerForStatus($current) === 'production') {
            return; // hanya kartu yang sedang jadi domain production
        }
        throw new AuthorizationException('Anda tidak berhak memindahkan naskah pada tahap ini.');
    }

    public function assignEditor(TitleProgress $progress, ?int $userId, User $actor): TitleProgress
    {
        if (!$actor->hasAnyRole(['production', 'manager', 'superadmin'])) {
            throw new AuthorizationException();
        }

        if ($userId !== null) {
            $assignee = User::find($userId);
            if (!$assignee || !$assignee->hasAnyRole(['production', 'manager'])) {
                throw ValidationException::withMessages([
                    'assigned_user_id' => 'Editor harus user dengan role production atau manager.',
                ]);
            }
        }

        $progress->update(['assigned_user_id' => $userId]);
        return $progress;
    }

    public function setPriority(TitleProgress $progress, string $priority, User $actor): TitleProgress
    {
        if (!$actor->hasAnyRole(['production', 'manager', 'superadmin'])) {
            throw new AuthorizationException();
        }
        if (!in_array($priority, TitleProgress::PRIORITIES, true)) {
            throw ValidationException::withMessages(['priority' => 'Prioritas tidak valid.']);
        }

        $progress->update(['priority' => $priority]);
        return $progress;
    }

    /** Set/clear target tanggal terbit/publish. Kosong = hapus target. Marketing juga boleh. */
    public function setTargetDate(TitleProgress $progress, ?string $date, User $actor): TitleProgress
    {
        if (! $actor->hasAnyRole(['marketing', 'production', 'manager', 'superadmin'])) {
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

        $progress->update(['target_date' => $value]);
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

        return TitleProgress::create([
            'order_detail_id'  => $detail->id,
            'status'           => $status,
            'assigned_role'    => TitleProgress::getHandlerForStatus($status),
            'assigned_user_id' => $sibling->assigned_user_id ?? null,
            'priority'         => $sibling->priority ?? 'normal',
            'updated_by'       => $actorId,
            'started_at'       => now(),
        ]);
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

        if ($next === null) {
            throw ValidationException::withMessages(['status' => 'Naskah sudah berada di tahap akhir.']);
        }

        $isCorrection = ($target !== $next);
        $this->authorizeChange($actor, $canonical);

        if ($isCorrection && trim((string) $note) === '') {
            throw ValidationException::withMessages(['note' => 'Catatan wajib diisi untuk koreksi/lompat status.']);
        }

        $targetIdx = array_search($target, $stages, true);

        DB::transaction(function () use ($progresses, $stages, $target, $targetIdx, $actor, $note, $isCorrection) {
            foreach ($progresses as $p) {
                $idx = array_search($p->status, $stages, true);
                // Maju: hanya varian di belakang target. Koreksi: seluruh varian.
                if (($isCorrection || $idx < $targetIdx) && $p->status !== $target) {
                    $this->applyStatus($p, $p->status, $target, $actor, $note, $isCorrection);
                }
            }
        });
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

    /** Superadmin/manager menandai lompatan sudah ditinjau (bersihkan flag pada seluruh grup). */
    public function markReviewed(iterable $progresses, User $actor): void
    {
        if (! $actor->hasAnyRole(['manager', 'superadmin'])) {
            throw new AuthorizationException();
        }

        DB::transaction(function () use ($progresses) {
            foreach (collect($progresses) as $p) {
                $p->update(['needs_review' => false]);
            }
        });
    }
}
