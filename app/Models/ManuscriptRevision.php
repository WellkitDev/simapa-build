<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu putaran perbaikan naskah: permintaan dari PJ, jawaban dari Pelaksana.
 *
 * Dua bentuk yang dipakai:
 * - stage 'revisi'    — reviewer jurnal meminta perbaikan (artikel)
 * - stage 'pembuatan' — PJ mengembalikan naskah ke pelaksana dari Editing (buku & artikel)
 *
 * Bedanya bukan kosmetik: HANYA putaran 'revisi' yang menggerbangi laju naskah.
 * Pengembalian ke Pembuatan dijawab dengan naskahnya sendiri lewat slot yang sudah ada,
 * bukan dengan berkas balasan. Lihat spec §5.2.
 */
class ManuscriptRevision extends Model
{
    protected $table = 'tb_manuscript_revisions';

    protected $fillable = [
        'title_id', 'title_chapter_id', 'round', 'stage', 'from_stage',
        'requested_by', 'assigned_to', 'request_note',
        'closed_at', 'closed_by', 'close_note',
    ];

    // JANGAN pakai `protected $dates` — mati sejak Laravel 10, dan kolomnya akan
    // diam-diam kembali sebagai string sehingga ->format() menghasilkan null tanpa galat.
    protected $casts = [
        'closed_at' => 'datetime',
        'round'     => 'integer',
    ];

    public const STAGES = ['revisi', 'pembuatan'];

    public function title(): BelongsTo
    {
        return $this->belongsTo(Title::class, 'title_id');
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(TitleChapter::class, 'title_chapter_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function files(): HasMany
    {
        return $this->hasMany(ManuscriptFile::class, 'manuscript_revision_id');
    }

    public function berkasMinta(): HasMany
    {
        return $this->files()->where('slot', 'revisi_minta');
    }

    public function berkasHasil(): HasMany
    {
        return $this->files()->where('slot', 'revisi_hasil');
    }

    public function terbuka(): bool
    {
        return $this->closed_at === null;
    }

    /**
     * Berkas yang masih `antre` DIHITUNG terjawab: berkasnya sudah dikirim orangnya,
     * dan menahan naskah karena Google Drive sedang lambat berarti menghukum orang atas
     * hal yang bukan urusannya. `gagal` tidak dihitung — berkasnya memang tak sampai.
     */
    public function terjawab(): bool
    {
        return $this->berkasHasil()
            ->whereIn('status', ['selesai', 'antre'])
            ->exists();
    }

    /** Putaran yang menahan laju naskah: revisi, terbuka, ada permintaan, belum dijawab. */
    public function menahan(): bool
    {
        return $this->stage === 'revisi'
            && $this->terbuka()
            && $this->berkasMinta()->exists()
            && ! $this->terjawab();
    }

    /** Nomor putaran berikutnya untuk sebuah judul. */
    public static function nomorBerikutnya(int $titleId): int
    {
        return (int) self::where('title_id', $titleId)->max('round') + 1;
    }
}
