<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceCatalog extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'tb_service_catalogs';

    protected $fillable = [
        'category', 'name', 'price', 'price_max', 'unit',
        'description', 'is_active', 'position',
    ];

    protected $casts = [
        'price'     => 'decimal:2',
        'price_max' => 'decimal:2',
        'is_active' => 'boolean',
        'position'  => 'integer',
    ];

    const CATEGORIES = [
        'instalasi'   => 'Layanan Instalasi & Setup',
        'perbaikan'   => 'Layanan Perbaikan',
        'upgrade'     => 'Upgrade & Migrasi',
        'desain'      => 'Desain OJS',
        'hosting'     => 'Hosting OJS (per Tahun)',
        'maintenance' => 'Maintenance',
        'similarity'  => 'Turnitin & Penurunan Plagiasi',
        'bundle'      => 'Paket Bundle',
        'lainnya'     => 'Lainnya',
    ];

    const UNITS = ['paket' => 'Paket', 'bulan' => 'Bulan', 'tahun' => 'Tahun', 'jurnal' => 'Jurnal'];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    /** "Rp 500.000 – Rp 1.000.000" bila berkisar, "Rp 750.000" bila tetap. */
    public function priceLabel(): string
    {
        $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');

        return $this->price_max !== null
            ? $rp($this->price) . ' – ' . $rp($this->price_max)
            : $rp($this->price);
    }
}
