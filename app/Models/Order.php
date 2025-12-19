<?php

namespace App\Models;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Order extends Model
{
    use HasFactory, SoftDeletes, Notifiable;

    protected $table = 'tb_orders';

    protected $fillable = [
        'code_order','type','title','slug','chapters','indexation',
        'naskah_type','publication_type',
        'cost_amount','pay_amount','debit_amount','status',
        'user_id','note', 'contact_phone', 'contact_email',
    ];

    public function scopes()
    {
        return $this->belongsToMany(
            Scope::class,
            'tb_scope_order',
            'order_id',
            'scope_id'
        );
    }
    protected static function boot()
    {
        parent::boot();
        static::creating(function ($order) {
            $order->slug = Str::slug($order->title);
            $order->code_order = 'ORD-' . date('Ym') . '-' . str_pad(self::count() + 1, 4, '0', STR_PAD_LEFT);
        });
    }
    public function authors()
    {
        return $this->belongsToMany(
            Author::class,
            'tb_author_order'
        )->withPivot('possition')->withTimestamps();
    }

     public function payments()
    {
        return $this->hasMany(Payment::class, 'order_id');
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'order_id');
    }

    public function users()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
