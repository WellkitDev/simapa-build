<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceClient extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'tb_service_clients';

    protected $fillable = [
        'name', 'institution', 'email', 'phone', 'address', 'note',
        'created_by', 'updated_by',
    ];

    public function invoices()
    {
        // Pemecah seri wajib: issued_at bertipe date (tanpa jam), jadi dua invoice
        // di hari yang sama akan bertukar urutan antar-request tanpa `id`.
        return $this->hasMany(ServiceInvoice::class)
            ->orderByDesc('issued_at')
            ->orderByDesc('id');
    }

    /** "Nama — Instansi" untuk dropdown; instansi kosong tidak menyisakan tanda pisah. */
    public function displayName(): string
    {
        return $this->institution ? $this->name . ' — ' . $this->institution : $this->name;
    }
}
