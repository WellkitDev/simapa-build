<?php

namespace App\Console\Commands;

use App\Models\TitleProgress;
use App\Services\Notifier;
use Illuminate\Console\Command;

/**
 * Penanda keterlambatan harian: naskah yang lewat SLA pembuatan atau lewat target
 * publish/terbit. Naskah yang sedang ditahan sengaja dilewati — penundaannya sudah
 * disepakati, jadi mengabarinya tiap hari hanya jadi kebisingan.
 *
 * Command ini TIDAK mengisi alasan keterlambatan; alasan wajib diisi manusia lewat
 * Detail Naskah supaya jadi keterangan sungguhan, bukan tebakan sistem.
 */
class NaskahCheckOverdue extends Command
{
    protected $signature = 'naskah:check-overdue {--dry-run : Tampilkan daftar tanpa mengirim notifikasi}';

    protected $description = 'Tandai naskah yang lewat SLA/target dan kirim notifikasi ke PJ, pelaksana, dan superadmin';

    public function handle(Notifier $notifier): int
    {
        $dry = (bool) $this->option('dry-run');

        $terlambat = TitleProgress::with(['orderDetail.order', 'pj', 'pelaksana'])
            ->active()
            ->where('is_on_hold', false)
            ->overdue()
            ->get();

        if ($terlambat->isEmpty()) {
            $this->info('Tidak ada naskah yang lewat tenggat.');

            return self::SUCCESS;
        }

        $baris = [];
        foreach ($terlambat as $p) {
            $tenggat = $p->status === 'pembuatan' ? $p->sla_due_at : $p->target_date;

            $baris[] = [
                $p->orderDetail?->order?->code_order ?? '—',
                $p->stageLabelId(),
                $tenggat?->translatedFormat('j M Y') ?? '—',
                $p->pj?->name ?? '—',
                $p->pelaksana?->name ?? '—',
                $p->overdue_reason ?: 'belum diisi',
            ];

            if (! $dry) {
                $notifier->naskahOverdue($p);
            }
        }

        $this->table(['Kode Order', 'Tahap', 'Jatuh tempo', 'PJ', 'Pelaksana', 'Alasan'], $baris);

        $tanpaAlasan = $terlambat->whereNull('overdue_reason')->count();
        if ($tanpaAlasan > 0) {
            $this->warn("{$tanpaAlasan} naskah terlambat belum punya alasan — isi lewat Detail Naskah.");
        }

        $this->info($dry
            ? $terlambat->count() . ' naskah terlambat (uji coba, notifikasi tidak dikirim).'
            : 'Notifikasi terkirim untuk ' . $terlambat->count() . ' naskah terlambat.');

        return self::SUCCESS;
    }
}
