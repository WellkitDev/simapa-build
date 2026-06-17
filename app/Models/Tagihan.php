<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tagihan extends Model
{
    use HasFactory;

    protected $table = 'tb_tagihan';

    protected $fillable = [
        'tagihan_no', 'created_by',
        'client_name', 'client_email', 'client_phone', 'client_institution',
        'title', 'type', 'author_names', 'description', 'amount', 'due_at', 'note',
        'status', 'approved_by', 'approved_at', 'reject_note',
        'order_id', 'order_code',
    ];

    protected $casts = [
        'amount'      => 'integer',
        'due_at'      => 'date',
        'approved_at' => 'datetime',
    ];

    const STATUSES = ['diajukan', 'disetujui', 'ditolak', 'jadi_order', 'dibatalkan'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function logs()
    {
        return $this->hasMany(TagihanLog::class);
    }

    /** Boleh diedit hanya saat menunggu (diajukan) atau ditolak. */
    public function isEditable(): bool
    {
        return in_array($this->status, ['diajukan', 'ditolak']);
    }

    /** PDF & kirim ke klien hanya setelah disetujui. */
    public function isDownloadable(): bool
    {
        return in_array($this->status, ['disetujui', 'jadi_order']);
    }

    /** Boleh dikonversi jadi order. */
    public function canConvert(): bool
    {
        return $this->status === 'disetujui';
    }
}
