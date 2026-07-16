<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\Request;

class DataAssetAccessException extends Exception
{
    public static function notFound(): self
    {
        return new self('Data tidak ditemukan atau sudah dihapus.');
    }

    public static function cannotView(): self
    {
        return new self('Kamu tidak punya akses ke data ini.');
    }

    public static function notOwner(): self
    {
        return new self('Hanya pemilik yang bisa mengubah atau menghapus data ini.');
    }

    public function render(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 403);
        }

        return redirect()->route('data.index')->with('error', $this->getMessage());
    }
}
