<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashLog extends Model
{
    protected $table = 'tb_cash_logs';

    protected $fillable = ['cash_entry_id', 'action', 'user_id', 'changes', 'note'];

    protected $casts = ['changes' => 'array'];

    public const ACTIONS = [
        'created'  => 'Dibuat',
        'updated'  => 'Diubah',
        'deleted'  => 'Dihapus',
        'locked'   => 'Periode dikunci',
        'unlocked' => 'Periode dibuka',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }

    /** Pelaku; null = perubahan otomatis tanpa user login (mis. sinkron dari console). */
    public function actorName(): string
    {
        return $this->user?->name ?? 'sistem';
    }
}
