<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\Request;

/**
 * Penjagaan pembatalan/pemulihan order. Mengikuti pola CashEntryGuardException:
 * pesan siap tampil + render() sendiri, supaya controller tidak perlu try/catch.
 */
class OrderCancellationException extends Exception
{
    public static function notCancellable(): self
    {
        return new self('Order ini tidak bisa dibatalkan karena pembayarannya sudah disetujui. Gunakan alur Refund.');
    }

    public static function alreadyRefunded(): self
    {
        return new self('Order ini sudah pernah di-refund, jadi tidak bisa dibatalkan. Pembatalan tidak bisa membalik transaksi yang sudah terjadi.');
    }

    public static function periodLocked(string $period): self
    {
        return new self(
            'Periode kas ' . $period . ' sudah dikunci. '
            . 'Minta superadmin membuka periode atau gunakan alur Refund.'
        );
    }

    public static function notCancelled(): self
    {
        return new self('Order ini tidak dalam keadaan dibatalkan.');
    }

    /**
     * Bab yang dulu dipesan order ini sudah dijual ulang selagi ordernya dibatalkan.
     * Memulihkannya akan menimpa penulis pemilik baru DAN meninggalkan dua order hidup
     * di atas satu bab — ambiguitas kepemilikan yang harus diputuskan manusia, bukan
     * diselesaikan diam-diam oleh kode.
     */
    public static function chapterTakenOver(string $successorCode): self
    {
        return new self(
            'Bab ini sudah dipesan order ' . $successorCode . ' selagi order ini dibatalkan. '
            . 'Batalkan order itu dulu bila memang order ini yang mau dipulihkan.'
        );
    }

    public function render(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 422);
        }

        return back()->with('error', $this->getMessage());
    }
}
