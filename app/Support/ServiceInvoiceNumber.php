<?php

namespace App\Support;

use App\Models\ServiceInvoice;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;

class ServiceInvoiceNumber
{
    /**
     * Nomor invoice berikutnya untuk bulan penerbitan: INV-JS-YYYYMM-NNNN.
     *
     * WAJIB dipanggil DI DALAM transaksi yang sama dengan insert-nya — lockForUpdate
     * baru berarti di sana. Tiga lapis pengaman: kunci baris, withTrashed (nomor yang
     * dihapus tak pernah didaur ulang), dan unique index + retry() sebagai jaring akhir.
     *
     * MAX() string aman di sini karena sufiksnya zero-padded dengan panjang tetap dan
     * prefiksnya sama persis; urutan leksikografis = urutan numerik. Asumsi itu putus
     * kalau satu bulan melewati 9999 invoice — tidak terjangkau pada volume jasa.
     */
    public static function next(CarbonInterface $issuedAt): string
    {
        $prefix = 'INV-JS-' . $issuedAt->format('Ym') . '-';

        $last = ServiceInvoice::withTrashed()
            ->where('invoice_no', 'like', $prefix . '%')
            ->lockForUpdate()
            ->max('invoice_no');

        $suffix = $last !== null ? substr($last, strlen($prefix)) : null;

        // Gagal keras, jangan menebak. Sufiks non-angka (data hasil sunting tangan
        // atau importer) membuat (int) menghasilkan 0, `next()` mengembalikan 0001
        // yang sudah terpakai, dan bulan itu macet permanen dengan galat SQL
        // yang tak menjelaskan apa pun.
        if ($suffix !== null && ! ctype_digit($suffix)) {
            throw new \RuntimeException(
                "Nomor invoice layanan terakhir tidak berformat angka: {$last}. "
                . 'Perbaiki datanya sebelum menerbitkan invoice baru.'
            );
        }

        $seq = $suffix !== null ? ((int) $suffix) + 1 : 1;

        // Di 10000 sufiksnya jadi 5 digit dan urutan leksikografis MAX() putus —
        // '1' < '9' membuat baris 5 digit terabaikan dan nomor yang sama diterbitkan
        // berulang. Tak terjangkau pada volume jasa, tapi harus berbunyi jelas.
        if ($seq > 9999) {
            throw new \RuntimeException(
                'Kuota nomor invoice layanan bulan ' . $issuedAt->format('F Y') . ' habis (9999). '
                . 'Lebarkan format penomoran sebelum menerbitkan invoice baru.'
            );
        }

        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Jalankan $fn, ulangi hanya bila gagal karena balapan alokasi nomor. Galat lain
     * dilempar apa adanya — mengulang galat sembarangan menyembunyikan bug.
     *
     * Jeda acak kecil antar-percobaan supaya dua pemanggil yang bertabrakan tidak
     * langsung bertabrakan lagi di percobaan berikutnya.
     */
    public static function retrying(callable $fn, int $tries = 3)
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $fn();
            } catch (QueryException $e) {
                if (! self::isRaceCollision($e) || $attempt >= $tries) {
                    throw $e;
                }

                usleep(random_int(10_000, 50_000));
            }
        }
    }

    /**
     * Dicocokkan lewat SQLSTATE + kode driver di `errorInfo`, BUKAN teks pesan:
     * Laravel menempelkan seluruh SQL ke pesan, sehingga mencari 'invoice_no' di
     * sana ikut cocok dengan duplikat kolom lain hanya karena nama kolomnya muncul
     * di daftar INSERT. Presedennya sudah ada di `EnforceIdempotency`.
     *
     * Deadlock & lock-wait ikut dianggap balapan karena justru DIPICU oleh
     * lockForUpdate() di next(): pada bulan yang masih kosong, `LIKE 'prefix%'
     * FOR UPDATE` hanya mengambil gap lock yang kompatibel-bersama, jadi dua
     * transaksi sama-sama menghitung 0001 lalu saling mengunci saat INSERT.
     * Tanpa memasukkan 40001/1213 ke sini, invoice PERTAMA tiap bulan bisa
     * berakhir 500 — tepat di kasus yang retry ini dibuat untuk menanganinya.
     */
    private static function isRaceCollision(QueryException $e): bool
    {
        $sqlState   = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        $detail     = (string) ($e->errorInfo[2] ?? '');

        if ($sqlState === '40001' || in_array($driverCode, [1213, 1205], true)) {
            return true;
        }

        // errorInfo[2] hanya memuat nama indeks yang bentrok, tanpa SQL-nya —
        // jadi ini benar-benar menyaring unique index invoice_no.
        return $sqlState === '23000'
            && $driverCode === 1062
            && str_contains($detail, 'invoice_no');
    }
}
