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
            Order::class,
            'tb_scope_order',
            'scope_id',
            'order_id'
        );
    }
}
