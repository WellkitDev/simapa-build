<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashEntry extends Model
{
    protected $table = 'tb_cash_entries';

    protected $fillable = ['tanggal', 'kode', 'keterangan', 'jenis', 'amount', 'cash_category_id', 'produk', 'ref', 'catatan', 'source', 'created_by'];

    protected $casts = ['tanggal' => 'date', 'amount' => 'decimal:2'];

    const PRODUK = ['artikel' => 'Artikel', 'buku' => 'Buku', 'operasional' => 'Operasional'];

    public function isPemasukan(): bool { return $this->jenis === 'pemasukan'; }

    public function category() { return $this->belongsTo(CashCategory::class, 'cash_category_id'); }

    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
