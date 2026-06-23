<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    use HasFactory;

    protected $table = 'tb_announcements';

    /** Ambang "Baru" (hari sejak published_at). */
    public const NEW_DAYS = 3;

    protected $fillable = [
        'title', 'body', 'status', 'is_pinned', 'published_at', 'created_by',
    ];

    protected $casts = [
        'is_pinned'    => 'boolean',
        'published_at' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reads()
    {
        return $this->hasMany(AnnouncementRead::class);
    }
}
