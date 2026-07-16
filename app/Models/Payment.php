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
        'proof_url', 'status',
        'refund_reason', 'refund_method', 'refund_account', 'refunded_by',
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

    public function refundedBy()
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }

    /** Pembayaran yang dianggap "uang masuk" (di-set bersamaan approval saat approve). */
    public function scopeApproved($query)
    {
        return $query->where('status', 'paid');
    }

    /**
     * Uang masuk kanonik: pembayaran diterima, BUKAN refund.
     * Refund = uang keluar → dicatat sebagai pengeluaran di Jurnal Kas
     * (PaymentCashSyncService). Dipakai semua tempat yang bertanya
     * "berapa uang masuk": Laporan Keuangan, Dashboard & Target Marketing.
     */
    public function scopeIncome($query)
    {
        return $query->where('status', 'paid')->where('payment_type', '!=', 'refund');
    }

    /** Scope ke order milik $user (marketing). Bila null → tanpa filter (manager/superadmin). */
    public function scopeForOrdersOf($query, ?User $user)
    {
        return $user
            ? $query->whereHas('order', fn ($o) => $o->where('user_id', $user->id))
            : $query;
    }
}
