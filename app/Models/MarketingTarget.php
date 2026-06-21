<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MarketingTarget extends Model
{
    use HasFactory;

    protected $table = 'tb_marketing_targets';

    protected $fillable = [
        'user_id', 'year', 'month', 'target_amount', 'commission_rate', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'year'            => 'integer',
        'month'           => 'integer',
        'target_amount'   => 'integer',
        'commission_rate' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
