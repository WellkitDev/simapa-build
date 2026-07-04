<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashFixedExpense extends Model
{
    protected $table = 'tb_cash_fixed_expenses';

    protected $fillable = ['name', 'period', 'amount', 'note', 'active', 'position'];

    protected $casts = ['amount' => 'decimal:2', 'active' => 'boolean'];

    const PERIODS = ['bulanan' => 'Bulanan', 'tahunan' => 'Tahunan'];

    public function monthlyAmount(): float
    {
        return $this->period === 'tahunan' ? (float) $this->amount / 12 : (float) $this->amount;
    }
}
