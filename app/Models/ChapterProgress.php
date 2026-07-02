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
    ];

    protected $casts = [
        'needs_review' => 'boolean',
        'started_at'   => 'datetime',
        'last_log_at'  => 'datetime',
    ];

    public function chapter()
    {
        return $this->belongsTo(TitleChapter::class, 'title_chapter_id');
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
