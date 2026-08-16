<?php
// tests/Feature/QueueScheduleTest.php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Produksi berjalan di cPanel yang tak mengizinkan proses permanen, jadi antrian
 * digerakkan cron yang sama dengan scheduler. Tanpa ini seluruh job ShouldQueue —
 * email invoice, refund, slip gaji, invoice layanan — masuk tabel jobs lalu diam
 * selamanya, tanpa satu pun pesan galat.
 */
class QueueScheduleTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function scheduler_menjalankan_queue_work_yang_berhenti_sendiri(): void
    {
        $perintah = collect(app(Schedule::class)->events())
            ->map(fn ($e) => $e->command ?? '')
            ->filter(fn (string $c) => str_contains($c, 'queue:work'))
            ->values();

        $this->assertCount(1, $perintah, 'queue:work harus terjadwal tepat sekali.');
        $this->assertStringContainsString('--stop-when-empty', $perintah[0],
            'Tanpa --stop-when-empty prosesnya jadi daemon, yang tak boleh di shared hosting.');
    }

    /**
     * Sengaja menjalankan worker sungguhan, bukan memeriksa string konfigurasi:
     * membuktikan barisnya ada tidak membuktikan job pernah dieksekusi — dan justru
     * itulah bug yang sedang diperbaiki.
     *
     * @test
     */
    public function job_yang_diantrikan_benar_benar_dieksekusi(): void
    {
        config(['queue.default' => 'database']);
        Cache::forget('bukti-antrian');

        dispatch(function () {
            Cache::put('bukti-antrian', 'jalan', 60);
        });

        // Sebelum worker jalan: job menunggu, efeknya belum ada.
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertNull(Cache::get('bukti-antrian'));

        Artisan::call('queue:work', ['--stop-when-empty' => true, '--quiet' => true]);

        $this->assertSame(0, DB::table('jobs')->count(), 'Job harus habis dikerjakan.');
        $this->assertSame('jalan', Cache::get('bukti-antrian'));
    }
}
