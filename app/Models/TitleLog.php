<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TitleLog extends Model
{
    use HasFactory;

    protected $table = 'tb_title_logs';

    public $timestamps = false;

    protected $fillable = ['title_id', 'event', 'note', 'changed_by', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function title()
    {
        return $this->belongsTo(Title::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
