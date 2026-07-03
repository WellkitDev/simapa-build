<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TitleArchive extends Model
{
    protected $table = 'tb_title_archives';

    protected $fillable = ['title_id', 'status', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'approval_note', 'reject_note'];

    protected $casts = ['submitted_at' => 'datetime', 'approved_at' => 'datetime'];

    const STATUSES = ['draft' => 'Draft', 'diajukan' => 'Diajukan', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak'];

    const BOOK_ARTIFACTS = [
        'isbn'            => ['label' => 'No. ISBN',                   'type' => 'text'],
        'barcode_file'    => ['label' => 'File Barcode',              'type' => 'file'],
        'publish_link'    => ['label' => 'Link Buku Publish',         'type' => 'link'],
        'scholar_link'    => ['label' => 'Link Scholar',              'type' => 'link'],
        'hki_file'        => ['label' => 'File HKI',                  'type' => 'file'],
        'final_book_file' => ['label' => 'File Buku Final (ber-ISBN)', 'type' => 'file'],
    ];
    const ARTICLE_ARTIFACTS = [
        'loa'          => ['label' => 'LoA',             'type' => 'file'],
        'publish_link' => ['label' => 'Link Publish',    'type' => 'link'],
        'final_naskah' => ['label' => 'Naskah Final',    'type' => 'file'],
        'apc_bukti'    => ['label' => 'Bukti Bayar APC', 'type' => 'file'],
    ];

    public static function artifactsFor(string $jenis): array
    {
        return $jenis === 'buku' ? self::BOOK_ARTIFACTS : self::ARTICLE_ARTIFACTS;
    }

    public function statusLabel(): string { return self::STATUSES[$this->status] ?? $this->status; }
    public function title() { return $this->belongsTo(Title::class); }
    public function submitter() { return $this->belongsTo(User::class, 'submitted_by'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }
}
