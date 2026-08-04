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

    public function render(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 422);
        }

        return back()->with('error', $this->getMessage());
    }
}
