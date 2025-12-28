<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory;

    protected $table = 'tb_invoices';

    protected $fillable = [
        'order_id', 'payment_id', 'invoice_no',
        'issued_at', 'due_at',
        'pdf_url', 'pdf_drive_id', 'status'
    ];

    protected $dates = ['issued_at', 'due_at'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}
