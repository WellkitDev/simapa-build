<?php

namespace App\Console\Commands;

use App\Models\IdempotencyKey;
use Illuminate\Console\Command;

class PruneIdempotencyKeys extends Command
{
    protected $signature = 'idempotency:prune {--hours=24 : Umur maksimum klaim dalam jam}';

    protected $description = 'Hapus idempotency key yang lebih tua dari N jam (default 24).';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $deleted = IdempotencyKey::stale($hours)->delete();
        $this->info("Menghapus {$deleted} idempotency key kedaluwarsa (> {$hours} jam).");

        return self::SUCCESS;
    }
}
