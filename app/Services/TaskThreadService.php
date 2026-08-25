<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskUpdate;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Utas aktivitas sebuah tugas: laporan orang dan peristiwa sistem dalam satu urutan.
 *
 * Sampai sekarang sebuah tugas cuma kotak centang pribadi. Pemberi tugas tak punya cara
 * tahu apa pun selain `todo/in_progress/done`, dan pelaksananya tak punya tempat
 * bercerita — sehingga satu-satunya jalan mengabari kemajuan adalah menemui orangnya.
 *
 * Yang membuat sebuah record hidup di aplikasi POS dan CRM adalah utas aktivitasnya,
 * bukan kolom statusnya. Kelas ini yang menulis utas itu.
 */
class TaskThreadService
{
    public function __construct(private Notifier $notifier) {}

    /**
     * Laporan dari manusia. Mengembalikan entri yang tercatat.
     *
     * `progress` boleh null — tak setiap laporan perlu angka, dan memaksa persentase
     * membuat orang mengarang. Bila diisi, ia ikut disalin ke tugasnya supaya papan
     * bisa menampilkan bilah kemajuan tanpa membaca seluruh utas tiap kartu.
     */
    public function laporkan(Task $task, User $pelapor, string $isi, ?int $progress = null): TaskUpdate
    {
        $isi = trim($isi);
        $progress = $progress === null ? null : max(0, min(100, $progress));

        $entri = DB::transaction(function () use ($task, $pelapor, $isi, $progress) {
            $entri = TaskUpdate::create([
                'task_id'    => $task->id,
                'user_id'    => $pelapor->id,
                'kind'       => TaskUpdate::LAPORAN,
                'body'       => $isi,
                'progress'   => $progress,
                'created_at' => now(),
            ]);

            // Kemajuan yang dilaporkan naik ke tugasnya. TIDAK diturunkan otomatis saat
            // laporan berikutnya tak menyebut angka: diam bukan berarti mundur.
            if ($progress !== null && $progress !== $task->progress) {
                $task->forceFill(['progress' => $progress])->save();
            }

            return $entri;
        });

        $this->kabari($task, $pelapor, $isi);

        return $entri;
    }

    /**
     * Peristiwa yang dicatat aplikasi, bukan orang.
     *
     * `$aktor` boleh null untuk yang lahir dari penjadwal — menautkannya ke akun mana
     * pun akan berbohong tentang siapa yang melakukannya.
     */
    public function catat(Task $task, string $isi, ?User $aktor = null): TaskUpdate
    {
        return TaskUpdate::create([
            'task_id'    => $task->id,
            'user_id'    => $aktor?->id,
            'kind'       => TaskUpdate::SISTEM,
            'body'       => $isi,
            'created_at' => now(),
        ]);
    }

    /**
     * Mengabari pihak SEBERANG, bukan semua orang.
     *
     * Pelaksana melapor → pemberi tugas yang perlu tahu. Pemberi tugas menulis →
     * pelaksananya. Mengirim ke keduanya berarti separuh notifikasi memberi tahu orang
     * tentang tulisannya sendiri, dan notifikasi yang selalu bisa diabaikan akan
     * selalu diabaikan.
     */
    private function kabari(Task $task, User $penulis, string $isi): void
    {
        $task->loadMissing(['user', 'creator']);

        $tujuan = $penulis->id === $task->user_id
            ? $task->creator
            : $task->user;

        if (! $tujuan || $tujuan->id === $penulis->id) {
            return;
        }

        $this->notifier->taskDilaporkan($task, $tujuan, $penulis, $isi);
    }

    /**
     * Ringkasan untuk kepala halaman detail: kapan terakhir dilaporkan, dan oleh siapa.
     *
     * @return array{jumlah:int, terakhir:?TaskUpdate}
     */
    public function ringkasan(Task $task): array
    {
        $laporan = $task->updates->where('kind', TaskUpdate::LAPORAN);

        return [
            'jumlah'   => $laporan->count(),
            'terakhir' => $laporan->last(),
        ];
    }
}
