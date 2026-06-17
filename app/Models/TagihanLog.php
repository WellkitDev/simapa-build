<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TagihanLog extends Model
{
    protected $table = 'tb_tagihan_logs';

    public $timestamps = false;

    protected $fillable = [
        'tagihan_id', 'from_status', 'to_status', 'changed_by', 'note',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function tagihan()
    {
        return $this->belongsTo(Tagihan::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
