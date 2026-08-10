<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocRequirement extends Model
{
    protected $table = 'tb_doc_requirements';

    protected $fillable = ['category', 'label', 'description', 'position', 'active', 'auto_source'];

    protected $casts = ['active' => 'boolean'];

    const CATEGORIES = [
        'penerbit' => 'Dokumen Penerbit (ISBN)',
        'hki'      => 'Dokumen HKI (Hak Cipta)',
    ];

    /**
     * Item yang dipenuhi otomatis dari berkas yang sudah diunggah di tempat lain,
     * bukan diunggah ulang di sini. Nilainya = slot ManuscriptFile sumbernya.
     */
    const AUTO_SOURCES = [
        'naskah_final' => 'Naskah Final di Pelacakan Naskah',
    ];

    /** Item ini mengambil berkasnya dari modul lain — tak punya kotak unggah sendiri. */
    public function isAuto(): bool
    {
        return $this->auto_source !== null
            && array_key_exists($this->auto_source, self::AUTO_SOURCES);
    }

    public function autoSourceLabel(): ?string
    {
        return self::AUTO_SOURCES[$this->auto_source] ?? null;
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function marks()
    {
        return $this->hasMany(TitleDocMark::class);
    }
}
