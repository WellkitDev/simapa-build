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

    use HasFactory;

    protected $table = 'tb_orders';

    protected $fillable = [
        'code_order', 'user_id', 'status',
        'note', 'ordered_at', 'completed_at'
    ];

    protected $dates = ['ordered_at', 'completed_at'];

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

    /** Lunas bila ada invoice berstatus 'lunas' atau total pembayaran 'paid' >= biaya. */
    public function isLunas(): bool
    {
        if ($this->invoices()->where('status', 'lunas')->exists()) {
            return true;
        }
        $paid = (int) $this->payments()->where('status', 'paid')->sum('amount');
        $cost = (int) optional($this->details)->cost_amount;
        return $paid >= $cost;
    }
}
