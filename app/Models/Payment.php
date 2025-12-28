<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $table = 'tb_payments';

    protected $fillable = [
        'order_id', 'payment_type',
        'amount', 'paid_at',
        'proof_url', 'status'
    ];

    protected $dates = ['paid_at'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function invoice()
    {
        return $this->hasOne(Invoice::class);
    }

    public function approval()
    {
        return $this->hasOne(PaymentApproval::class);
    }
}
