<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashCategory extends Model
{
    protected $table = 'tb_cash_categories';

    protected $fillable = ['name', 'jenis', 'active', 'position'];

    protected $casts = ['active' => 'boolean'];

    const JENIS = ['pemasukan' => 'Pemasukan', 'pengeluaran' => 'Pengeluaran'];

    public function scopeActive($query) { return $query->where('active', true); }

    public function entries() { return $this->hasMany(CashEntry::class); }
}
