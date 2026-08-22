<?php

namespace App\Console\Commands;

use App\Models\ManuscriptFile;
use App\Models\Title;
use App\Services\DriveJudulFolderService;
use App\Services\GoogleDriveService;
use Illuminate\Console\Command;

/**
 * Memindahkan berkas naskah yang sudah telanjur rata di folder aplikasi ke folder
 * judulnya masing-masing.
 *
 * BAWAANNYA MENGINTIP, BUKAN MENJALANKAN. Menjalankan perintah ini tanpa argumen apa
 * pun tak memindahkan satu berkas pun — perintah yang berbahaya secara bawaan adalah
 * perintah yang suatu saat dijalankan orang yang cuma ingin melihat.
 *
 * Berkas dipindah dengan mengganti INDUKNYA, bukan diunggah ulang: id dan URL berkas
 * tak berubah, sehingga seluruh tautan Drive yang sudah tersimpan di basis data tetap
 * hidup sesudah perapian.
 */
class DriveRapikan extends Command
{
    protected $signature = 'simapa:drive-rapikan
                            {--apply : Benar-benar pindahkan; tanpa ini hanya menampilkan rencana}
                            {--judul= : Batasi ke id judul tertentu, dipisah koma}';

    protected $description = 'Rapikan berkas Drive ke folder per judul (bawaan: hanya menampilkan rencana)';

    public function handle(GoogleDriveService $drive, DriveJudulFolderService $folder): int
    {
        $apply = (bool) $this->option('apply');

        if (! $apply) {
            $this->warn('MODE INTIP — tak ada yang diubah. Tambahkan --apply untuk menjalankan.');
        }

        $berkas = ManuscriptFile::query()
            ->whereNotNull('drive_file_id')
            ->when($this->option('judul'), fn ($q, $ids) => $q->whereIn(
                'title_id',
                array_filter(array_map('intval', explode(',', $ids)))
            ))
            ->orderBy('title_id')->orderBy('slot')
            ->get();

        $judulCache = [];
        $pindah = 0;
        $lewat  = 0;
        $gagal  = 0;

        foreach ($berkas as $b) {
            $judul = $judulCache[$b->title_id]
                ??= Title::find($b->title_id);

            if (! $judul) {
                $this->line("  <fg=yellow>lewat</> {$b->original_name} — judulnya sudah terhapus");
                $lewat++;
                continue;
            }

            $jalur = DriveJudulFolderService::jalurSlot($b->slot);
            $tujuanLabel = DriveJudulFolderService::namaFolder($judul) . '/' . ($jalur ?? '');

            if (! $apply) {
                $this->line("  {$tujuanLabel} <fg=gray>←</> {$b->original_name}");
                $pindah++;
                continue;
            }

            $tujuan = $folder->folderSlot($judul, $b->slot);
            if (! $tujuan) {
                $this->line("  <fg=red>gagal</> {$b->original_name} — folder tujuan tak bisa disiapkan");
                $gagal++;
                continue;
            }

            if ($drive->moveFile($b->drive_file_id, $tujuan)) {
                $this->line("  <fg=green>pindah</> {$tujuanLabel} ← {$b->original_name}");
                $pindah++;
            } else {
                $this->line("  <fg=red>gagal</> {$b->original_name} — Drive menolak pemindahan");
                $gagal++;
            }
        }

        // Berkas yang belum mendarat di Drive sengaja tak dihitung sebagai kegagalan:
        // ia memang belum ada di sana, dan jalur antre yang akan menaruhnya di tempat
        // yang benar sejak awal.
        $antre = ManuscriptFile::whereNull('drive_file_id')->count();

        $this->newLine();
        $this->info($apply
            ? "Selesai: {$pindah} dipindahkan, {$lewat} dilewati, {$gagal} gagal."
            : "Rencana: {$pindah} akan dipindahkan, {$lewat} dilewati. Jalankan ulang dengan --apply.");

        if ($antre > 0) {
            $this->line("<fg=gray>{$antre} berkas belum ada di Drive (antre/gagal unggah) — tak disentuh.</>");
        }

        return $gagal > 0 ? self::FAILURE : self::SUCCESS;
    }
}
