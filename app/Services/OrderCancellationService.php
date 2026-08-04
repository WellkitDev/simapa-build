<?php

namespace App\Services;

use App\Exceptions\OrderCancellationException;
use App\Models\InvoiceLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Tagihan;
use App\Models\TagihanLog;
use App\Models\TitleProgress;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pembatalan & pemulihan order.
 *
 * Semua penghapusan bersifat SOFT: nomor ORD-xxxx tidak pernah dipakai ulang, dan
 * soft delete berjenjang (order → detail → progress) membuat order yang dibatalkan
 * hilang sendirinya dari papan manuskrip, distribusi, dan dashboard produksi lewat
 * global scope Eloquent — tanpa satu pun call site OrderDetail::/TitleProgress::
 * yang tersebar di 12+ tempat perlu disentuh (spec §0.3).
 */
class OrderCancellationService
{
    public function cancel(Order $order, ?string $reason, User $actor): void
    {
        if ($order->hasRefund()) {
            throw OrderCancellationException::alreadyRefunded();
        }

        if (! $order->isCancellable()) {
            throw OrderCancellationException::notCancellable();
        }

        $this->assertCashPeriodsUnlocked($order->payments()->pluck('id')->all());

        DB::transaction(function () use ($order, $reason, $actor) {
            $this->cancelPayments($order, $actor);
            $this->cancelInvoices($order, $actor);

            $detailIds = $order->details()->pluck('id');

            TitleProgress::whereIn('order_detail_id', $detailIds)->delete();
            $order->details()->delete();

            $order->update([
                'status'        => 'dibatalkan',
                'cancel_reason' => $reason,
                'cancelled_by'  => $actor->id,
                'cancelled_at'  => now(),
            ]);
            $order->delete();

            $this->releaseTagihan($order, $actor);
        });

        // Non-fatal: pembatalan sudah ter-commit. Kegagalan notifikasi tidak boleh
        // menjatuhkan alur (pola yang sama dengan paymentSubmitted).
        try {
            app(Notifier::class)->orderCancelled($order, $actor);
        } catch (\Throwable $e) {
            Log::warning('Notifikasi pembatalan order gagal: ' . $e->getMessage());
        }
    }

    /**
     * Membalik cancel(): payment 'batal' → 'paid' (approval kembali 'pending'),
     * invoice 'dibatalkan' → 'diterbitkan', progress/detail/order di-restore.
     *
     * Tagihan SENGAJA tidak ditarik kembali: bila sudah dipakai order lain, menariknya
     * justru merusak data. Jejaknya tetap terlihat di TagihanLog milik pembatalan.
     */
    public function restore(Order $order, User $actor): void
    {
        if (! $order->isCancelled()) {
            throw OrderCancellationException::notCancelled();
        }

        // Memulihkan payment membuat ulang CashEntry-nya — penjagaan periode berlaku dua arah.
        $this->assertCashPeriodsUnlocked($order->payments()->pluck('id')->all());

        DB::transaction(function () use ($order, $actor) {
            $order->restore();

            $order->details()->onlyTrashed()->restore();
            $detailIds = $order->details()->pluck('id');
            TitleProgress::onlyTrashed()->whereIn('order_detail_id', $detailIds)->restore();

            $this->restorePayments($order, $actor);
            $this->restoreInvoices($order, $actor);

            $order->update([
                'status'        => $this->statusAfterRestore($order),
                'cancel_reason' => null,
                'cancelled_by'  => null,
                'cancelled_at'  => null,
            ]);
        });

        try {
            app(Notifier::class)->orderRestored($order, $actor);
        } catch (\Throwable $e) {
            Log::warning('Notifikasi pemulihan order gagal: ' . $e->getMessage());
        }
    }

    /**
     * Semua payment order → 'batal', approval-nya → 'rejected'.
     * PaymentObserver::saved() otomatis menghapus CashEntry-nya, karena
     * PaymentCashSyncService::sync() membuang entri untuk payment ber-status != 'paid'.
     *
     * Tidak menyaring payment_type: order yang sudah di-refund sudah ditolak lebih
     * dulu oleh isCancellable() (lihat Order::hasRefund()). Kalau gerbang itu suatu
     * saat dilonggarkan, penyaringan refund HARUS ditambahkan di sini.
     */
    private function cancelPayments(Order $order, User $actor): void
    {
        foreach ($order->payments()->with('approval')->get() as $payment) {
            $payment->update(['status' => 'batal']);

            if ($payment->approval) {
                $payment->approval->update([
                    'status'      => 'rejected',
                    'note'        => 'Order dibatalkan',
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                ]);
            }
        }
    }

