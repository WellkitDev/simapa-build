<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    protected $table = 'tb_idempotency_keys';

    /** Baris klaim tidak pernah di-update; hanya butuh created_at (default DB). */
    public $timestamps = false;

    protected $fillable = ['key', 'user_id', 'method', 'path'];

    protected $casts = ['created_at' => 'datetime'];

    /** Klaim lebih tua dari $hours jam (untuk prune). */
    public function scopeStale($query, int $hours = 24)
    {
        return $query->where('created_at', '<', now()->subHours($hours));
    }
}
