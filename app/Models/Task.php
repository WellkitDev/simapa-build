<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    use HasFactory;

    protected $table = 'tb_tasks';

    public const STATUSES = ['todo', 'in_progress', 'done'];

    /** Satu kosakata untuk papan, utas aktivitas, dan notifikasi. */
    public const STATUS_LABELS = [
        'todo'        => 'Belum mulai',
        'in_progress' => 'Dikerjakan',
        'done'        => 'Selesai',
    ];

    public static function labelStatus(?string $status): string
    {
        return self::STATUS_LABELS[$status] ?? '—';
    }
    public const PRIORITIES = ['low', 'normal', 'high'];

    protected $fillable = [
        'user_id', 'title', 'description', 'status', 'priority', 'progress',
        'due_date', 'position', 'completed_at', 'deadline_notified_at', 'deadline_stage', 'created_by',
    ];

    protected $casts = [
        'due_date'             => 'date',
        'completed_at'         => 'datetime',
        'deadline_notified_at' => 'datetime',
        'position'             => 'integer',
        'progress'             => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Utas aktivitas: laporan orang dan peristiwa sistem, urut waktu.
     *
     * Diurut `id`, bukan `created_at`: beberapa entri bisa lahir dalam detik yang sama
     * (mis. "status berubah" lalu "selesai"), dan urutan sebenarnya cuma terbaca dari
     * urutan penyisipannya.
     */
    public function updates()
    {
        return $this->hasMany(TaskUpdate::class)->orderBy('id');
    }

    /** Laporan manusia saja — dipakai menghitung "sudah dilaporkan atau belum". */
    public function laporan()
    {
        return $this->hasMany(TaskUpdate::class)->where('kind', TaskUpdate::LAPORAN);
    }

    /**
     * Boleh MEMBACA dan MENGERJAKAN: pelaksana, pemberi tugas, atau manager.
     *
     * Menggerakkan status dan menulis laporan masuk ke sini — itu pekerjaannya.
     */
    public function bolehDibaca(?User $aktor): bool
    {
        if (! $aktor) {
            return false;
        }

        return $aktor->hasAnyRole(['manager', 'superadmin'])
            || $this->user_id === $aktor->id
            || (int) $this->created_by === $aktor->id;
    }

    /**
     * Boleh MENGUBAH SYARATNYA: judul, deskripsi, prioritas, tenggat, penerima, hapus.
     *
     * Pelaksana sengaja TIDAK termasuk. Syarat tugas adalah milik yang memberi;
     * membiarkan penerimanya mengubah syaratnya sendiri membuat penugasan kehilangan
     * artinya — siapa pun bisa menurunkan prioritas atau menggeser tenggat pekerjaannya
     * sendiri tanpa seorang pun tahu.
     *
     * KECUALI tugas tanpa pembuat (`created_by` null, data lama): tak ada orang lain
     * yang bisa mengklaimnya, dan di produksi tak ada satu pun akun manager — jadi
     * memerlukan pembuat berarti mengunci tugas itu selamanya.
     */
    public function bolehDikelola(?User $aktor): bool
    {
        if (! $aktor) {
            return false;
        }

        if ($aktor->hasAnyRole(['manager', 'superadmin'])) {
            return true;
        }

        return $this->created_by === null
            ? $this->user_id === $aktor->id
            : (int) $this->created_by === $aktor->id;
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
