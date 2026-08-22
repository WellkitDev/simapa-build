<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\InvoiceLog;
use App\Services\Notifier;
use Illuminate\Console\Command;

/**
 * Menandai invoice yang sudah lewat tanggal jatuh tempo.
 *
 * `Invoice::isOverdue()` sudah ada dan benar sejak lama, tapi tak pernah dipanggil
 * siapa pun — status `jatuh_tempo` hanya muncul kalau ada orang menyetelnya tangan.
 * Akibatnya tagihan lewat tempo baru ketahuan kalau seseorang kebetulan membuka
 * daftar invoice dan membandingkan tanggalnya sendiri.
 *
 * Meniru `naskah:check-overdue` yang sudah terbukti: satu perintah, jalan tiap pagi
 * lewat scheduler, dengan jejak dan notifikasi.
 */
class InvoiceCheckOverdue extends Command
{
    protected $signature = 'invoice:check-overdue {--dry-run : Tampilkan daftar tanpa mengubah apa pun}';

    protected $description = 'Tandai invoice yang lewat jatuh tempo dan beri tahu pemilik ordernya';

    public function handle(Notifier $notifier): int
    {
        $ujiCoba = (bool) $this->option('dry-run');

        // Hanya 'diterbitkan' yang bisa jatuh tempo. Yang lunas, dibatalkan, atau
        // sudah ditandai jatuh tempo sengaja dilewati — menandai ulang tiap pagi akan
        // membengkakkan riwayat dengan baris yang tak menyatakan perubahan apa pun.
        $kandidat = Invoice::with('order.user')
            ->where('status', 'diterbitkan')
            ->whereNotNull('due_at')
            ->whereDate('due_at', '<', now()->toDateString())
            ->get();

        if ($kandidat->isEmpty()) {
            $this->info('Tak ada invoice yang lewat jatuh tempo.');

            return self::SUCCESS;
        }

        foreach ($kandidat as $invoice) {
            $this->line(sprintf('%s — jatuh tempo %s', $invoice->invoice_no, $invoice->due_at->format('d M Y')));

            if ($ujiCoba) {
                continue;
            }

            $dari = $invoice->status;
            $invoice->update(['status' => 'jatuh_tempo']);

            // Perubahan status invoice lain semuanya berjejak; yang otomatis justru
            // paling perlu, karena tak ada manusia yang menyaksikannya terjadi.
            InvoiceLog::create([
                'invoice_id'  => $invoice->id,
                'from_status' => $dari,
                'to_status'   => 'jatuh_tempo',
                'changed_by'  => null,
                'note'        => 'Ditandai otomatis oleh invoice:check-overdue.',
            ]);

            // Ke pemilik order (marketing), BUKAN ke klien: ini alat kerja internal.
            // Mengirim email otomatis ke klien adalah keputusan bisnis yang belum
            // diambil siapa pun.
            $notifier->invoiceJatuhTempo($invoice);
        }

        $this->info(sprintf('%s%d invoice diproses.', $ujiCoba ? '[uji coba] ' : '', $kandidat->count()));

        return self::SUCCESS;
    }
}
