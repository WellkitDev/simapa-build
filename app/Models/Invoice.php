<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory;

    protected $table = 'tb_invoices';

    protected $fillable = [
        'order_id', 'payment_id', 'invoice_no', 'type', 'status', 'email_requested',
        'issued_at', 'due_at', 'note',
        'pdf_url', 'pdf_drive_id',
        'cancelled_by', 'cancelled_at',
        'refunded_by', 'refunded_at',
        'refund_reason', 'refund_method', 'refund_account', 'refund_payment_id',
    ];

    protected $casts = [
        'issued_at'    => 'datetime',
        'due_at'       => 'datetime',
        'cancelled_at' => 'datetime',
        'refunded_at'  => 'datetime',
    ];

    const STATUSES = [
        'draft', 'diterbitkan', 'jatuh_tempo', 'lunas', 'dibatalkan', 'refund',
    ];

    const TYPES = ['proforma', 'regular'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function logs()
    {
        return $this->hasMany(InvoiceLog::class);
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function refundedBy()
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }

    public function refundPayment()
    {
        return $this->belongsTo(Payment::class, 'refund_payment_id');
    }

    public function isOverdue(): bool
    {
        return $this->due_at !== null
            && $this->due_at->isPast()
            && !in_array($this->status, ['lunas', 'dibatalkan', 'refund']);
    }
}
