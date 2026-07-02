<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Title extends Model
{
    use HasFactory;

    protected $table = 'tb_titles';

    public const JENIS = ['artikel', 'buku'];
    public const TIPE = ['mandiri', 'kolaborasi'];
    public const STATUSES = ['draft', 'menunggu', 'disetujui', 'ditolak'];
    public const INDEKSASI = [
        'none', 'SINTA 1', 'SINTA 2', 'SINTA 3', 'SINTA 4', 'SINTA 5', 'SINTA 6',
        'Scopus Q1', 'Scopus Q2', 'Scopus Q3', 'Scopus Q4', 'Copernicus', 'WoS', 'DOAJ', 'Garuda',
    ];

    protected $fillable = [
        'title', 'code', 'jenis', 'indeksasi', 'tipe_naskah', 'scope_id', 'assigned_to', 'status', 'asal', 'slug',
        'created_by', 'approved_by', 'approved_at', 'reject_note',
        'target_terbit', 'jurnal_target', 'jurnal_link', 'template_link', 'apc_info', 'catatan_publikasi',
    ];

    protected $casts = ['approved_at' => 'datetime', 'target_terbit' => 'date'];

    public function chapters()
    {
        return $this->hasMany(TitleChapter::class)->orderBy('urutan');
    }

    public function scope()
    {
        return $this->belongsTo(Scope::class);
    }

    public function assignedMarketing()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function orderDetails()
    {
        return $this->hasMany(OrderDetail::class, 'title_id');
    }

    public function journalOptions()
    {
        return $this->hasMany(TitleJournalOption::class)->orderBy('urutan');
    }

    public function logs()
    {
        return $this->hasMany(TitleLog::class)->latest('created_at');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'ditolak'], true);
    }

    public function isApproved(): bool
    {
        return $this->status === 'disetujui';
    }
}
