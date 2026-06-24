<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DailyReportFile extends Model
{
    use HasFactory;

    protected $table = 'tb_daily_report_files';

    protected $fillable = ['daily_report_id', 'drive_file_id', 'name', 'url', 'mime', 'size', 'uploaded_by'];

    public function report()
    {
        return $this->belongsTo(DailyReport::class, 'daily_report_id');
    }
}
