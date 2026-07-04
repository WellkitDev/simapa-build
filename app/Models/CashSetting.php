<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashSetting extends Model
{
    protected $table = 'tb_cash_settings';

    protected $fillable = ['saldo_awal', 'tanggal_awal', 'updated_by', 'team_members', 'target_operasional', 'target_order'];

    protected $casts = ['saldo_awal' => 'decimal:2', 'tanggal_awal' => 'date', 'team_members' => 'integer', 'target_operasional' => 'decimal:2', 'target_order' => 'decimal:2'];

    /** Baris tunggal setelan kas (dibuat bila belum ada). */
    public static function singleton(): self
    {
        return static::firstOrCreate([], ['saldo_awal' => 0, 'team_members' => 8, 'target_operasional' => 0, 'target_order' => 0]);
    }
}
