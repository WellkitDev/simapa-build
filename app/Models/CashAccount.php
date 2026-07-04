<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashAccount extends Model
{
    protected $table = 'tb_cash_accounts';

    protected $fillable = ['name', 'purpose', 'opening_balance', 'is_income_default', 'active', 'position'];

    protected $casts = ['opening_balance' => 'decimal:2', 'is_income_default' => 'boolean', 'active' => 'boolean'];

    const PURPOSES = ['pemasukan' => 'Pemasukan', 'operational' => 'Operational', 'harta' => 'Harta', 'umum' => 'Umum'];

    public function scopeActive($query) { return $query->where('active', true); }

    public function entries() { return $this->hasMany(CashEntry::class, 'account_id'); }

    public static function incomeDefault(): ?self
    {
        return static::where('is_income_default', true)->first() ?? static::orderBy('position')->first();
    }

    public static function totalOpening(): float
    {
        return (float) static::sum('opening_balance');
    }
}
