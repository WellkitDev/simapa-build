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

    /** Keadaan UANG. Dibaca Laporan Keuangan & Piutang — jangan tambahi nilai baru. */
    public const STATUSES = ['pending', 'lunas', 'dibatalkan'];

    /**
     * Keadaan PEKERJAAN. `ditarik` = order di-refund penuh dan tak lagi dihitung
     * sebagai bagian judul. Ditulis HANYA oleh OrderFulfillmentService dan
     * OrderWithdrawalService.
     */
    public const FULFILLMENTS = ['berjalan', 'selesai', 'ditarik', 'dibatalkan'];

    protected $fillable = [
        'code_order', 'user_id', 'status', 'fulfillment_status',
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

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Ada pembayaran yang benar-benar sudah disetujui approver.
     *
     * SENGAJA membaca tb_payment_approvals.status, BUKAN tb_payments.status:
     * PaymentBookController::store() menulis status 'paid' bersamaan dengan approval
     * 'pending', jadi 'paid' tidak berarti disetujui (spec §0.1).
     */
    public function hasApprovedPayment(): bool
    {
        // Pakai koleksi yang sudah di-eager load bila ada: daftar order memanggil
        // isCancellable() sekali per baris, dan payments()->exists() akan menembak
        // SQL baru tiap panggilan meski relasinya sudah dimuat.
        if ($this->relationLoaded('payments')) {
            return $this->payments->contains(
                fn ($payment) => optional($payment->approval)->status === 'approved'
            );
        }

        return $this->payments()
            ->whereHas('approval', fn ($q) => $q->where('status', 'approved'))
            ->exists();
    }

    /** Order dibatalkan: status 'dibatalkan' atau sudah soft-deleted. */
    public function isCancelled(): bool
    {
        return $this->status === 'dibatalkan' || $this->trashed();
    }

    /** Order ditarik dari judul karena refund penuh. */
    public function isWithdrawn(): bool
    {
        return $this->fulfillment_status === 'ditarik';
    }

    /**
     * Boleh diedit selama belum dibatalkan — termasuk setelah pembayaran disetujui.
     * Gerbang ini dipakai untuk MEMBUKA Edit lebih awal, bukan menutupnya belakangan.
     */
    public function isEditable(): bool
    {
        return ! $this->isCancelled();
    }

    /**
     * Boleh dibatalkan: belum dibatalkan, belum ada payment yang disetujui,
     * DAN belum pernah di-refund.
     *
     * Refund sengaja ikut menutup gerbang: RefundController::paidIn() tidak
     * memeriksa approval, dan payment refund dibuat tanpa PaymentApproval — jadi
     * order yang sudah di-refund tetap lolos hasApprovedPayment(). Membatalkannya
     * akan menghapus entri kas pemasukan DAN pengeluaran refund-nya sekaligus,
     * padahal uangnya benar-benar sudah masuk lalu keluar lewat transfer bank.
     */
    public function isCancellable(): bool
    {
        return ! $this->isCancelled()
            && ! $this->hasApprovedPayment()
            && ! $this->hasRefund();
    }

    /** Sudah pernah di-refund (uang keluar tercatat). */
    public function hasRefund(): bool
    {
        if ($this->relationLoaded('payments')) {
            return $this->payments->contains(fn ($payment) => $payment->payment_type === 'refund');
        }

        return $this->payments()->where('payment_type', 'refund')->exists();
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
