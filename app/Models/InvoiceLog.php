<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class InvoiceLog extends Model
{
    use HasFactory;

    protected $table = 'tb_invoice_logs';

    public $timestamps = false;

    protected $fillable = [
        'invoice_id', 'from_status', 'to_status', 'changed_by', 'note',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
