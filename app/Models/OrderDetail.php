<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderDetail extends Model
{
    use HasFactory;
    protected $table = 'tb_order_details';

    protected $fillable = [
        'order_id', 'type', 'title', 'slug',
        'chapters', 'indexation',
        'naskah_type', 'publication_type',
        'cost_amount'
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
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
}
