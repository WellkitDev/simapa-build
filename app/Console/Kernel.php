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
