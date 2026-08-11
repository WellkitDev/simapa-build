<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceInvoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'tb_service_invoices';

    protected $fillable = [
        'invoice_no', 'service_client_id',
        'client_name', 'client_institution', 'client_email', 'client_phone', 'client_address',
        'issued_at', 'due_at',
        'work_status', 'work_started_at', 'work_finished_at',
        // `subtotal`, `total`, `paid_total`, `remaining` SENGAJA tidak fillable:
        // satu-satunya penulisnya adalah recalcTotals(), lewat forceFill() yang
        // memang melewati $fillable. Membiarkannya terbuka berarti sebuah form
        // (mis. layar koreksi) bisa mengirim paid_total dan membuatnya menyimpang
        // dari SUM(payments.amount) sampai recalcTotals() berikutnya.
        // `discount` ikut karena memang masukan pengguna; `payment_status` ikut
        // karena diisi saat invoice dibuat.
        'discount', 'payment_status',
        'note', 'internal_note',
        'pdf_drive_url', 'sent_at', 'sent_count',
        'cancel_reason', 'cancelled_by', 'cancelled_at',
        'created_by', 'updated_by',
    ];

    // CATATAN: JANGAN pakai `protected $dates` — mati sejak Laravel 10, diam-diam
    // membuat kolom tanggal tetap berupa string dan ->format() meledak.
    protected $casts = [
        'issued_at'        => 'date',
        'due_at'           => 'date',
        'work_started_at'  => 'datetime',
        'work_finished_at' => 'datetime',
        'sent_at'          => 'datetime',
        'cancelled_at'     => 'datetime',
        'subtotal'         => 'decimal:2',
        'discount'         => 'decimal:2',
        'total'            => 'decimal:2',
        'paid_total'       => 'decimal:2',
        'remaining'        => 'decimal:2',
        'sent_count'       => 'integer',
    ];

    const WORK_STATUS = [
        'belum'   => 'Belum Dikerjakan',
        'proses'  => 'Proses',
        'selesai' => 'Selesai',
        'batal'   => 'Dibatalkan',
    ];

    const PAYMENT_STATUS = [
        'belum' => 'Belum Dibayar',
        'dp'    => 'DP',
        'lunas' => 'Lunas',
    ];

    public function client()
    {
        return $this->belongsTo(ServiceClient::class, 'service_client_id');
    }

    public function items()
    {
        return $this->hasMany(ServiceInvoiceItem::class)->orderBy('position')->orderBy('id');
    }

    public function payments()
    {
        return $this->hasMany(ServiceInvoicePayment::class)->orderBy('paid_at')->orderBy('id');
    }

    public function logs()
    {
        return $this->hasMany(ServiceInvoiceLog::class)->latest('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function workStatusLabel(): string
    {
        return self::WORK_STATUS[$this->work_status] ?? $this->work_status;
    }

    public function paymentStatusLabel(): string
    {
        return self::PAYMENT_STATUS[$this->payment_status] ?? $this->payment_status;
    }

    public function isCancelled(): bool
    {
        return $this->work_status === 'batal';
    }

    public function isOverpaid(): bool
    {
        return (float) $this->remaining < 0;
    }

    public function overpaidAmount(): float
    {
        return $this->isOverpaid() ? abs((float) $this->remaining) : 0.0;
    }

    /**
     * Tulis ulang subtotal/total/paid_total/remaining/payment_status dari baris anaknya.
     * WAJIB dipanggil setiap kali item, diskon, atau pembayaran berubah — kolom-kolom
     * ini sengaja didenormalisasi supaya daftar bisa mengurutkan & memfilter di SQL.
     *
     * payment_status TIDAK PERNAH diketik manusia; selalu hasil hitungan di sini.
     *
     * Semua nilai dibulatkan ke 2 desimal SEBELUM dibandingkan. Tanpa itu, invoice
     * yang dibayar tepat lunas bisa tersangkut di 'dp' selamanya: float tak bisa
     * mewakili sebagian pecahan rupiah, sehingga `paid >= total` bernilai false
     * padahal selisihnya 6e-8 — dan selisih itu tersimpan sebagai 0.00 di kolom
     * decimal(15,2), membuat barisnya menyangkal dirinya sendiri (sisa nol, status DP).
     *
     * DUA HAL YANG PERLU DIINGAT PEMANGGIL:
     *  - `save()` menulis SEMUA atribut yang kotor, bukan hanya lima kolom di bawah.
     *    Jangan panggil metode ini sambil menggantung perubahan lain di memori
     *    kecuali memang ingin ikut tersimpan.
     *  - SUM di sini adalah consistent read TANPA kunci baris, jadi membungkusnya
     *    dengan `DB::transaction` saja TIDAK menyerialkan dua pencatatan pembayaran
     *    yang bersamaan — yang terakhir bisa menimpa dengan angka basi. Hitungannya
     *    derivatif (bukan inkremental), jadi panggilan berikutnya memulihkannya.
     *    Diterima sebagai risiko: alat internal dengan satu-dua operator.
     */
    public function recalcTotals(): void
    {
        $subtotal  = round((float) $this->items()->sum('subtotal'), 2);
        $total     = round(max($subtotal - (float) $this->discount, 0), 2);
        $paid      = round((float) $this->payments()->sum('amount'), 2);
        $remaining = round($total - $paid, 2);

        $status = 'belum';
        if ($paid > 0) {
            $status = $remaining <= 0 ? 'lunas' : 'dp';
        }

        $this->forceFill([
            'subtotal'       => $subtotal,
            'total'          => $total,
            'paid_total'     => $paid,
            'remaining'      => $remaining,   // negatif = lebih bayar, sengaja dipertahankan
            'payment_status' => $status,
        ])->save();
    }

    /**
     * Dua hal yang mudah salah di sini:
     *  - `lt(today())`, BUKAN `isPast()`. `due_at` di-cast `date` sehingga jatuh di
     *    tengah malam, dan `isPast()` menandai invoice telat sejak pukul 00:00 pada
     *    hari jatuh temponya sendiri — padahal hari itu masih hak klien.
     *  - ambang utangnya `remaining`, BUKAN `payment_status`. Invoice bertotal nol
     *    (mis. pekerjaan garansi) tak pernah bisa mencapai 'lunas', jadi memakai
     *    payment_status akan menandainya telat selamanya atas utang nol.
     */
    public function isOverdue(): bool
    {
        return $this->due_at !== null
            && $this->due_at->lt(today())
            && (float) $this->remaining > 0
            && ! $this->isCancelled();
    }
}
