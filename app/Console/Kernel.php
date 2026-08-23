<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    /**
     * Butuh satu cron di server: `* * * * * php artisan schedule:run`.
     * Tanpa itu tidak ada satu pun tugas di bawah yang pernah berjalan.
     *
     * Keluaran tiap tugas ditulis ke berkas log masing-masing (appendOutputTo),
     * bukan dibiarkan mengalir ke email cron: cron berjalan tiap menit, jadi
     * membiarkannya berbicara akan menenggelamkan kotak masuk dengan "No scheduled
     * commands are ready to run." — dan justru membuat pesan yang penting terlewat.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('idempotency:prune')
            ->daily()
            ->appendOutputTo(storage_path('logs/schedule-idempotency.log'));

        // Pagi hari kerja: sebelum orang membuka Meja Kerja, keterlambatan sudah
        // ketahuan dan PJ/pelaksana sudah dikabari. withoutOverlapping menjaga agar
        // proses yang tertahan lama tak ditumpuk jalan kedua.
        $schedule->command('naskah:check-overdue')
            ->dailyAt('07:00')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/naskah-overdue.log'));

        // Produksi berjalan di cPanel yang tak mengizinkan proses permanen, jadi
        // antrian digerakkan cron yang sama dengan scheduler. --stop-when-empty
        // membuatnya mati begitu antrian habis (bukan daemon), --max-time menjamin
        // ia berhenti sebelum menit berikutnya memanggil lagi.
        //
        // Tanpa baris ini seluruh job ShouldQueue — email invoice, refund, slip gaji,
        // invoice layanan — masuk tabel `jobs` lalu diam selamanya tanpa pesan galat.
        // Berkas yang gagal diunggah meninggalkan salinan lokal supaya bisa diulang.
        // Tanpa pembersihan berkala, "bisa diulang" berarti menumpuk selamanya —
        // dan berkas naskah 20 MB akan menghabiskan kuota hosting.
        $schedule->command('unggahan:prune')
            ->weekly()
            ->appendOutputTo(storage_path('logs/unggahan-prune.log'));

        // Keterlambatan naskah sudah ketahuan sendiri tiap pagi; tagihan lewat tempo
        // tak punya padanannya sampai sekarang. Jam yang sama supaya keduanya terbaca
        // dalam satu duduk.
        $schedule->command('invoice:check-overdue')
            ->dailyAt('07:00')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/invoice-overdue.log'));

        // Drive = titik gagal tunggal untuk SELURUH unggahan berkas. Token yang mati
        // tak menjatuhkan apa pun; unggahan cuma berhenti bekerja. Sekali sehari cukup
        // untuk menemukannya lebih dulu daripada pengguna, tanpa jadi kebisingan.
        //
        // Sengaja SESUDAH jam kerja dimulai (08:00): notifikasinya perlu dibaca orang
        // di hari yang sama, bukan menumpuk semalaman.
        $schedule->command('drive:cek-kesehatan')
            ->dailyAt('08:00')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/drive-kesehatan.log'));

        // Berbarengan dengan naskah:check-overdue: satu sapuan tenggang di pagi hari
        // kerja, sebelum orang membuka papannya.
        //
        // TaskService::notifyDueSoon() sudah ada sejak modul tugas dibuat tapi tak pernah
        // dipanggil siapa pun — pengingat tenggang karena itu tak pernah sekali pun
        // berbunyi. Baris inilah yang menghidupkannya.
        $schedule->command('tasks:check-deadline')
            ->dailyAt('07:05')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/tasks-deadline.log'));

        // --timeout=300 (bukan bawaan 60 detik): mengirim berkas 20 MB ke Google Drive
        // dari hosting bersama lazim melewati satu menit, dan worker yang membunuh
        // jobnya sendiri terlihat persis seperti unggahan yang menggantung selamanya.
        // Nilainya WAJIB lebih kecil dari `retry_after` di config/queue.php, kalau
        // tidak job yang masih berjalan dianggap terbengkalai dan diambil worker kedua.
        //
        // withoutOverlapping(10) — BUKAN tanpa argumen. Bawaannya 1.440 menit alias
        // DUA PULUH EMPAT JAM: satu worker yang mati tanpa sempat melepas kuncinya
        // (proses dibunuh hosting, unggahan menggantung, deploy di tengah jalan)
        // membekukan seluruh antrean sehari penuh, dengan cron tetap berbunyi tiap
        // menit dan tetap dilewati diam-diam. Sepuluh menit cukup panjang untuk
        // menampung unggahan terlama, cukup pendek untuk sembuh sendiri.
        $schedule->command('queue:work --stop-when-empty --max-time=50 --timeout=300 --tries=3')
            ->everyMinute()
            ->withoutOverlapping(10)
            ->appendOutputTo(storage_path('logs/queue-work.log'));

        // Jaring pengaman terakhir: job yang HILANG tanpa pernah gagal secara resmi
        // meninggalkan barisnya di `antre` selamanya, dan di server tanpa terminal tak
        // ada seorang pun yang bisa membangunkannya. Lihat UnggahanBangkitkan.
        $schedule->command('unggahan:bangkitkan')
            ->everyTenMinutes()
            ->withoutOverlapping(10)
            ->appendOutputTo(storage_path('logs/unggahan-bangkitkan.log'));
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
