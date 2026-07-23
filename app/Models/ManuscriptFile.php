<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManuscriptFile extends Model
{
    protected $table = 'tb_manuscript_files';

    public const UPDATED_AT = null; // append-only: hanya created_at

    public const SLOTS = ['masuk' => 'Naskah Masuk', 'final' => 'Naskah Final'];

    protected $fillable = [
        'title_id', 'title_chapter_id', 'slot', 'version',
        'original_name', 'drive_file_id', 'drive_url', 'file_size', 'uploaded_by',
    ];

    public function title() { return $this->belongsTo(Title::class); }
    public function chapter() { return $this->belongsTo(TitleChapter::class, 'title_chapter_id'); }
    public function uploader() { return $this->belongsTo(User::class, 'uploaded_by'); }

    public function slotLabel(): string { return self::SLOTS[$this->slot] ?? $this->slot; }
}
