<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory;

    protected $table = 'tb_invoices';

    protected $fillable = [
        'order_id','inv_no','details',
        'issued_at','dued_at',
        'inv_pdf_url','inv_pdf_id'
    ];

    protected $casts = [
        'details' => 'array',
        'issued_at' => 'datetime',
        'dued_at' => 'datetime'
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
