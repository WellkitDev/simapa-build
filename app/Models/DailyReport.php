<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DailyReport extends Model
{
    use HasFactory;

    protected $table = 'tb_daily_reports';

    protected $fillable = ['user_id', 'report_date', 'note', 'status', 'submitted_at'];

    protected $casts = [
        'report_date'  => 'date',
        'submitted_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function files()
    {
        return $this->hasMany(DailyReportFile::class);
    }

    public function isSubmitted(): bool
    {
        return $this->status === 'submitted';
    }
}
