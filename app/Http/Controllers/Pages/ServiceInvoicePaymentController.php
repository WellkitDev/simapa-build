<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoicePayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Pembayaran invoice layanan. TIDAK ada kaitannya dengan tb_payments, approval,
 * atau Jurnal Kas — modul ini sengaja berdiri sendiri (spec §2, dikunci T-ISO).
 */
class ServiceInvoicePaymentController extends Controller
{
    public function store(Request $request, int $id)
    {
        $invoice = ServiceInvoice::findOrFail($id);

        if ($invoice->isCancelled()) {
            return back()->with('error', 'Invoice yang dibatalkan tidak bisa menerima pembayaran.');
        }

        // Buang pemisah ribuan; minus dipertahankan supaya min:1 tetap menolaknya.
        // is_scalar(): nilai array (mis. amount[]=1) tidak boleh sampai ke (string) $amount.
        // Larik yang di-cast ke string memicu warning "Array to string conversion", yang
        // oleh error handler Laravel diubah jadi ErrorException — 500 sungguhan, bukan
        // galat validasi. Dibuktikan dengan mematikan guard ini sementara. Persis jebakan
        // yang sudah ditambal di ServiceInvoiceForm::normalize() (Task 8).
        $amount = $request->input('amount');
        $request->merge(['amount' => is_scalar($amount) ? preg_replace('/[.,\s]/', '', (string) $amount) : $amount]);

        $data = $request->validate([
            'paid_at'   => 'required|date',
            'type'      => 'required|in:' . implode(',', array_keys(ServiceInvoicePayment::TYPES)),
            'amount'    => 'required|numeric|min:1|max:9999999999999.99',
            'method'    => 'required|in:' . implode(',', array_keys(ServiceInvoicePayment::METHODS)),
            'reference' => 'nullable|string|max:190',
            'note'      => 'nullable|string',
        ]);

        DB::transaction(function () use ($invoice, $data) {
            $payment = $invoice->payments()->create($data + ['created_by' => Auth::id()]);

            // Lebih bayar TIDAK diblokir: remaining boleh negatif dan ditampilkan
            // sebagai "Lebih Bayar". Memblokirnya cuma memaksa operator memalsukan angka.
            $invoice->recalcTotals();

            $invoice->logs()->create([
                'event'      => 'payment_added',
                'to_status'  => $invoice->payment_status,
                'note'       => $payment->typeLabel() . ' Rp ' . number_format((float) $payment->amount, 0, ',', '.'),
                'changed_by' => Auth::id(),
            ]);
        });

        return back()->with('success', 'Pembayaran dicatat.');
    }

    public function destroy(int $id, int $paymentId)
    {
        $invoice = ServiceInvoice::findOrFail($id);
        $payment = $invoice->payments()->findOrFail($paymentId);

        DB::transaction(function () use ($invoice, $payment) {
            $amount = (float) $payment->amount;

            $payment->delete();           // soft delete — barisnya tetap bisa ditelusuri
            $invoice->recalcTotals();

            $invoice->logs()->create([
                'event'      => 'payment_deleted',
                'to_status'  => $invoice->payment_status,
                'note'       => 'Pembayaran Rp ' . number_format($amount, 0, ',', '.') . ' dihapus.',
                'changed_by' => Auth::id(),
            ]);
        });

        // 'info', bukan 'warning': layouts/master hanya merender success/error/info
        // (lihat ServiceInvoiceController::destroy/cancel) — key 'warning' tak pernah
        // sampai ke layar, operator menghapus pembayaran tanpa satu pun konfirmasi.
        return back()->with('info', 'Pembayaran dihapus dan total dihitung ulang.');
    }
}
