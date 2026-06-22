<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketingTarget extends Model
{
    use HasFactory;

    protected $table = 'tb_marketing_targets';

    protected $fillable = [
        'user_id', 'start_date', 'end_date', 'target_amount', 'commission_rate',
        'batch_id', 'commission_paid', 'commission_paid_at', 'commission_paid_by',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'start_date'         => 'date',
        'end_date'           => 'date',
        'target_amount'      => 'integer',
        'commission_rate'    => 'decimal:2',
        'commission_paid'    => 'boolean',
        'commission_paid_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function paidBy()
    {
        return $this->belongsTo(User::class, 'commission_paid_by');
    }
}
