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

    public function progress()
    {
        return $this->hasOne(ChapterProgress::class, 'title_chapter_id');
    }

    public function authors()
    {
        return $this->belongsToMany(Author::class, 'tb_title_chapter_authors', 'title_chapter_id', 'author_id')
            ->withPivot('position')->orderByPivot('position');
    }
}
