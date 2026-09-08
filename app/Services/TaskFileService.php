<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Lampiran tugas: menaruh berkas di Drive, mencatat barisnya, dan menuliskan jejaknya
 * di utas.
 *
 * Sinkron, bukan lewat antrean job. Berkas sebesar ini sudah ditangani begitu di
 * Laporan Harian selama setahun; antrean baru berguna kalau ukurannya naik seperti
 * naskah (lihat `ManuscriptFileService`).
 */
class TaskFileService
{
    /** Satu folder per tugas, supaya berkasnya bisa ditemukan tanpa membuka aplikasi. */
    private const FOLDER = 'SiMAPA/Tugas/';

    public function __construct(
        private GoogleDriveService $drive,
        private TaskThreadService $utas,
    ) {}

    /**
     * Menaruh satu berkas pada sebuah tugas. Mengembalikan null bila Drive menolak.
     *
     * Kegagalan Drive dikembalikan sebagai null, bukan exception: pemanggilnya perlu
     * menjawab Dropzone dengan pesan yang bisa dibaca orang, bukan halaman galat.
     */
    public function unggah(Task $task, UploadedFile $file, User $aktor): ?TaskFile
    {
        $folder = $this->drive->getOrCreateFolderByPath(self::FOLDER . $task->id);
        if (! $folder) {
            return null;
        }

        $uploaded = $this->drive->uploadFile($file, $folder, true);
        if (! $uploaded) {
            return null;
        }

        $berkas = TaskFile::create([
            'task_id'       => $task->id,
            'drive_file_id' => $uploaded['id'],
            'name'          => $uploaded['name'] ?? $file->getClientOriginalName(),
            'url'           => $uploaded['url'] ?? '',
            'mime'          => $file->getClientMimeType(),
            'size'          => $file->getSize(),
            'uploaded_by'   => $aktor->id,
        ]);

        // Utas adalah tempat orang membaca apa yang terjadi pada sebuah tugas. Berkas
        // yang mendarat tanpa jejak di sana akan tampak muncul sendiri.
        $this->utas->catat($task, 'Melampirkan berkas “' . $berkas->name . '”.', $aktor);

        return $berkas;
    }

    /**
     * Mencabut satu lampiran.
     *
     * Berkasnya yang hilang, bukan jejaknya: entri pencabutan tetap ditulis, dan entri
     * pelampirannya tak pernah disunting — utas ini append-only.
     */
    public function hapus(TaskFile $berkas, User $aktor): void
    {
        $task = $berkas->task;
        $nama = $berkas->name;

        // Kegagalan Drive tak boleh menggagalkan pencabutan: barisnya yang jadi acuan
        // aplikasi, dan berkas yatim di Drive lebih murah daripada baris yang tak bisa
        // dihapus. Pola yang sama dipakai DailyReportController::destroyFile().
        if (! $this->drive->deleteFile($berkas->drive_file_id)) {
            Log::warning('Gagal menghapus berkas tugas di Drive: ' . $berkas->drive_file_id);
        }

        $berkas->delete();

        $this->utas->catat($task, 'Mencabut lampiran “' . $nama . '”.', $aktor);
    }
}
