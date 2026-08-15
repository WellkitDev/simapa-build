<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookIsbn extends Model
{
    protected $table = 'tb_book_isbns';

    protected $fillable = [
        'title_id', 'status', 'no_pendaftaran', 'no_isbn', 'no_buku_cetak',
        'penerbit', 'tgl_daftar', 'tgl_isbn', 'tgl_terbit', 'catatan', 'link_terbit', 'created_by',
    ];

    protected $casts = [
        'tgl_daftar' => 'date',
        'tgl_isbn'   => 'date',
        'tgl_terbit' => 'date',
    ];

    const STATUSES = [
        'pendaftaran' => 'Pendaftaran',
        'ber_isbn'    => 'Ber-ISBN',
        'cetak'       => 'Cetak/Terbit',
    ];

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /**
     * Berkas ISBN versi terbaru untuk satu slot (`ebook` | `sertifikat_isbn`).
     *
     * Satu query per panggilan — pakai ini di halaman detail saja. Direktori ISBN
     * memuat berkas seluruh baris dalam SATU query di controller; jangan panggil
     * method ini di dalam perulangan.
     */
    public function berkas(string $slot): ?ManuscriptFile
    {
        return ManuscriptFile::where('title_id', $this->title_id)
            ->whereNull('title_chapter_id')
            ->where('slot', $slot)
            ->orderByDesc('version')
            ->first();
    }

    public function title()
    {
        return $this->belongsTo(Title::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
