<?php

namespace App\Support;

/**
 * Batas unggahan yang benar-benar berlaku, bukan yang dijanjikan aplikasi.
 *
 * Aturan `max:` di controller hanyalah plafon aplikasi. Yang menolak lebih dulu
 * adalah PHP lewat `upload_max_filesize` / `post_max_size` — dan ia menolak SEBELUM
 * aturan `max:` sempat berjalan, sehingga pesan yang muncul bukan "melebihi 20 MB"
 * melainkan galat `uploaded` yang tak menyebut sebab apa pun.
 *
 * Menjanjikan 20 MB di layar sementara server hanya menerima 2 MB adalah
 * kesenjangan yang hanya ketahuan saat pengguna gagal mengunggah dan tak tahu kenapa.
 */
class BatasUnggah
{
    /** Ubah nilai php.ini bergaya "40M" / "8192K" / "2G" ke KB. 0 = tak terbatas. */
    public static function iniKeKb(string $kunci): int
    {
        $nilai = trim((string) ini_get($kunci));
        if ($nilai === '' || $nilai === '-1' || $nilai === '0') {
            return 0;
        }

        $angka  = (float) $nilai;
        $satuan = strtoupper(substr($nilai, -1));

        return (int) match ($satuan) {
            'G'     => $angka * 1024 * 1024,
            'M'     => $angka * 1024,
            'K'     => $angka,
            default => $angka / 1024,   // tanpa satuan = byte
        };
    }

    /**
     * Batas efektif (KB): nilai terkecil antara plafon aplikasi dan batas PHP.
     *
     * @param int $plafonKb plafon yang diinginkan aplikasi untuk jenis berkas ini
     */
    public static function kb(int $plafonKb): int
    {
        $batas = [$plafonKb];
        foreach (['upload_max_filesize', 'post_max_size'] as $kunci) {
            $kb = self::iniKeKb($kunci);
            if ($kb > 0) {
                $batas[] = $kb;
            }
        }

        return max(1, min($batas));
    }

    /** Batas efektif dalam bentuk yang enak dibaca, mis. "2 MB" / "7,5 MB". */
    public static function manusia(int $plafonKb): string
    {
        $mb = self::kb($plafonKb) / 1024;
        $teks = fmod($mb, 1.0) === 0.0
            ? (string) (int) $mb
            : rtrim(rtrim(number_format($mb, 1, ',', '.'), '0'), ',');

        return $teks . ' MB';
    }
}
