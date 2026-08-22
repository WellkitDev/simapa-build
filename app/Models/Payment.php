<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $table = 'tb_payments';

    /**
     * Nilai yang sah untuk kolom status & payment_type.
     *
     * 'pending' adalah nilai yang diperkenalkan A3: pembayaran lahir belum
     * terverifikasi, dan baru menjadi 'paid' saat approve(). scopeIncome() menyaring
     * 'paid', jadi daftar ini sekaligus menyatakan uang mana yang dihitung.
     *
     * 'refund' sengaja TIDAK termasuk tipe yang boleh dipilih di formulir pembayaran —
     * ia lahir dari alur refund tersendiri, tanpa PaymentApproval.
     */
    public const STATUSES = ['pending', 'paid', 'rejected'];

    public const TYPES = ['dp', 'lunas', 'pelunasan', 'refund'];

    /** Tipe yang boleh dipilih orang saat mencatat pembayaran masuk. */
    public const TYPES_MASUK = ['dp', 'lunas', 'pelunasan'];

    protected $fillable = [
        'order_id', 'payment_type',
        'amount', 'paid_at',
        'proof_url', 'status',
        'refund_reason', 'refund_method', 'refund_account', 'refunded_by',
    ];

    /**
     * JANGAN kembalikan ke `protected $dates` — properti itu TIDAK berfungsi lagi sejak
     * Laravel 10 (getDates() hanya mengembalikan created_at/updated_at), dan gagalnya
     * senyap: paid_at kembali sebagai string, lalu pola `optional($p->paid_at)->format()`
     * yang dipakai di mana-mana memulangkan null tanpa melempar galat. Hasilnya kolom
     * Tanggal menampilkan "-" di seluruh laporan pemasukan sementara angkanya tetap
     * benar (KPI memakai SQL, bukan cast) — laporan yang hampir benar jauh lebih sulit
     * dicurigai daripada yang jelas rusak.
     */
    protected $casts = [
        'paid_at' => 'datetime',
    ];

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

    /** Refund yang sudah dieksekusi (uang keluar). Pasangan income(). */
    public function scopeRefund($query)
    {
        return $query->where('status', 'paid')->where('payment_type', 'refund');
    }

    /** Scope ke order milik $user (marketing). Bila null → tanpa filter (manager/superadmin). */
    public function scopeForOrdersOf($query, ?User $user)
    {
        return $user
            ? $query->whereHas('order', fn ($o) => $o->where('user_id', $user->id))
            : $query;
    }
}
