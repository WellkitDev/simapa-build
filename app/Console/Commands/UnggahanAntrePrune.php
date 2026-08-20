<?php

namespace App\Console\Commands;

use App\Models\ManuscriptFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Membersihkan salinan lokal berkas yang tak akan diunggah lagi.
 *
 * Saat unggahan pindah ke queue, berkas mendarat dulu di disk server. Yang berhasil
 * dibersihkan job-nya sendiri; yang GAGAL sengaja ditinggal supaya masih bisa diulang.
 * Tanpa perintah ini, "sengaja ditinggal" berarti selamanya — dan berkas naskah
 * berukuran 20 MB akan menumpuk di hosting sampai kuotanya habis.
 *
 * Ambang 14 hari: cukup lama untuk mengulang unggahan yang gagal karena gangguan
 * sesaat, cukup pendek untuk tidak menahan puluhan berkas besar berbulan-bulan.
 */
class UnggahanAntrePrune extends Command
{
    protected $signature = 'unggahan:prune {--hari=14 : Umur minimum berkas gagal yang dibersihkan}';

    protected $description = 'Menghapus salinan lokal berkas unggahan yang gagal dan sudah lewat batas umur';

    public function handle(): int
    {
        $hari  = max(1, (int) $this->option('hari'));
        $batas = now()->subDays($hari);

        $kandidat = ManuscriptFile::whereNotNull('local_path')
            ->where('status', 'gagal')
            ->where('created_at', '<', $batas)
            ->get();

        $dihapus = 0;
        foreach ($kandidat as $berkas) {
            if (Storage::disk('local')->exists($berkas->local_path)) {
                Storage::disk('local')->delete($berkas->local_path);
                $dihapus++;
            }

            // local_path dikosongkan apa pun hasilnya: berkasnya sudah tak ada, dan
            // menyisakan penunjuk ke berkas yang hilang membuat percobaan ulang gagal
            // dengan pesan yang membingungkan.
            $berkas->forceFill(['local_path' => null])->save();
        }

        $this->info(sprintf('%d salinan lokal dibersihkan dari %d baris gagal (> %d hari).',
            $dihapus, $kandidat->count(), $hari));

        return self::SUCCESS;
    }
}
