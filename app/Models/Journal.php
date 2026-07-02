<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Journal extends Model
{
    use HasFactory;

    protected $table = 'tb_journals';

    public const MONTHS = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
        7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];

    protected $fillable = [
        'nama', 'akreditasi', 'scope_id', 'apc_reguler', 'apc_fastrack',
        'link', 'kontak_wa', 'kontak_email', 'terbitan_bulan', 'catatan', 'created_by',
    ];

    protected $casts = ['terbitan_bulan' => 'array'];

    public function scope()
    {
        return $this->belongsTo(Scope::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Nama bulan terbitan, terurut. */
    public function terbitanLabels(): array
    {
        return collect($this->terbitan_bulan ?? [])
            ->map(fn ($m) => (int) $m)
            ->sort()
            ->map(fn ($m) => self::MONTHS[$m] ?? $m)
            ->values()
            ->all();
    }
}
