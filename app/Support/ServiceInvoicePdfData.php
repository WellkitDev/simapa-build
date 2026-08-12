<?php

namespace App\Support;

use App\Models\ServiceInvoice;

/**
 * Perakit data PDF invoice layanan. Satu sumber yang dipakai bersama oleh route
 * unduh dan SendServiceInvoiceJob, supaya dokumen yang diunduh dan yang dikirim
 * lewat email tidak pernah berbeda isi — peran yang sama seperti InvoicePdfData
 * di modul invoice order.
 *
 * Tidak ada penyaringan approval seperti di InvoicePdfData: pembayaran jasa dicatat
 * langsung tanpa alur persetujuan, jadi semua barisnya memang sah dihitung.
 */
class ServiceInvoicePdfData
{
    /** @return array{invoice: ServiceInvoice} */
    public static function for(ServiceInvoice $invoice): array
    {
        $invoice->load(['items', 'payments']);

        return compact('invoice');
    }
}
