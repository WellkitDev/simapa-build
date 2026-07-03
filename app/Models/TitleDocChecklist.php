<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TitleDocChecklist extends Model
{
    protected $table = 'tb_title_doc_checklists';

    protected $fillable = ['title_id', 'status', 'submitted_at', 'submitted_by'];

    protected $casts = ['submitted_at' => 'datetime'];

    public function title()
    {
        return $this->belongsTo(Title::class);
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}
