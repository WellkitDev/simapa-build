<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocRequirement extends Model
{
    protected $table = 'tb_doc_requirements';

    protected $fillable = ['category', 'label', 'description', 'position', 'active'];

    protected $casts = ['active' => 'boolean'];

    const CATEGORIES = [
        'penerbit' => 'Dokumen Penerbit (ISBN)',
        'hki'      => 'Dokumen HKI (Hak Cipta)',
    ];

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function marks()
    {
        return $this->hasMany(TitleDocMark::class);
    }
}
