<?php

namespace App\Services;

use App\Exceptions\OrderCancellationException;
use App\Models\InvoiceLog;
use App\Models\Order;
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

        // Hanya entri kas milik payment yang SEDANG 'paid' yang akan dihapus —
        // itu yang harus diperiksa, bukan seluruh payment tanpa syarat.
        $this->assertCashPeriodsUnlocked($order->payments()->where('status', 'paid')->pluck('paid_at')->all());

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
     * invoice 'dibatalkan' → status aslinya sebelum dibatalkan (lihat restoreInvoices()),
     * progress/detail/order di-restore.
     *
     * Tagihan SENGAJA tidak ditarik kembali: bila sudah dipakai order lain, menariknya
     * justru merusak data. Jejaknya tetap terlihat di TagihanLog milik pembatalan.
     */
    public function restore(Order $order, User $actor): void
    {
        if (! $order->isCancelled()) {
            throw OrderCancellationException::notCancelled();
        }

        // Hanya entri kas milik payment yang SEDANG 'batal' akan dibuat ulang —
        // itu yang harus diperiksa, bukan seluruh payment tanpa syarat (payment yang
        // sudah 'rejected' sebelum pembatalan tidak akan disentuh restorePayments()).
        $this->assertCashPeriodsUnlocked($order->payments()->where('status', 'batal')->pluck('paid_at')->all());

        DB::transaction(function () use ($order, $actor) {
            $order->restore();

            $order->details()->onlyTrashed()->restore();
            $detailIds = $order->details()->pluck('id');
            TitleProgress::onlyTrashed()->whereIn('order_detail_id', $detailIds)->restore();

            $this->restorePayments($order);
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
     * Payment yang SEDANG 'paid' → 'batal', approval-nya → 'rejected'.
     * PaymentObserver::saved() otomatis menghapus CashEntry-nya, karena
     * PaymentCashSyncService::sync() membuang entri untuk payment ber-status != 'paid'.
     *
     * Disaring HANYA status 'paid': payment yang sudah 'rejected' lebih dulu (lewat
     * PaymentBookController::reject(), yang menulis payments.status juga — bukan cuma
     * approval-nya) sengaja tidak disentuh. Entri kasnya sudah lama hilang dan ia sudah
     * keluar dari Payment::scopeIncome(), jadi tak ada yang perlu dibatalkan. Menulisinya
     * di sini akan menghapus catatan penolakan asli (note + approved_by/approved_at) dan
     * — lebih parah — membuatnya hidup lagi sebagai 'paid' begitu order dipulihkan,
     * karena restorePayments() mengembalikan SEMUA payment 'batal' menjadi 'paid'.
     *
     * Tidak menyaring payment_type: order yang sudah di-refund sudah ditolak lebih
     * dulu oleh isCancellable() (lihat Order::hasRefund()). Kalau gerbang itu suatu
     * saat dilonggarkan, penyaringan refund HARUS ditambahkan di sini.
     */
    private function cancelPayments(Order $order, User $actor): void
    {
        foreach ($order->payments()->where('status', 'paid')->with('approval')->get() as $payment) {
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
     * Menerima tanggal (paid_at) langsung, BUKAN payment id: entri kas milik payment
     * yang sudah 'rejected' sudah lama terhapus (tidak akan disentuh cancel() ataupun
     * restore()), jadi ikut memeriksa periodenya hanya akan memblokir tanpa alasan.
     * Tiap call site wajib memasok persis payment yang entri kasnya akan benar-benar
     * dihapus/dibuat ulang — lihat cancel() dan restore().
     *
     * @param  array<int, mixed>  $dates
     */
    private function assertCashPeriodsUnlocked(array $dates): void
    {
        if (empty($dates)) {
            return;
        }

        $service = app(CashPeriodService::class);

        foreach ($dates as $paidAt) {
            if (! $paidAt) {
                continue;
            }

            $d = Carbon::parse($paidAt);
            if ($service->isLocked((int) $d->format('Y'), (int) $d->format('n'))) {
                throw OrderCancellationException::periodLocked($d->format('m/Y'));
            }
        }
    }

    /**
     * Payment 'batal' → 'paid': PaymentObserver::saved() otomatis membuat ulang
     * CashEntry-nya lewat PaymentCashSyncService::sync(). Approval-nya kembali
     * 'pending' supaya direview ulang, bukan otomatis dianggap disetujui.
     *
     * Hanya menyasar status 'batal' — nilai itu SEKARANG hanya pernah ditulis oleh
     * cancelPayments() (yang menyaring 'paid' saja, lihat catatannya). Jadi payment
     * yang sudah 'rejected' lewat PaymentBookController::reject() sebelum order
     * dibatalkan tidak pernah tersentuh di sini dan tetap 'rejected' sesudah dipulihkan.
     */
    private function restorePayments(Order $order): void
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

    /**
     * Invoice 'dibatalkan' → status sebelum dibatalkan — BUKAN dipatok 'diterbitkan':
     * invoice bisa saja sedang 'jatuh_tempo' (lewat InvoiceController::updateStatus())
     * saat order dibatalkan, dan restore() harus benar-benar kebalikan dari cancel(),
     * bukan menormalkan semuanya. Status asli dibaca dari InvoiceLog milik pembatalan
     * TERAKHIR — cancelInvoices() selalu mencatat status sebelumnya sebagai from_status
     * — dengan 'diterbitkan' sebagai fallback kalau baris log itu entah kenapa hilang.
     */
    private function restoreInvoices(Order $order, User $actor): void
    {
        foreach ($order->invoices()->where('status', 'dibatalkan')->get() as $invoice) {
            $before = InvoiceLog::where('invoice_id', $invoice->id)
                ->where('to_status', 'dibatalkan')
                ->latest('id')
                ->value('from_status') ?: 'diterbitkan';

            $invoice->update([
                'status'       => $before,
                'cancelled_by' => null,
                'cancelled_at' => null,
            ]);

            InvoiceLog::create([
                'invoice_id'  => $invoice->id,
                'from_status' => 'dibatalkan',
                'to_status'   => $before,
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
