<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TitleChapter extends Model
{
    use HasFactory;

    protected $table = 'tb_title_chapters';

    protected $fillable = ['title_id', 'judul', 'urutan'];

    public function title()
    {
        return $this->belongsTo(Title::class);
    }
}
