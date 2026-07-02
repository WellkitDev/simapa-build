<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JournalSubmission extends Model
{
    use HasFactory;

    protected $table = 'tb_journal_submissions';

    public const STATUSES = ['submitted', 'loa', 'published'];

    protected $fillable = [
        'journal_id', 'title_id', 'tgl_submit', 'tgl_terbit', 'ojs_akun', 'ojs_password',
        'loa_url', 'bukti_bayar_url', 'link_publish', 'status', 'catatan', 'created_by',
    ];

    protected $casts = [
        'ojs_password' => 'encrypted',
        'tgl_submit'   => 'date',
        'tgl_terbit'   => 'date',
    ];

    public function journal()
    {
        return $this->belongsTo(Journal::class);
    }

    public function title()
    {
        return $this->belongsTo(Title::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function statusLabel(): string
    {
        return ['submitted' => 'Submitted', 'loa' => 'LoA', 'published' => 'Published'][$this->status] ?? ucfirst($this->status);
    }
}
