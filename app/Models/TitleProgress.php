<?php
// app/Models/TitleProgress.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TitleProgress extends Model
{
    use HasFactory;

    protected $table = 'tb_title_progress';

    protected $fillable = [
        'order_detail_id', 'status', 'assigned_role',
        'note', 'updated_by', 'started_at',
        'assigned_user_id', 'priority', 'needs_review',
    ];

    protected $dates = ['started_at'];

    protected $casts = [
        'needs_review' => 'boolean',
    ];

    const ARTICLE_STAGES = [
        'menunggu_proses', 'templating', 'editing',
        'revisi', 'submit', 'loa', 'publish',
    ];

    const BOOK_STAGES = [
        'menunggu_proses', 'editing', 'layout',
        'proofreading', 'isbn', 'cetak', 'terbit',
    ];

    const STAGE_HANDLER = [
        'menunggu_proses' => 'marketing',
        'templating'      => 'production',
        'editing'         => 'production',
        'revisi'          => 'production',
        'submit'          => 'production',
        'loa'             => 'superadmin',
        'publish'         => 'superadmin',
        'layout'          => 'production',
        'proofreading'    => 'production',
        'isbn'            => 'production',
        'cetak'           => 'superadmin',
        'terbit'          => 'superadmin',
    ];

    const PRIORITIES = ['low', 'normal', 'high'];

    public function orderDetail()
    {
        return $this->belongsTo(OrderDetail::class);
    }

    public function logs()
    {
        return $this->hasMany(TitleProgressLog::class);
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function getStages(): array
    {
        $bookTypes = ['bk_mandiri', 'bk_kolab'];
        return in_array($this->orderDetail->type, $bookTypes)
            ? self::BOOK_STAGES
            : self::ARTICLE_STAGES;
    }

    public function getNextStatus(): ?string
    {
        $stages = $this->getStages();
        $currentIndex = array_search($this->status, $stages);
        if ($currentIndex === false || !isset($stages[$currentIndex + 1])) {
            return null;
        }
        return $stages[$currentIndex + 1];
    }

    public function isValidStatus(string $status): bool
    {
        return in_array($status, $this->getStages());
    }

    public static function getHandlerForStatus(string $status): string
    {
        return self::STAGE_HANDLER[$status] ?? 'superadmin';
    }
}
