<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceInvoicePayment extends Model
{
    use SoftDeletes;

    protected $table = 'tb_service_invoice_payments';

    protected $fillable = [
        'service_invoice_id', 'paid_at', 'type', 'amount',
        'method', 'reference', 'note', 'created_by',
    ];

    protected $casts = [
        'paid_at' => 'date',
        'amount'  => 'decimal:2',
    ];

    const TYPES   = ['dp' => 'DP', 'cicilan' => 'Cicilan', 'pelunasan' => 'Pelunasan'];
    const METHODS = ['transfer' => 'Transfer', 'tunai' => 'Tunai', 'lainnya' => 'Lainnya'];

    public function invoice()
    {
        return $this->belongsTo(ServiceInvoice::class, 'service_invoice_id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? $this->method;
    }
}
