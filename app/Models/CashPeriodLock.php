<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashPeriodLock extends Model
{
    protected $table = 'tb_cash_period_locks';

    protected $fillable = ['year', 'month', 'locked_by', 'locked_at'];

    protected $casts = ['year' => 'integer', 'month' => 'integer', 'locked_at' => 'datetime'];

    public function lockedBy()
    {
        return $this->belongsTo(User::class, 'locked_by');
    }
}
