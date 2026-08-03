<?php

namespace App\Models;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Order extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'tb_orders';

    protected $fillable = [
        'code_order', 'user_id', 'status',
        'note', 'ordered_at', 'completed_at',
        'cancel_reason', 'cancelled_by', 'cancelled_at',
    ];

    protected $dates = ['ordered_at', 'completed_at'];

    protected $casts = ['cancelled_at' => 'datetime'];

    public function details()
    {
        return $this->hasOne(OrderDetail::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function contact()
    {
        return $this->hasOne(OrderContact::class);
    }
    public function user()
    {
       return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Uang bersih yang diterima untuk order ini: pembayaran masuk - refund.
     * Dipakai semua pertanyaan "sudah dibayar berapa" (lunas, sisa, arsip).
     * BEDA dari Payment::income() (pelaporan, refund dikecualikan) — lihat
     * docs/superpowers/specs/2026-07-17-paid-net-refund-design.md.
     */
    public function paidNet(): int
    {
        return (int) $this->payments()->income()->sum('amount')
             - (int) $this->payments()->refund()->sum('amount');
    }

    /** Versi SQL dari paidNet() untuk filter di query (harus setara — dikunci PaidNetTest). */
    public const PAID_NET_SQL = "(SELECT COALESCE(SUM(CASE WHEN payment_type = 'refund' THEN -amount ELSE amount END), 0) FROM tb_payments WHERE tb_payments.order_id = tb_orders.id AND tb_payments.status = 'paid')";

    /** Lunas bila ada invoice berstatus 'lunas' atau uang bersih >= biaya. */
    public function isLunas(): bool
    {
        if ($this->invoices()->where('status', 'lunas')->exists()) {
            return true;
        }

        return $this->paidNet() >= (int) optional($this->details)->cost_amount;
    }
}
