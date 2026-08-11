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

        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Jalankan $fn, ulangi hanya bila gagal karena tabrakan invoice_no. Galat lain
     * dilempar apa adanya — mengulang galat sembarangan menyembunyikan bug.
     */
    public static function retrying(callable $fn, int $tries = 3)
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $fn();
            } catch (QueryException $e) {
                $duplicate = str_contains($e->getMessage(), 'invoice_no')
                    && (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'UNIQUE'));

                if (! $duplicate || $attempt >= $tries) {
                    throw $e;
                }
            }
        }
    }
}
