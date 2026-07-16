<?php

namespace App\Services;

use App\Exceptions\CashEntryGuardException;
use App\Models\CashLog;
use App\Models\CashPeriodLock;
use App\Models\User;
use Carbon\Carbon;

class CashPeriodService
{
    public function isLocked(int $year, int $month): bool
    {
        return CashPeriodLock::where('year', $year)->where('month', $month)->exists();
    }

    /** @param string|\DateTimeInterface|null $tanggal */
    public function assertUnlocked($tanggal): void
    {
        if (! $tanggal) {
            return;
        }
        $d = Carbon::parse($tanggal);

        if ($this->isLocked((int) $d->year, (int) $d->month)) {
            throw CashEntryGuardException::periodLocked((int) $d->year, (int) $d->month);
        }
    }

    public function lock(int $year, int $month, ?User $actor): void
    {
        CashPeriodLock::firstOrCreate(
            ['year' => $year, 'month' => $month],
            ['locked_by' => $actor?->id, 'locked_at' => now()]
        );

        CashLog::create([
            'action'  => 'locked',
            'user_id' => $actor?->id,
            'note'    => "Periode {$month}/{$year}",
        ]);
    }

    public function unlock(int $year, int $month, ?User $actor): void
    {
        CashPeriodLock::where('year', $year)->where('month', $month)->delete();

        CashLog::create([
            'action'  => 'unlocked',
            'user_id' => $actor?->id,
            'note'    => "Periode {$month}/{$year}",
        ]);
    }

    public function lockFor(int $year, int $month): ?CashPeriodLock
    {
        return CashPeriodLock::with('lockedBy')->where('year', $year)->where('month', $month)->first();
    }
}
