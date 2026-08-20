<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\TitleProgress;
use App\Models\TitleProgressLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Menarik satu order dari judulnya karena refund penuh.
 *
 * "Ditarik" BUKAN "ditahan": rancangan pertama memakai AssignmentService::hold(), tapi
 * hold bekerja se-grup (onGroup) sehingga satu penulis yang mundur akan membekukan buku
 * untuk semua penulis lain. Baris yang ditarik dikeluarkan dari perhitungan grup lewat
 * `withdrawn_at` + OrderDetail::scopeNotWithdrawn().
 *
 * Refund SEBAGIAN tidak menarik apa pun — bisa jadi potongan harga atau kompensasi,
 * bukan pembatalan.
 */
class OrderWithdrawalService
{
    /**
     * Tahap buku setelah mana bab TIDAK boleh dicabut lagi: sejak ISBN terdaftar,
     * susunan bab sudah resmi dan bukunya mungkin sudah dicetak. Uang boleh kembali,
     * karyanya tidak bisa ditarik dari peredaran.
     */
    private const BATAS_CABUT = 'isbn';

    /** Uang masuk (bukan refund) yang pernah diterima order ini. */
    public function paidIn(Order $order): int
    {
        return (int) $order->payments()
            ->where('status', 'paid')
            ->where('payment_type', '!=', 'refund')
            ->sum('amount');
    }

    /** Refund ini mengembalikan SELURUH uang yang pernah masuk. */
    public function isFullRefund(Order $order, Payment $refund): bool
    {
        return (int) $refund->amount >= $this->paidIn($order);
    }

    /** @return bool true bila ordernya benar-benar ditarik (refund penuh) */
    public function withdraw(Order $order, Payment $refund, User $actor): bool
    {
        if (! $this->isFullRefund($order, $refund)) {
            return false;
        }

        $progress = $order->details?->titleProgress;

        DB::transaction(function () use ($order, $refund, $actor, $progress) {
            $order->update(['fulfillment_status' => 'ditarik']);

            if ($progress === null) {
                return;
            }

            $dari = $progress->stageLabelId();

            $progress->update([
                'withdrawn_at' => now(),
                // Dipotong: kolomnya varchar(255) sedangkan `reason` divalidasi tanpa
                // batas panjang (textarea). Alasan utuhnya tetap tersimpan di payment
                // dan di `note` log di bawah (kolom text), jadi tak ada yang hilang.
                'withdrawn_reason' => Str::limit((string) $refund->refund_reason, 200),
            ]);

            TitleProgressLog::create([
                'title_progress_id' => $progress->id,
                'event'             => 'penarikan',
                'from_value'        => $dari,
                'to_value'          => 'Ditarik',
                'changed_by'        => $actor->id,
                'note'              => 'Order ' . $order->code_order . ' di-refund penuh: '
                                     . $refund->refund_reason,
            ]);

            $progress->update(['last_log_at' => now()]);

            $progress->update(['withdrawal_snapshot' => $this->lepasBab($order, $progress)]);
        });

        return true;
    }

    /**
     * Cabut bab + penulis milik order yang ditarik, lalu kembalikan snapshotnya.
     *
     * Mengembalikan null (dan tidak mengubah apa pun) untuk artikel, buku mandiri,
     * atau buku yang sudah melewati BATAS_CABUT.
     */
    private function lepasBab(Order $order, TitleProgress $progress): ?array
    {
        $detail = $order->details;
        if ($detail === null || $detail->type !== 'bk_kolab') {
            return null;
        }

        if (! $this->bolehCabut($progress)) {
            return null;
        }

        $book    = $detail->titleRef;
        $chapter = $book?->chapters()->where('urutan', (int) $detail->chapters)->first();
        if ($chapter === null) {
            return null;
        }

        // Penulis dibaca SEBELUM detach — snapshot inilah satu-satunya jalan pulang
        // bila penarikannya nanti dibatalkan.
        $snapshot = [
            'chapter_id'     => $chapter->id,
            'authors'        => $chapter->authors()
                                    ->get()
                                    ->map(fn ($a) => ['id' => $a->id, 'position' => $a->pivot->position])
                                    ->all(),
            'chapter_status' => optional($chapter->progress)->status,
            'pelaksana_id'   => optional($chapter->progress)->pelaksana_user_id,
        ];

        $chapter->authors()->detach();

        if ($chapter->progress) {
            $chapter->progress->update([
                'status'            => 'menunggu',
                'pelaksana_user_id' => null,
                'sla_due_at'        => null,
                'started_at'        => now(),
            ]);
        }

        return $snapshot;
    }

    /** Tahap buku masih di bawah BATAS_CABUT. */
    private function bolehCabut(TitleProgress $progress): bool
    {
        $stages   = TitleProgress::BOOK_STAGES;
        $sekarang = array_search((string) $progress->status, $stages, true);
        $batas    = array_search(self::BATAS_CABUT, $stages, true);

        return $sekarang !== false && $sekarang < $batas;
    }
}
