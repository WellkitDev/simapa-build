<?php

namespace App\Observers;

use App\Models\CashEntry;
use App\Models\CashLog;
use App\Services\CashPeriodService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class CashEntryObserver
{
    /** Field yang bermakna dicatat (sisanya derau: kode, timestamps). */
    private const FIELDS = ['tanggal', 'jenis', 'amount', 'keterangan', 'account_id', 'cash_category_id'];

    public function created(CashEntry $entry): void
    {
        $this->log($entry, 'created', $this->snapshot($entry));
    }

    public function updated(CashEntry $entry): void
    {
        $changed = array_intersect_key($entry->getChanges(), array_flip(self::FIELDS));
        if (empty($changed)) {
            return; // hanya updated_at/kode → bukan perubahan bermakna
        }

        $before = [];
        foreach (array_keys($changed) as $field) {
            $before[$field] = $entry->getOriginal($field);
        }

        $this->log($entry, 'updated', ['before' => $before, 'after' => $changed]);
    }

    public function deleted(CashEntry $entry): void
    {
        $this->log($entry, 'deleted', $this->snapshot($entry));
    }

    private function snapshot(CashEntry $entry): array
    {
        return array_intersect_key($entry->getAttributes(), array_flip(self::FIELDS));
    }

    /**
     * Catatan "periode terkunci" menandai perubahan yang lolos kunci — satu-satunya
     * jalur yang bisa mengubah bulan beku adalah sinkron payment (disengaja, spec
     * §Keputusan). Kompromi itu harus terlihat, bukan tersembunyi.
     */
    private function log(CashEntry $entry, string $action, array $changes): void
    {
        $note = null;
        if ($entry->tanggal) {
            $d = $entry->tanggal instanceof \DateTimeInterface ? $entry->tanggal : Carbon::parse($entry->tanggal);
            if (app(CashPeriodService::class)->isLocked((int) $d->format('Y'), (int) $d->format('n'))) {
                $note = 'periode terkunci';
            }
        }

        CashLog::create([
            'cash_entry_id' => $entry->id,
            'action'        => $action,
            'user_id'       => Auth::id(),
            'changes'       => $changes,
            'note'          => $note,
        ]);
    }
}
