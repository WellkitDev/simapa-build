<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu berkas yang dilampirkan pada sebuah tugas, oleh salah satu dari dua pihak.
 */
class TaskFile extends Model
{
    protected $table = 'tb_task_files';

    protected $fillable = ['task_id', 'drive_file_id', 'name', 'url', 'mime', 'size', 'uploaded_by'];

    protected $casts = ['size' => 'integer'];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Yang mengunggah boleh mencabut; pihak seberang tidak.
     *
     * Kedua pihak boleh MELAMPIRKAN — itu bagian dari mengerjakan tugas. Tapi mencabut
     * berkas orang lain adalah menghapus pekerjaannya, dan itu urusan yang berbeda.
     * Pengawas tetap bisa, seperti di tempat lain di aplikasi ini.
     */
    public function bolehDihapus(?User $aktor): bool
    {
        if (! $aktor) {
            return false;
        }

        return $aktor->hasAnyRole(['manager', 'superadmin'])
            || (int) $this->uploaded_by === $aktor->id;
    }

    /** Nama pengunggah, atau "—" bila akunnya sudah tak ada. */
    public function pelaku(): string
    {
        return $this->uploader?->name ?? '—';
    }
}
