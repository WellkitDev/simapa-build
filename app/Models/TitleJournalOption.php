<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TitleJournalOption extends Model
{
    use HasFactory;

    protected $table = 'tb_title_journal_options';

    protected $fillable = ['title_id', 'nama_jurnal', 'link', 'apc', 'urutan'];

    public function title()
    {
        return $this->belongsTo(Title::class);
    }
}
