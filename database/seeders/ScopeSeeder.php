<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class ScopeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //
        $now = Carbon::now();

        $scopes = [
            // Teknologi & Komputer
            'Teknologi Informasi',
            'Ilmu Komputer',
            'Sistem Informasi',
            'Rekayasa Perangkat Lunak',
            'Kecerdasan Buatan',
            'Machine Learning',
            'Deep Learning',
            'Data Science',
            'Big Data Analytics',
            'Internet of Things (IoT)',
            'Keamanan Siber',
            'Kriptografi',
            'Jaringan Komputer',
            'Cloud Computing',
            'Blockchain',
            'Komputasi Terdistribusi',
            'Human Computer Interaction',
            'Multimedia dan Game',
            'Pengolahan Citra Digital',
            'Sistem Cerdas',

            // Teknik & Sains Terapan
            'Teknik Informatika',
            'Teknik Elektro',
            'Teknik Industri',
            'Teknik Mesin',
            'Teknik Sipil',
            'Teknik Lingkungan',
            'Teknik Biomedis',
            'Robotika dan Otomasi',
            'Mechatronics',
            'Energi Terbarukan',

            // Pendidikan
            'Pendidikan Teknologi',
            'Pendidikan Informatika',
            'Pendidikan Matematika',
            'Pendidikan IPA',
            'Pendidikan Bahasa',
            'Pendidikan Vokasi',
            'Kurikulum dan Pembelajaran',
            'Evaluasi Pendidikan',
            'Manajemen Pendidikan',

            // Sosial & Humaniora
            'Ilmu Komunikasi',
            'Ilmu Sosial',
            'Sosiologi',
            'Psikologi',
            'Filsafat',
            'Antropologi',
            'Sejarah',
            'Kajian Budaya',
            'Bahasa dan Sastra',
            'Kajian Media dan Jurnalistik',

            // Ekonomi & Bisnis
            'Ekonomi',
            'Ekonomi Pembangunan',
            'Manajemen',
            'Akuntansi',
            'Keuangan',
            'Perbankan dan Keuangan Syariah',
            'Kewirausahaan',
            'Bisnis Digital',
            'Manajemen UMKM',
            'Ekonomi Kreatif',

            // Hukum & Kebijakan
            'Ilmu Hukum',
            'Hukum Perdata',
            'Hukum Pidana',
            'Hukum Bisnis',
            'Hukum Teknologi Informasi',
            'Kebijakan Publik',
            'Administrasi Publik',
            'Tata Kelola Pemerintahan',

            // Pertanian, Lingkungan, & Pangan
            'Pertanian',
            'Agroteknologi',
            'Agribisnis',
            'Peternakan',
            'Perikanan',
            'Teknologi Pangan',
            'Ketahanan Pangan',
            'Lingkungan Hidup',
            'Konservasi Sumber Daya Alam',
            'Perubahan Iklim',
            'Pembangunan Berkelanjutan',

            // Kesehatan (non-medis detail)
            'Kesehatan Masyarakat',
            'Gizi',
            'Farmasi',
            'Teknologi Kesehatan',
            'Manajemen Rumah Sakit',

            // Seni & Humaniora Terapan
            'Seni Rupa',
            'Desain Komunikasi Visual',
            'Desain Produk',
            'Seni Pertunjukan',
            'Film dan Televisi',
            'Fotografi',
            'Musik dan Etnomusikologi',
        ];

        foreach ($scopes as $scope) {
            DB::table('tb_scopes')->insert([
                'scope' => $scope,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
