<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $table = 'tb_payments';
    protected $fillable = [
        'order_id','type','amount','date',
        'struk_url','struk_id','status'
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
