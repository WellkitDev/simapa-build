<?php

namespace App\Support;

class Terbilang
{
    private static array $satuan = [
        '', 'satu', 'dua', 'tiga', 'empat', 'lima',
        'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas',
    ];

    public static function angka(int $n): string
    {
        $n = abs($n);
        if ($n < 12) {
            return self::$satuan[$n];
        }
        if ($n < 20) {
            return self::angka($n - 10) . ' belas';
        }
        if ($n < 100) {
            return self::angka(intdiv($n, 10)) . ' puluh' . ($n % 10 ? ' ' . self::angka($n % 10) : '');
        }
        if ($n < 200) {
            return 'seratus' . ($n - 100 ? ' ' . self::angka($n - 100) : '');
        }
        if ($n < 1000) {
            return self::angka(intdiv($n, 100)) . ' ratus' . ($n % 100 ? ' ' . self::angka($n % 100) : '');
        }
        if ($n < 2000) {
            return 'seribu' . ($n - 1000 ? ' ' . self::angka($n - 1000) : '');
        }
        if ($n < 1000000) {
            return self::angka(intdiv($n, 1000)) . ' ribu' . ($n % 1000 ? ' ' . self::angka($n % 1000) : '');
        }
        if ($n < 1000000000) {
            return self::angka(intdiv($n, 1000000)) . ' juta' . ($n % 1000000 ? ' ' . self::angka($n % 1000000) : '');
        }
        if ($n < 1000000000000) {
            return self::angka(intdiv($n, 1000000000)) . ' miliar' . ($n % 1000000000 ? ' ' . self::angka($n % 1000000000) : '');
        }
        return self::angka(intdiv($n, 1000000000000)) . ' triliun' . ($n % 1000000000000 ? ' ' . self::angka($n % 1000000000000) : '');
    }

    public static function rupiah(int|float $n): string
    {
        $n = (int) round($n);
        if ($n === 0) {
            return 'Nol rupiah';
        }
        $prefix = $n < 0 ? 'Minus ' : '';
        $words  = trim(preg_replace('/\s+/', ' ', self::angka(abs($n))));
        return $prefix . ucfirst($words) . ' rupiah';
    }
}
