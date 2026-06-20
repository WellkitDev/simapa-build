<?php

namespace App\Support;

use App\Models\Tagihan;

class TagihanPdfData
{
    /** @return array{tagihan: Tagihan} */
    public static function for(Tagihan $tagihan): array
    {
        $tagihan->loadMissing('creator');
        return compact('tagihan');
    }
}
