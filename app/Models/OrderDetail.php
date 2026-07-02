<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderDetail extends Model
{
    use HasFactory;
    protected $table = 'tb_order_details';

    protected $fillable = [
        'order_id', 'type', 'title_id', 'title', 'slug',
        'chapters', 'indexation',
        'naskah_type', 'publication_type',
        'cost_amount', 'group_key',
    ];

    protected static function booted(): void
    {
        // Jaga group_key selalu sinkron dengan tipe + judul (pengelompokan judul).
        static::saving(function (OrderDetail $detail) {
            if ($detail->type !== null && $detail->title !== null) {
                $detail->group_key = (new \App\Services\TitleArchiveService())
                    ->groupKeyFor($detail->type, $detail->title);
            }
        });
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function titleRef()
    {
        return $this->belongsTo(Title::class, 'title_id');
    }

    public function scopes()
    {
        return $this->belongsToMany(
            Scope::class,
            'tb_scope_orders',
            'order_detail_id',
            'scope_id'
        );
    }

    public function authors()
    {
        return $this->belongsToMany(
            Author::class,
            'tb_author_orders',
            'order_detail_id',
            'author_id'
        )->withPivot('position');
    }

    public function titleProgress()
    {
        return $this->hasOne(TitleProgress::class);
    }
}
