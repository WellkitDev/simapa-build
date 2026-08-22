<?php

namespace App\Jobs;

use App\Models\ManuscriptFile;
use App\Services\GoogleDriveService;
use App\Services\ManuscriptFileService;
use App\Services\Notifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Mengangkat berkas naskah/ISBN dari disk server ke Google Drive.
 *
 * Dulu ini terjadi di dalam request, sehingga halaman menggantung selama berkas
 * 20 MB dikirim ke jaringan — dan orang yang menunggu tak bisa mengerjakan apa pun.
 *
 * Yang menyertai kepindahan ini: tahap naskah baru boleh maju SESUDAH berkasnya
 * benar-benar mendarat. Memajukannya saat pengiriman baru diantrekan berarti tahap
 * bisa terlanjur maju atas berkas yang kemudian gagal diunggah.
 */
class UnggahBerkasKeDrive implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Drive kadang menolak sesaat; tiga percobaan dengan jeda naik. */
    public $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(protected int $manuscriptFileId) {}

    public function handle(GoogleDriveService $drive): void
    {
        $berkas = ManuscriptFile::find($this->manuscriptFileId);
        if (! $berkas || $berkas->status === 'selesai') {
            return;     // sudah ditangani, atau barisnya keburu dihapus
        }

        if (! $berkas->local_path || ! Storage::disk('local')->exists($berkas->local_path)) {
            throw new \RuntimeException('Salinan lokal berkas tak ditemukan: ' . $berkas->local_path);
        }

        // Folder tujuan menurut slotnya. Null (judul tak ada, atau Drive sedang bermasalah)
        // → uploadFile jatuh ke folder aplikasi seperti perilaku lama. Berkas yang salah
        // tempat masih jauh lebih baik daripada naskah 20 MB yang gagal terunggah.
        $folder = $berkas->title
            ? app(\App\Services\DriveJudulFolderService::class)->folderSlot($berkas->title, $berkas->slot)
            : null;

        $uploaded = $drive->uploadFile(Storage::disk('local')->path($berkas->local_path), $folder, false);

        // uploadFile() menelan exception dan mengembalikan null. Dilempar ulang di sini
        // supaya queue benar-benar mencatatnya gagal dan failed() sempat berjalan —
        // tanpa ini, kegagalan Drive berakhir sebagai job yang "sukses" tanpa berkas.
        if (! $uploaded) {
            throw new \RuntimeException('Google Drive menolak unggahan (sebabnya di laravel.log).');
        }

        $lokal = $berkas->local_path;

        $berkas->forceFill([
            'drive_file_id' => $uploaded['id'] ?? null,
            'drive_url'     => $uploaded['url'] ?? null,
            'status'        => 'selesai',
            'upload_error'  => null,
            'local_path'    => null,
        ])->save();

        Storage::disk('local')->delete($lokal);

        // Baru sekarang tahapnya boleh bergerak.
        app(ManuscriptFileService::class)->majukanTahapSetelahUnggah($berkas);
    }

    public function failed(\Throwable $e): void
    {
        $berkas = ManuscriptFile::find($this->manuscriptFileId);
        if (! $berkas) {
            return;
        }

        $berkas->forceFill([
            'status'       => 'gagal',
            'upload_error' => substr($e->getMessage(), 0, 500),
        ])->save();

        Log::error('Unggahan berkas ke Drive gagal', [
            'manuscript_file_id' => $berkas->id,
            'slot'               => $berkas->slot,
            'pesan'              => $e->getMessage(),
        ]);

        // Kegagalan ikut masuk riwayat naskah, bukan hanya notifikasi. Notifikasi
        // terbaca sekali lalu hilang; riwayat yang menjawab "kenapa berkas ini tak
        // pernah ada" berbulan-bulan kemudian.
        //
        // `uploadedBy` dipakai sebagai pelaku: dialah yang mengirim berkasnya, meski
        // yang gagal adalah jobnya. Bila akunnya sudah terhapus, pencatatan dilewati —
        // job yang gagal tak boleh gagal dua kali.
        if ($berkas->title && $berkas->uploader) {
            app(\App\Services\RiwayatNaskahService::class)->catatJudul(
                $berkas->title,
                'berkas_gagal',
                $berkas->uploader,
                null,
                \App\Models\ManuscriptFile::SLOTS[$berkas->slot] ?? $berkas->slot,
                $berkas->original_name . ' — ' . substr($e->getMessage(), 0, 200)
            );
        }

        // Salinan lokal SENGAJA tidak dihapus: selama ia ada, unggahan masih bisa
        // diulang tanpa meminta orang mengirim ulang berkas 20 MB.
        app(Notifier::class)->unggahanGagal($berkas, $e->getMessage());
    }
}
