<?php
// app/Services/TitleProgressService.php

namespace App\Services;

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

        $next = $progress->getNextStatus();
        if ($next === null) {
            throw ValidationException::withMessages(['status' => 'Naskah sudah berada di tahap akhir.']);
        }

        $current      = $progress->status;
        $isCorrection = ($target !== $next);

        $this->authorizeChange($actor, $current, $isCorrection);

        if ($isCorrection && trim((string) $note) === '') {
            throw ValidationException::withMessages(['note' => 'Catatan wajib diisi untuk koreksi status.']);
        }

        return DB::transaction(function () use ($progress, $target, $current, $actor, $note, $isCorrection) {
            $progress->update([
                'status'        => $target,
                'assigned_role' => TitleProgress::getHandlerForStatus($target),
                'note'          => $note,
                'updated_by'    => $actor->id,
                'started_at'    => now(),
            ]);

            TitleProgressLog::create([
                'title_progress_id' => $progress->id,
                'from_status'       => $current,
                'to_status'         => $target,
                'changed_by'        => $actor->id,
                'note'              => $note,
                'is_correction'     => $isCorrection,
            ]);

            return $progress;
        });
    }

    private function authorizeChange(User $actor, string $current, bool $isCorrection): void
    {
        if ($actor->hasRole('superadmin')) {
            return; // bebas: maju, mundur, lompat
        }
        if ($isCorrection) {
            throw new AuthorizationException('Hanya superadmin yang dapat melakukan koreksi.');
        }
        if ($actor->hasRole('manager')) {
            return; // maju stage apa pun
        }
        if ($actor->hasRole('production') && TitleProgress::getHandlerForStatus($current) === 'production') {
            return; // maju stage yang sedang jadi domain production
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
}
