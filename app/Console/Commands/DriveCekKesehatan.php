<?php

namespace App\Console\Commands;

use App\Services\GoogleDriveService;
use App\Services\Notifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Memastikan kredensial Google Drive masih hidup.
 *
 * Drive adalah titik gagal tunggal untuk SELURUH unggahan berkas di aplikasi ini.
 * Ketika refresh token-nya mati, tak ada yang jatuh — unggahan hanya berhenti bekerja,
 * berkas tertahan berstatus 'gagal', dan sebabnya hanya terlihat oleh yang membuka
 * laravel.log. Perintah ini menukar penemuan-oleh-keluhan dengan penemuan-terjadwal.
 */
class DriveCekKesehatan extends Command
{
    protected $signature = 'drive:cek-kesehatan';

    protected $description = 'Memeriksa kredensial Google Drive dan memberi tahu superadmin bila bermasalah';

    public function handle(GoogleDriveService $drive, Notifier $notifier): int
    {
        try {
            $drive->cekKesehatan();
        } catch (\Throwable $e) {
            $this->error('Google Drive TIDAK sehat: ' . $e->getMessage());

            Log::error('Cek kesehatan Google Drive gagal', ['pesan' => $e->getMessage()]);

            // Dipakai ulang dari jalur kegagalan pengiriman: penerimanya sama
            // (superadmin) dan yang dibutuhkan sama — sebabnya, bukan cuma faktanya.
            $notifier->pengirimanGagal(
                'Google Drive',
                'cek kesehatan terjadwal',
                $e->getMessage(),
            );

            return self::FAILURE;
        }

        $this->info('Google Drive sehat.');

        return self::SUCCESS;
    }
}
