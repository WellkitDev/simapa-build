<?php
// app/Models/TitleProgressLog.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TitleProgressLog extends Model
{
    use HasFactory;

    protected $table = 'tb_title_progress_logs';

    public $timestamps = false;

    protected $fillable = [
        'title_progress_id', 'from_status', 'to_status',
        'changed_by', 'note', 'is_correction',
    ];

    protected $casts = [
        'is_correction' => 'boolean',
        'created_at'    => 'datetime',
    ];

    public function titleProgress()
    {
        return $this->belongsTo(TitleProgress::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
