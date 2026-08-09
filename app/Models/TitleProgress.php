<?php
// app/Models/TitleProgress.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class TitleProgress extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'tb_title_progress';

    protected $fillable = [
        'order_detail_id', 'status', 'assigned_role',
        'note', 'updated_by', 'started_at',
        'pelaksana_user_id', 'priority', 'needs_review', 'target_date', 'last_log_at',
        'pj_user_id', 'bidang', 'sla_due_at', 'overdue_reason', 'overdue_note',
        'is_on_hold', 'chapters_done', 'archived_at',
        'cancelled_at', 'cancelled_by', 'cancel_reason',
    ];

    // CATATAN: `protected $dates` sudah TIDAK berlaku sejak Laravel 10 — properti itu
    // dulu ada di sini untuk `started_at` dan diam-diam tak berefek, sehingga kolomnya
    // kembali sebagai string. Semua kolom waktu kini dideklarasikan di $casts.
    protected $casts = [
        'needs_review'  => 'boolean',
        'started_at'    => 'datetime',
        'target_date'   => 'date',
        'last_log_at'   => 'datetime',
        'sla_due_at'    => 'date',
        'is_on_hold'    => 'boolean',
        'chapters_done' => 'boolean',
        'archived_at'   => 'datetime',
        'cancelled_at'  => 'datetime',
    ];

    const ARTICLE_STAGES = [
        'menunggu_proses', 'pembuatan', 'editing',
        'revisi', 'submit', 'loa', 'publish',
    ];

    const BOOK_STAGES = [
        'menunggu_proses', 'pembuatan', 'editing', 'layout',
        'proofreading', 'isbn', 'cetak', 'terbit',
    ];

    /**
     * Status lama → status baru. Dipakai command naskah:migrate-v2 dan sebagai guard
     * tampilan untuk baris lama yang belum termigrasi ('templating' dihapus dari alur).
     */
    const LEGACY_STAGE_MAP = [
        'templating' => 'editing',
    ];

    /**
     * Handler per tahap. 'pembuatan' = satu-satunya tahap milik pelaksana (production).
     * CATATAN TRANSISI: pemetaan penuh editing..terbit → 'admin' menyusul bersama
     * refactor advance()/correct() (otorisasi berbasis permission naskah.*) — diubah
     * sekarang akan mengunci modul distribusi lama yang masih memakai gerbang
     * handler === 'production'.
     */
    const STAGE_HANDLER = [
        'menunggu_proses' => 'marketing',
        'pembuatan'       => 'production',
        'editing'         => 'production',
        'revisi'          => 'production',
        'submit'          => 'production',
        'loa'             => 'superadmin',
        'publish'         => 'superadmin',
        'layout'          => 'production',
        'proofreading'    => 'production',
        'isbn'            => 'production',
        'cetak'           => 'superadmin',
        'terbit'          => 'superadmin',
    ];

    /** Label tahap Bahasa Indonesia (identitas UI modul Penugasan Naskah). */
    const STAGE_LABELS_ID = [
        'menunggu_proses' => 'Menunggu Proses',
        'pembuatan'       => 'Pembuatan Naskah',
        'editing'         => 'Editing',
        'revisi'          => 'Revisi',
        'submit'          => 'Submit',
        'loa'             => 'LoA',
        'publish'         => 'Publish',
        'layout'          => 'Layout',
        'proofreading'    => 'Proofreading',
        'isbn'            => 'ISBN',
        'cetak'           => 'Cetak',
        'terbit'          => 'Terbit',
    ];

    const PRIORITIES = ['low', 'normal', 'high'];

    const FINAL_STAGES = ['terbit', 'publish'];

    const OVERDUE_REASONS = ['internal', 'eksternal', 'lainnya'];

    const BOARD_RETENTION_DAYS = 30;

    /** Daftar status yang handler-nya production (diturunkan dari STAGE_HANDLER). */
    public static function productionStages(): array
    {
        return array_keys(array_filter(self::STAGE_HANDLER, fn ($role) => $role === 'production'));
    }

    public static function isFinal(string $status): bool
    {
        return in_array($status, self::FINAL_STAGES, true);
    }

    /** Label Indonesia untuk satu status; status lama tak dikenal → Title Case apa adanya. */
    public static function labelFor(?string $status): string
    {
        if ($status === null) {
            return '—';
        }

        return self::STAGE_LABELS_ID[$status] ?? Str::title(str_replace('_', ' ', $status));
    }

    public function stageLabelId(): string
    {
        return self::labelFor($this->status);
    }

    // ─── Relasi ───

    public function orderDetail()
    {
        return $this->belongsTo(OrderDetail::class);
    }

    public function logs()
    {
        return $this->hasMany(TitleProgressLog::class);
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Penanggung Jawab — admin per bidang. */
    public function pj()
    {
        return $this->belongsTo(User::class, 'pj_user_id');
    }

    /** Pelaksana pembuatan naskah — akun production. */
    public function pelaksana()
    {
        return $this->belongsTo(User::class, 'pelaksana_user_id');
    }

    /** @deprecated Alias era distribusi; dipakai modul lama sampai cleanup. Pakai pelaksana(). */
    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'pelaksana_user_id');
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    // ─── Scope ───

    /** Naskah yang masih hidup di papan: belum diarsip & belum dibatalkan. */
    public function scopeActive($query)
    {
        return $query->whereNull('archived_at')->whereNull('cancelled_at');
    }

    /** Tugas milik satu user: sebagai pelaksana ATAU sebagai PJ. */
    public function scopeMine($query, int $userId)
    {
        return $query->where(fn ($q) => $q->where('pelaksana_user_id', $userId)
            ->orWhere('pj_user_id', $userId));
    }

    public function scopeBidang($query, string $bidang)
    {
        return $query->where('bidang', $bidang);
    }

    /** Lewat SLA pembuatan ATAU lewat target publish/terbit (non-final). */
    public function scopeOverdue($query)
    {
        $today = now()->startOfDay();

        return $query->where(function ($q) use ($today) {
            $q->where(fn ($w) => $w->where('status', 'pembuatan')
                    ->whereNotNull('sla_due_at')->whereDate('sla_due_at', '<', $today))
              ->orWhere(fn ($w) => $w->whereNotNull('target_date')
                    ->whereDate('target_date', '<', $today)
                    ->whereNotIn('status', self::FINAL_STAGES));
        });
    }

    // ─── Helper ───

    /** "Sudah X hari di tahap ini" — dihitung dari started_at (hari kalender). */
    public function daysInStage(): int
    {
        if (! $this->started_at) {
            return 0;
        }

        return $this->started_at->copy()->startOfDay()->diffInDays(now()->startOfDay());
    }

    public function isOverdue(): bool
    {
        $today = now()->startOfDay();

        if ($this->status === 'pembuatan' && $this->sla_due_at && $this->sla_due_at->lt($today)) {
            return true;
        }

        return $this->target_date !== null
            && ! self::isFinal((string) $this->status)
            && $this->target_date->lt($today);
    }

    public function nextStage(): ?string
    {
        return $this->getNextStatus();
    }

    public function getStages(): array
    {
        $bookTypes = ['bk_mandiri', 'bk_kolab'];
        return in_array($this->orderDetail->type, $bookTypes)
            ? self::BOOK_STAGES
            : self::ARTICLE_STAGES;
    }

    public function getNextStatus(): ?string
    {
        $stages = $this->getStages();
        $currentIndex = array_search($this->status, $stages);
        if ($currentIndex === false || !isset($stages[$currentIndex + 1])) {
            return null;
        }
        return $stages[$currentIndex + 1];
    }

    public function isValidStatus(string $status): bool
    {
        return in_array($status, $this->getStages());
    }

    public static function getHandlerForStatus(string $status): string
    {
        return self::STAGE_HANDLER[$status] ?? 'superadmin';
    }
}
