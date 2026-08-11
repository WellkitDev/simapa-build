<?php

namespace Database\Seeders;

use App\Models\ServiceCatalog;
use Illuminate\Database\Seeder;

/**
 * Daftar harga jasa OJS yang berlaku. firstOrCreate berdasarkan category+name,
 * jadi aman dijalankan ulang dan TIDAK menimpa harga yang sudah disunting operator.
 *
 * Kategori 'similarity' (Turnitin & penurunan plagiasi) sengaja kosong: tarifnya
 * belum ditetapkan, diisi lewat CRUD katalog tanpa perlu deploy.
 */
class ServiceCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // [nama, harga, harga_maks, satuan, deskripsi]
        $rows = [
            'instalasi' => [
                ['Instalasi OJS Basic',           500000,  null,     'paket', null],
                ['Instalasi + Konfigurasi OJS',   750000,  null,     'paket', null],
                ['Instalasi + Desain Tampilan',   1250000, null,     'paket', null],
                ['Setup Lengkap Jurnal',          2500000, null,     'paket', null],
                ['Setup Multi Jurnal',            3500000, 5000000,  'paket', null],
            ],
            'perbaikan' => [
                ['Fix Error Ringan',              250000,  500000,   'paket', null],
                ['Fix Error Sedang',              500000,  1000000,  'paket', null],
                ['Fix Error Berat',               1000000, 2500000,  'paket', null],
                ['Perbaikan SMTP',                350000,  null,     'paket', null],
                ['Perbaikan DOI Crossref',        500000,  null,     'paket', null],
                ['Perbaikan PKP PN',              500000,  null,     'paket', null],
                ['Pembersihan Malware',           750000,  2000000,  'paket', null],
            ],
            'upgrade' => [
                ['Upgrade Minor',                 750000,  null,     'paket', null],
                ['Upgrade Mayor',                 1500000, 3000000,  'paket', 'Mis. 3.2 → 3.3, 3.3 → 3.4'],
                ['Migrasi Hosting',               1000000, 2500000,  'paket', null],
                ['Migrasi VPS',                   1500000, 3500000,  'paket', null],
            ],
            'desain' => [
                ['Redesign Homepage',             750000,  null,     'paket', null],
                ['Custom Homepage Premium',       1500000, null,     'paket', null],
                ['Desain Logo Jurnal',            250000,  null,     'paket', null],
                ['Custom Theme OJS',              2500000, 5000000,  'paket', null],
            ],
            'hosting' => [
                ['Starter (5GB)',                 750000,  null,     'tahun', null],
                ['Standard (10GB)',               1250000, null,     'tahun', null],
                ['Professional (25GB)',           2500000, null,     'tahun', null],
                ['VPS Managed',                   4500000, 12000000, 'tahun', null],
            ],
            'maintenance' => [
                ['Maintenance Bulanan',           300000,  null,     'bulan', null],
                ['Maintenance Semester',          1500000, null,     'paket', null],
                ['Maintenance Tahunan',           2500000, null,     'tahun', null],
            ],
            'bundle' => [
                ['Paket Starter',      1750000, null, 'tahun',
                 'Domain .com/.or.id · Hosting 5 GB · Instalasi OJS · SSL · Setup SMTP · Konsultasi ISSN'],
                ['Paket Professional', 3500000, null, 'tahun',
                 'Domain · Hosting 10 GB · Instalasi OJS · Desain Homepage · Setup DOI · SMTP · Support 1 Tahun'],
                ['Paket Enterprise',   6500000, null, 'tahun',
                 'Domain · Hosting 25 GB · Hingga 5 Jurnal · Desain Premium · DOI & PKP PN · Maintenance 1 Tahun · Prioritas Support'],
            ],
        ];

        foreach ($rows as $category => $items) {
            foreach ($items as $position => [$name, $price, $priceMax, $unit, $description]) {
                ServiceCatalog::firstOrCreate(
                    ['category' => $category, 'name' => $name],
                    [
                        'price'       => $price,
                        'price_max'   => $priceMax,
                        'unit'        => $unit,
                        'description' => $description,
                        'is_active'   => true,
                        'position'    => $position,
                    ]
                );
            }
        }
    }
}
