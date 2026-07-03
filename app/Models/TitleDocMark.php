<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TitleDocMark extends Model
{
    protected $table = 'tb_title_doc_marks';

    protected $fillable = ['title_id', 'doc_requirement_id', 'status', 'file_url', 'file_name', 'catatan', 'updated_by'];

    const STATUSES = ['ada' => 'Ada', 'belum' => 'Belum', 'tidak_perlu' => 'Tidak perlu'];

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function requirement()
    {
        return $this->belongsTo(DocRequirement::class, 'doc_requirement_id');
    }

    public function title()
    {
        return $this->belongsTo(Title::class);
    }
}
