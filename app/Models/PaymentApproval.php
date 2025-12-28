<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentApproval extends Model
{
    use HasFactory;
    protected $table = 'tb_payment_approvals';

    protected $fillable = [
        'payment_id', 'approved_by',
        'status', 'note', 'approved_at'
    ];

    protected $dates = ['approved_at'];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}
