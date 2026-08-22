<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    use HasFactory;

    protected $table = 'tb_tasks';

    public const STATUSES = ['todo', 'in_progress', 'done'];
    public const PRIORITIES = ['low', 'normal', 'high'];

    protected $fillable = [
        'user_id', 'title', 'description', 'status', 'priority',
        'due_date', 'position', 'completed_at', 'deadline_notified_at', 'deadline_stage', 'created_by',
    ];

    protected $casts = [
        'due_date'             => 'date',
        'completed_at'         => 'datetime',
        'deadline_notified_at' => 'datetime',
        'position'             => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
