<?php

namespace App\Console\Commands;

use App\Jobs\UnggahBerkasKeDrive;
use App\Models\ManuscriptFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Membangunkan unggahan yang tertinggal di `antre` tanpa ada yang mengerjakannya.
 *
 * Status `antre` seharusnya sementara: job mengangkat berkasnya ke Drive lalu
 * menandainya `selesai`, atau `failed()` menandainya `gagal`. Ada satu lubang di
 * antara keduanya — job yang HILANG tanpa pernah gagal secara resmi:
 *
 *   - worker dibunuh hosting di tengah unggahan, jadi `failed()` tak pernah berjalan;
 *   - tabel `jobs` terganti saat basis data diimpor ulang;
 *   - kunci anti-tumpang-tindih scheduler menahan worker sampai jobnya kedaluwarsa.
 *
 * Dalam ketiga hal itu barisnya duduk di `antre` SELAMANYA. Layar terus menjanjikan
 * "sedang diunggah…", tak ada galat di mana pun, dan di server tanpa terminal tak ada
 * seorang pun yang bisa membangunkannya. Perintah ini jaring pengaman terakhirnya.
 *
 * Yang SUDAH `gagal` sengaja tak disentuh: ia sudah punya jalannya sendiri — tercatat
 * di riwayat, pemiliknya sudah dikabari. Mengulangnya otomatis hanya membuat kegagalan
 * yang sama berputar tanpa henti.
 */
class UnggahanBangkitkan extends Command
{
    protected $signature = 'unggahan:bangkitkan
                            {--menit=15 : Umur minimum baris antre yang dianggap tertinggal}';

    protected $description = 'Mengantrekan ulang unggahan yang tertinggal di status antre';

    public function handle(): int
    {
        // Ambang bawah 5 menit menjaga perintah ini dari melangkahi antrean yang
        // sedang sibuk: job yang baru menunggu gilirannya bukan job yang hilang.
        $menit = max(5, (int) $this->option('menit'));

        $tertinggal = ManuscriptFile::where('status', 'antre')
            ->where('created_at', '<', now()->subMinutes($menit))
            ->orderBy('id')
            ->get();

        if ($tertinggal->isEmpty()) {
            $this->info("Tidak ada unggahan yang tertinggal lebih dari {$menit} menit.");

            return self::SUCCESS;
        }

        $diulang = 0;
        $mati    = 0;

        foreach ($tertinggal as $berkas) {
            // Tanpa salinan lokal, mengantrekannya lagi hanya menghasilkan job yang
            // pasti gagal. Lebih jujur menandainya gagal sekarang daripada membiarkan
            // layar menjanjikan unggahan yang tak mungkin terjadi.
            if (! $berkas->local_path || ! Storage::disk('local')->exists($berkas->local_path)) {
                $berkas->forceFill([
                    'status'       => 'gagal',
                    'upload_error' => 'Salinan lokal berkas sudah tidak ada, unggahan tak bisa diulang.',
                ])->save();

                $this->line("  <fg=red>mati</> {$berkas->original_name} — salinan lokalnya sudah hilang");
                $mati++;
                continue;
            }

            // Job yang lama mungkin masih hidup di tabel `jobs`. Itu tak apa: handle()
            // berhenti lebih awal bila statusnya sudah `selesai`, jadi job kembar
            // paling banter mengunggah sekali lalu yang kedua tak berbuat apa-apa.
            UnggahBerkasKeDrive::dispatch($berkas->id);

            $this->line("  <fg=green>diulang</> {$berkas->original_name} (antre sejak {$berkas->created_at})");
            $diulang++;
        }

        if ($diulang > 0 || $mati > 0) {
            Log::warning('Unggahan tertinggal dibangkitkan ulang', [
                'diulang' => $diulang,
                'mati'    => $mati,
                'ambang'  => $menit . ' menit',
            ]);
        }

        $this->newLine();
        $this->info("{$diulang} unggahan diantrekan ulang, {$mati} ditandai gagal.");

        return self::SUCCESS;
    }
}
