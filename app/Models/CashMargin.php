<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashMargin extends Model
{
    protected $table = 'tb_cash_margins';

    protected $fillable = ['code', 'label', 'margin_pct', 'active', 'position'];

    protected $casts = ['margin_pct' => 'decimal:2', 'active' => 'boolean'];

    public function scopeActive($query) { return $query->where('active', true); }
}
