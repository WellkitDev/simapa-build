<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\Request;

class CashEntryGuardException extends Exception
{
    public static function periodLocked(int $year, int $month): self
    {
        return new self("Periode {$month}/{$year} sudah dikunci. Buka kunci dulu bila memang perlu diubah.");
    }

    public static function autoEntry(): self
    {
        return new self('Entri ini otomatis dari pembayaran dan tak bisa diubah di sini. Ubah pembayarannya — jurnal ikut menyesuaikan.');
    }

    public function render(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 422);
        }

        return back()->with('error', $this->getMessage());
    }
}