    /**
     * Invoice order → 'dibatalkan' (kosakata Invoice::STATUSES; 'batal' di rancangan
     * awal bukan status yang dikenal model — lihat catatan penyimpangan di rencana).
     */
    private function cancelInvoices(Order $order, User $actor): void
    {
        foreach ($order->invoices()->get() as $invoice) {
            $from = $invoice->status;

            $invoice->update([
                'status'       => 'dibatalkan',
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
            ]);

            InvoiceLog::create([
                'invoice_id'  => $invoice->id,
                'from_status' => $from,
                'to_status'   => 'dibatalkan',
                'changed_by'  => $actor->id,
                'note'        => 'Order ' . $order->code_order . ' dibatalkan.',
            ]);
        }
    }

    /**
     * Tagihan yang sudah "jadi_order" dikembalikan ke 'disetujui'. Tanpa ini, tagihan
     * yang sudah disetujui ikut mati bersama order dan tidak bisa dipakai membuat
     * order pengganti.
     */
    private function releaseTagihan(Order $order, User $actor): void
    {
        $tagihans = Tagihan::where('order_id', $order->id)->where('status', 'jadi_order')->get();

        foreach ($tagihans as $tagihan) {
            $tagihan->update([
                'status'     => 'disetujui',
                'order_id'   => null,
                'order_code' => null,
            ]);

            TagihanLog::create([
                'tagihan_id'  => $tagihan->id,
                'from_status' => 'jadi_order',
                'to_status'   => 'disetujui',
                'changed_by'  => $actor->id,
                'note'        => 'Order ' . $order->code_order . ' dibatalkan; tagihan bisa dipakai lagi.',
            ]);
        }
    }

    /**
     * Menghapus/membuat ulang CashEntry di periode yang sudah ditutup melanggar
     * CashPeriodLock. Aturan mainnya dibaca dari CashPeriodService yang sudah ada,
     * bukan diduplikasi. Berlaku dua arah: cancel menghapus entri, restore membuatnya lagi.
     *
     * Periode diambil dari Payment::paid_at, BUKAN CashEntry::tanggal: saat restore()
     * memanggil ini, payment masih berstatus 'batal' dan CashEntry-nya sudah dihapus
     * oleh cancel(), jadi lookup by payment_id di tabel CashEntry akan selalu kosong.
     * paid_at adalah sumber yang sama dipakai PaymentCashSyncService::sync() untuk
     * mengisi CashEntry::tanggal, jadi hasilnya identik untuk arah cancel().
     *
     * @param  array<int>  $paymentIds
     */
    private function assertCashPeriodsUnlocked(array $paymentIds): void
    {
        if (empty($paymentIds)) {
            return;
        }

        $paidAts = Payment::whereIn('id', $paymentIds)->pluck('paid_at');
        $service = app(CashPeriodService::class);

        foreach ($paidAts as $paidAt) {
            if (! $paidAt) {
                continue;
            }

            $d = Carbon::parse($paidAt);
            if ($service->isLocked((int) $d->format('Y'), (int) $d->format('n'))) {
                throw OrderCancellationException::periodLocked($d->format('m/Y'));
            }
        }
    }

    private function restorePayments(Order $order, User $actor): void
    {
        foreach ($order->payments()->where('status', 'batal')->with('approval')->get() as $payment) {
            $payment->update(['status' => 'paid']); // observer membuat ulang CashEntry

            if ($payment->approval) {
                $payment->approval->update([
                    'status'      => 'pending',
                    'note'        => 'Order dipulihkan',
                    'approved_by' => null,
                    'approved_at' => null,
                ]);
            }
        }
    }

    private function restoreInvoices(Order $order, User $actor): void
    {
        foreach ($order->invoices()->where('status', 'dibatalkan')->get() as $invoice) {
            $invoice->update([
                'status'       => 'diterbitkan',
                'cancelled_by' => null,
                'cancelled_at' => null,
            ]);

            InvoiceLog::create([
                'invoice_id'  => $invoice->id,
                'from_status' => 'dibatalkan',
                'to_status'   => 'diterbitkan',
                'changed_by'  => $actor->id,
                'note'        => 'Order ' . $order->code_order . ' dipulihkan.',
            ]);
        }
    }

    /**
     * Status order setelah dipulihkan diturunkan dari payment, TIDAK dipaksa 'pending':
     * PaymentBookController::store() menyetel 'lunas' begitu payment lunas/pelunasan
     * disubmit (sebelum approval), jadi memaksa 'pending' akan menghilangkan status itu.
     * Dipanggil SETELAH restorePayments() agar membaca status 'paid' yang sudah pulih.
     */
    private function statusAfterRestore(Order $order): string
    {
        $lunas = $order->payments()
            ->whereIn('payment_type', ['lunas', 'pelunasan'])
            ->where('status', 'paid')
            ->exists();

        return $lunas ? 'lunas' : 'pending';
    }
}
