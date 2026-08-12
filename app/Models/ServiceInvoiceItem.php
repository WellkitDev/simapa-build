<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceInvoiceItem extends Model
{
    protected $table = 'tb_service_invoice_items';

    protected $fillable = [
        'service_invoice_id', 'service_catalog_id',
        'name', 'description', 'qty', 'unit_price', 'subtotal', 'position',
    ];

    protected $casts = [
        'qty'        => 'decimal:2',
        'unit_price' => 'decimal:2',
        'subtotal'   => 'decimal:2',
        'position'   => 'integer',
    ];

    public function invoice()
    {
        return $this->belongsTo(ServiceInvoice::class, 'service_invoice_id');
    }
}
