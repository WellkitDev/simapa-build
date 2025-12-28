<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderContact extends Model
{
    use HasFactory;
    protected $table = 'tb_order_contacts';

    protected $fillable = ['order_id', 'cp_phone', 'cp_email'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
