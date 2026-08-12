<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceInvoiceLog extends Model
{
    protected $table = 'tb_service_invoice_logs';

    protected $fillable = [
        'service_invoice_id', 'event', 'from_status', 'to_status', 'note', 'changed_by',
    ];

    const EVENTS = [
        'created'         => 'Invoice dibuat',
        'updated'         => 'Invoice diubah',
        'status_changed'  => 'Status pengerjaan diubah',
        'payment_added'   => 'Pembayaran dicatat',
        'payment_deleted' => 'Pembayaran dihapus',
        'emailed'         => 'Invoice dikirim via email',
        'email_failed'    => 'Pengiriman email gagal',
        'cancelled'       => 'Invoice dibatalkan',
    ];

    public function invoice()
    {
        return $this->belongsTo(ServiceInvoice::class, 'service_invoice_id');
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function eventLabel(): string
    {
        return self::EVENTS[$this->event] ?? $this->event;
    }
}
