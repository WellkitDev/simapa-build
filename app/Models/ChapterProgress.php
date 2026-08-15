<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChapterProgress extends Model
{
    use HasFactory;

    protected $table = 'tb_chapter_progress';

    protected $fillable = [
        'title_chapter_id', 'status', 'assigned_user_id',
        'needs_review', 'note', 'updated_by', 'started_at', 'last_log_at',
        'pelaksana_user_id', 'sla_due_at',
    ];

    protected $casts = [
        'needs_review' => 'boolean',
        'started_at'   => 'datetime',
        'last_log_at'  => 'datetime',
        'sla_due_at'   => 'date',
    ];

    /**
     * Alur bab (buku kolaborasi): Pembuatan+Editing berjalan per bab; setelah 'selesai'
     * bab tidak bergerak lagi — tahap layout..terbit berjalan level buku.
     */
    const CHAPTER_STAGES = ['menunggu', 'pembuatan', 'editing', 'selesai'];

    const STAGE_LABELS_ID = [
        'menunggu'  => 'Menunggu',
        'pembuatan' => 'Pembuatan',
        'editing'   => 'Editing',
        'selesai'   => 'Selesai',
    ];

    /** Status bab era distribusi (BOOK_STAGES) → CHAPTER_STAGES; dipakai naskah:migrate-v2. */
    const LEGACY_STAGE_MAP = [
        'menunggu_proses' => 'menunggu',
        'templating'      => 'editing',
        'editing'         => 'editing',
        'layout'          => 'editing',
        'proofreading'    => 'editing',
        'isbn'            => 'editing',
        'cetak'           => 'editing',
        'terbit'          => 'selesai',
        'publish'         => 'selesai',
    ];

    public static function labelFor(?string $status): string
    {
        if ($status === null) {
            return '—';
        }

        return self::STAGE_LABELS_ID[$status]
            ?? \Illuminate\Support\Str::title(str_replace('_', ' ', $status));
    }

    public function stageLabelId(): string
    {
        return self::labelFor($this->status);
    }

    public function nextStage(): ?string
    {
        // Bab bernaskah mandiri melewati tahap Pembuatan: naskahnya sudah dikirim
        // authornya, jadi tak ada yang perlu "dibuat" dan tak akan pernah ada
        // pelaksana. Aturan ini menyalin cabang 'menunggu_proses' pada
        // TitleProgressService::autoAdvanceOnUpload() di tingkat judul.
        if ($this->status === 'menunggu' && $this->naskahDariAuthor()) {
            return 'editing';
        }

        $idx = array_search($this->status, self::CHAPTER_STAGES, true);
        if ($idx === false || ! isset(self::CHAPTER_STAGES[$idx + 1])) {
            return null;
        }

        return self::CHAPTER_STAGES[$idx + 1];
    }

    /** "Hari ke-X" pada tahap berjalan (hari kalender sejak started_at). */
    public function daysInStage(): int
    {
        if (! $this->started_at) {
            return 0;
        }

        return $this->started_at->copy()->startOfDay()->diffInDays(now()->startOfDay());
    }

    public function isOverdue(): bool
    {
        return $this->status === 'pembuatan'
            && $this->sla_due_at !== null
            && $this->sla_due_at->lt(now()->startOfDay());
    }

    /**
     * Asal naskah bab ini: 'mandiri' (dikirim authornya) | 'dibuatkan' (ditulis tim)
     * | null (babnya belum dipesan siapa pun, jadi belum diketahui).
     *
     * Diambil dari ORDER yang memesan bab ini — pada buku kolaborasi satu order = satu
     * author = satu bab, dan `naskah_type` order itulah jawabannya. Ini penting bukan
     * sekadar untuk label: kalau bab bernaskah mandiri diperlakukan sebagai 'dibuatkan',
     * pelaksana akan menulis naskah yang sebenarnya sudah dikirim authornya.
     */
    public function sumberNaskah(): ?string
    {
        $bab  = $this->chapter;
        $buku = $bab?->title;

        if (! $bab || ! $buku) {
            return null;
        }

        $order = $buku->orderForChapter((int) $bab->urutan);

        return $order === null ? null : ($order->naskahMandiri() ? 'mandiri' : 'dibuatkan');
    }

    /** Naskah bab ini dikirim authornya sendiri — tak perlu (dan tak boleh) ada pelaksana. */
    public function naskahDariAuthor(): bool
    {
        return $this->sumberNaskah() === 'mandiri';
    }

    public function chapter()
    {
        return $this->belongsTo(TitleChapter::class, 'title_chapter_id');
    }

    /** Pelaksana pembuatan bab — akun production. */
    public function pelaksana()
    {
        return $this->belongsTo(User::class, 'pelaksana_user_id');
    }

    /** @deprecated Editor era distribusi; dipakai modul lama sampai cleanup. Pakai pelaksana(). */
    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
