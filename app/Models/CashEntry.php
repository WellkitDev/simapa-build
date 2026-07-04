<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashEntry extends Model
{
    protected $table = 'tb_cash_entries';

    protected $fillable = ['tanggal', 'kode', 'keterangan', 'jenis', 'amount', 'cash_category_id', 'account_id', 'produk', 'ref', 'catatan', 'source', 'created_by', 'payment_id', 'is_transfer', 'transfer_group'];

    protected $casts = ['tanggal' => 'date', 'amount' => 'decimal:2', 'is_transfer' => 'boolean'];

    const PRODUK = ['artikel' => 'Artikel', 'buku' => 'Buku', 'operasional' => 'Operasional'];

    public function isPemasukan(): bool { return $this->jenis === 'pemasukan'; }

    public function category() { return $this->belongsTo(CashCategory::class, 'cash_category_id'); }

    public function creator() { return $this->belongsTo(User::class, 'created_by'); }

    public function payment() { return $this->belongsTo(\App\Models\Payment::class); }

    public function account() { return $this->belongsTo(CashAccount::class, 'account_id'); }

    public function isTransfer(): bool { return (bool) $this->is_transfer; }

    public function scopeReal($query) { return $query->where('is_transfer', false); }
}
