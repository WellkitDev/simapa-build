<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu entri di utas aktivitas sebuah tugas.
 *
 * Dua jenis dalam satu tabel: `laporan` ditulis orang, `sistem` dicatat aplikasi.
 * Keduanya dibaca sebagai satu kolom kronologis — itulah gunanya disatukan.
 */
class TaskUpdate extends Model
{
    protected $table = 'tb_task_updates';

    /** Append-only: entri yang sudah tercatat tak pernah disunting. */
    public const UPDATED_AT = null;

    public const LAPORAN = 'laporan';
    public const SISTEM  = 'sistem';

    protected $fillable = ['task_id', 'user_id', 'kind', 'body', 'progress', 'created_at'];

    protected $casts = [
        'progress'   => 'integer',
        'created_at' => 'datetime',
    ];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isSistem(): bool
    {
        return $this->kind === self::SISTEM;
    }

    /**
     * Nama pelaku, atau "Sistem" bila entrinya lahir dari penjadwal.
     *
     * Menuliskan "—" untuk pelaku yang tak ada membuat orang bertanya siapa; menuliskan
     * "Sistem" menjawabnya.
     */
    public function pelaku(): string
    {
        return $this->author?->name ?? 'Sistem';
    }
}
