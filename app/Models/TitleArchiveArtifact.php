<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TitleArchiveArtifact extends Model
{
    protected $table = 'tb_title_archive_artifacts';

    protected $fillable = ['title_id', 'key', 'label', 'type', 'value', 'file_name', 'pic_user_id', 'note', 'is_custom', 'position'];

    protected $casts = ['is_custom' => 'boolean'];

    public function title() { return $this->belongsTo(Title::class); }
    public function pic() { return $this->belongsTo(User::class, 'pic_user_id'); }
}
