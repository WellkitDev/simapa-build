<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManuscriptFile extends Model
{
    protected $table = 'tb_manuscript_files';

    public const UPDATED_AT = null; // append-only: hanya created_at

    /**
     * Slot file per tahap. `masuk` = naskah yang jadi bukti kerja (memicu maju tahap
     * otomatis); sisanya keluaran tiap tahap. Kolom `slot` bertipe string(20), jadi
     * menambah slot tidak perlu migrasi.
     */
    public const SLOTS = [
        'masuk'           => 'Naskah Masuk',
        'hasil_editing'   => 'Hasil Editing',
        'hasil_layout'    => 'Hasil Layout',
        'hasil_proofread' => 'Hasil Proofread',
        'cover'           => 'Cover',
        'loa'             => 'LoA (Letter of Acceptance)',
        'final'           => 'Naskah Final',
    ];

    /**
     * Slot yang relevan per jenis naskah. Artikel tidak pernah punya layout/proofread/
     * cover, dan buku tidak pernah punya LoA — menampilkan semuanya di kedua jenis
     * hanya membuat daftar file penuh baris "belum ada" yang tak akan pernah terisi.
     */
    public const SLOTS_ARTIKEL = ['masuk', 'hasil_editing', 'loa', 'final'];

    public const SLOTS_BUKU = ['masuk', 'hasil_editing', 'hasil_layout', 'hasil_proofread', 'cover', 'final'];

    /** @return array<string,string> slot => label, sesuai jenis naskah. */
    public static function slotsFor(bool $buku): array
    {
        $keys = $buku ? self::SLOTS_BUKU : self::SLOTS_ARTIKEL;

        return array_replace(array_flip($keys), array_intersect_key(self::SLOTS, array_flip($keys)));
    }

    protected $fillable = [
        'title_id', 'title_chapter_id', 'slot', 'version',
        'original_name', 'drive_file_id', 'drive_url', 'file_size', 'uploaded_by',
    ];

    public function title() { return $this->belongsTo(Title::class); }
    public function chapter() { return $this->belongsTo(TitleChapter::class, 'title_chapter_id'); }
    public function uploader() { return $this->belongsTo(User::class, 'uploaded_by'); }

    public function slotLabel(): string { return self::SLOTS[$this->slot] ?? $this->slot; }
}
