<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashDistribution extends Model
{
    protected $table = 'tb_cash_distributions';

    protected $fillable = ['name', 'type', 'value', 'per_member', 'active', 'position'];

    protected $casts = ['value' => 'decimal:2', 'per_member' => 'boolean', 'active' => 'boolean'];

    const TYPES = ['percent' => 'Persen', 'flat' => 'Flat'];

    public function scopeActive($query) { return $query->where('active', true); }
}
