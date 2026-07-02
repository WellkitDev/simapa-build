<?php

namespace App\Services;

use App\Models\Title;

class TitleCodeService
{
    private const FALLBACK = 'JDL';

    /** Kode dari inisial 4 kata pertama judul; jamin unik (sufiks angka). */
    public function generate(string $title, ?int $ignoreId = null): string
    {
        $base = $this->baseFrom($title);
        $code = $base;
        $i = 2;
        while ($this->taken($code, $ignoreId)) {
            $code = $base . $i;
            $i++;
        }

        return $code;
    }

    private function baseFrom(string $title): string
    {
        // Buang tanda baca/simbol → sisakan huruf/angka + spasi.
        $clean = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $title) ?? '';
        $words = preg_split('/\s+/u', trim($clean), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) >= 2) {
            $letters = '';
            foreach (array_slice($words, 0, 4) as $w) {
                $letters .= mb_strtoupper(mb_substr($w, 0, 1));
            }

            return $letters !== '' ? $letters : self::FALLBACK;
        }

        $single = $words[0] ?? '';
        if ($single === '') {
            return self::FALLBACK;
        }

        return mb_strtoupper(mb_substr($single, 0, 4));
    }

    private function taken(string $code, ?int $ignoreId): bool
    {
        return Title::where('code', $code)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }
}
