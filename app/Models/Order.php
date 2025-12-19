<?php

namespace App\Models;

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
        'count_authors','naskah_type','publication_type',
        'cost_amount','pay_amount','debit_amount','status',
        'user_id','note', 'contact_phone', 'contact_email',
    ];

    public function scopes()
    {
        return $this->belongsToMany(
            Scope::class,
            'tb_scope_orders',
            'order_id',
            'scope_id'
        );
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
