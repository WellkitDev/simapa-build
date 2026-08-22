<?php

namespace App\Console\Commands;

use App\Services\Notifier;
use App\Services\TaskService;
use Illuminate\Console\Command;

/**
 * Mengirim pengingat tenggang tugas: mendekati → hari-H → lewat.
 *
 * `TaskService::notifyDueSoon()` sudah ada dan benar sejak modul tugas dibuat, tapi TAK
 * ADA SATU PUN yang memanggilnya — tak terjadwal, tak punya perintah. Pengingat tenggang
 * karena itu tak pernah sekali pun berbunyi.
 *
 * Pola yang sama sudah menggigit dua kali di repo ini: Invoice::isOverdue() dan
 * TaskService::notifyDueSoon(). Logika yang benar tanpa pemanggil sama saja dengan tak
 * ada, dan diamnya tak terlihat dari mana pun.
 */
class TasksCheckDeadline extends Command
{
    protected $signature = 'tasks:check-deadline';

    protected $description = 'Kirim pengingat tenggang tugas (mendekati, hari-H, lewat) sekali per tahap';

    public function handle(TaskService $tasks, Notifier $notifier): int
    {
        $terkirim = $tasks->notifyDueSoon($notifier);

        $this->info($terkirim === 0
            ? 'Tak ada pengingat yang perlu dikirim.'
            : "{$terkirim} pengingat tenggang dikirim.");

        return self::SUCCESS;
    }
}
