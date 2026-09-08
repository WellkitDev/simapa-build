<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;

/**
 * Menjalankan migrasi yang tertunda, dipanggil penjadwal.
 *
 * Server produksi tak punya terminal — `php artisan migrate` tak bisa dijalankan di
 * sana. Sampai sekarang jalan keluarnya adalah memigrasikan salinan DB di lokal lalu
 * mengunggah dump: tujuh langkah manual yang semuanya harus benar, dan yang sekali
 * terlewat membuat aplikasi 500 pada tabel yang tak ada.
 *
 * Cron produksi tetap SATU baris (`schedule:run`). Perintah ini hidup di dalamnya,
 * bukan sebagai cron kedua — dua cron yang sama-sama memanggil artisan sudah pernah
 * membuat dua worker antrean berebut job di aplikasi ini.
 *
 * TIGA hal yang membuatnya boleh dipercaya berjalan tanpa ditonton:
 *
 * 1. **Diam bila tak ada yang tertunda.** Ia dipanggil berkali-kali sehari; log yang
 *    menulis "tak ada yang perlu dikerjakan" tiap kali akan menenggelamkan satu-satunya
 *    baris yang penting.
 * 2. **Menyebut nama migrasinya**, sebelum dan sesudah. Log yang cuma berkata "berhasil"
 *    tak bisa dipakai memeriksa apa pun.
 * 3. **Gagal dengan bersuara** — pesannya masuk log dan kode keluarnya bukan nol,
 *    sehingga penjadwal tak mencatatnya sebagai sukses.
 *
 * Batas yang disadari: yang dihitung hanya migrasi di `database/migrations`. Migrasi
 * bawaan paket pihak ketiga tak terbaca di sini — di aplikasi ini tak ada satu pun,
 * dan menambahkannya berarti perintah ini harus ikut tahu path setiap paket.
 */
class MigrasiOtomatis extends Command
{
    protected $signature = 'simapa:migrasi-otomatis';

    protected $description = 'Menjalankan migrasi yang tertunda dari penjadwal (server tanpa terminal).';

    public function handle(): int
    {
        // Diambil lewat alias `migrator`, BUKAN type-hint kelasnya: Migrator dibangun
        // dengan MigrationRepositoryInterface yang tak punya ikatan kelas di container,
        // sehingga menyuntikkannya lewat konstruktor/handle gagal
        // "Target [...MigrationRepositoryInterface] is not instantiable".
        $migrator = app('migrator');

        $tertunda = $this->tertunda($migrator);

        if ($tertunda === []) {
            return self::SUCCESS;
        }

        $mulai = microtime(true);
        $this->line('[' . now()->toDateTimeString() . '] ' . count($tertunda) . ' migrasi tertunda:');
        foreach ($tertunda as $nama) {
            $this->line('  · ' . $nama);
        }

        try {
            $kode = $this->call('migrate', ['--force' => true]);
        } catch (\Throwable $e) {
            // Pesannya ditulis apa adanya: galat SQL yang dipendekkan tak pernah cukup
            // untuk tahu migrasi mana yang berhenti di tengah.
            $this->line('  GAGAL: ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($kode !== self::SUCCESS) {
            $this->line('  GAGAL: perintah migrate keluar dengan kode ' . $kode . '.');

            return self::FAILURE;
        }

        // Diperiksa ULANG dari basis data, bukan diasumsikan dari kode keluar. Migrasi
        // yang berhenti separuh jalan bisa saja meninggalkan kode keluar nol, dan log
        // yang berkata "berhasil" atas keadaan yang tak diperiksa lebih buruk daripada
        // tak ada log sama sekali.
        $sisa = $this->tertunda($migrator);
        if ($sisa !== []) {
            $this->line('  GAGAL: ' . count($sisa) . ' migrasi masih tertunda sesudah migrate — ' . implode(', ', $sisa));

            return self::FAILURE;
        }

        $this->line(sprintf(
            '  BERHASIL — %d migrasi mendarat dalam %.2f detik.',
            count($tertunda),
            microtime(true) - $mulai
        ));

        return self::SUCCESS;
    }

    /** Nama migrasi yang ada sebagai berkas tapi belum tercatat di tabel `migrations`. */
    private function tertunda(Migrator $migrator): array
    {
        $berkas = $migrator->getMigrationFiles([database_path('migrations')]);

        // Basis data yang belum pernah dimigrasikan sama sekali: semuanya tertunda.
        if (! $migrator->repositoryExists()) {
            return array_keys($berkas);
        }

        return array_values(array_diff(array_keys($berkas), $migrator->getRepository()->getRan()));
    }
}
