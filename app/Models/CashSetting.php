<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashSetting extends Model
{
    protected $table = 'tb_cash_settings';

    protected $fillable = ['saldo_awal', 'tanggal_awal', 'updated_by', 'team_members'];

    protected $casts = ['saldo_awal' => 'decimal:2', 'tanggal_awal' => 'date', 'team_members' => 'integer'];

    /** Baris tunggal setelan kas (dibuat bila belum ada). */
    public static function singleton(): self
    {
        return static::firstOrCreate([]);
    }
}
