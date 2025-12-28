<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Scope extends Model
{
    use HasFactory;
    protected $table = 'tb_scopes';
    protected $fillable = ['scope'];

    public function orders()
    {
        return $this->belongsToMany(
            OrderDetail::class,
            'tb_scope_orders',
            'scope_id',
            'order_detail_id'
        );
    }
}
