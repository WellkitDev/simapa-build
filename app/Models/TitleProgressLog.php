<?php
// app/Models/TitleProgressLog.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class TitleProgressLog extends Model
{
    use HasFactory;

    protected $table = 'tb_title_progress_logs';

    public $timestamps = false;

    protected $fillable = [
        'title_progress_id', 'event', 'from_value', 'to_value',
        'changed_by', 'note', 'is_correction',
    ];

    protected $casts = [
        'is_correction' => 'boolean',
        'created_at'    => 'datetime',
    ];

    const EVENT_LABELS = [
        'status_advanced'  => 'Maju tahap',
        'status_corrected' => 'Koreksi / Lompat',
        'editor_assigned'  => 'Tugaskan editor',
        'priority_changed' => 'Ubah prioritas',
        'target_set'       => 'Set target terbit',
        'reviewed'         => 'Tandai ditinjau',
        // Penugasan Naskah
        'distribusi'          => 'Distribusi tugas',
        'claim'               => 'Ambil tugas',
        'tarik_tugas'         => 'Tarik tugas',
        'oper_pj'             => 'Oper penanggung jawab',
        'hold'                => 'Tahan sementara',
        'unhold'              => 'Lanjutkan kembali',
        'dibatalkan'          => 'Batalkan naskah',
        'diarsipkan'          => 'Pindah ke arsip',
        'overdue_reason_set'  => 'Alasan keterlambatan',
        'auto_advance_upload' => 'Maju otomatis (upload naskah)',
        'chapters_done'       => 'Semua bab selesai',
        'penarikan'           => 'Ditarik (refund)',
        'batal_penarikan'     => 'Batalkan penarikan',
        // Berkas & putaran perbaikan. Sebelum 2026-08-23 tak satu pun tercatat: unggahan
        // hanya muncul di riwayat bila kebetulan memicu maju tahap, sehingga unggah hasil
        // editing, LoA, cover, berkas ISBN, dan berkas revisi tak meninggalkan jejak
        // siapa pun — padahal kartunya berjanji "semua aksi tercatat".
        'berkas_diunggah'     => 'Unggah berkas',
        'berkas_mendarat'     => 'Berkas masuk Drive',
        'berkas_gagal'        => 'Unggahan gagal',
        'revisi_diminta'      => 'Minta revisi',
        'revisi_dijawab'      => 'Jawab revisi',
        'revisi_ditutup'      => 'Tutup putaran revisi',
    ];

    public function eventLabel(): string
    {
        return self::EVENT_LABELS[$this->event] ?? Str::title(str_replace('_', ' ', (string) $this->event));
    }

    public function titleProgress()
    {
        return $this->belongsTo(TitleProgress::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
