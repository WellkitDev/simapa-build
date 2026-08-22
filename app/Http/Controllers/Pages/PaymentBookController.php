<?php

namespace App\Http\Controllers\Pages;

use Carbon\Carbon;
use App\Models\Order;
use App\Models\Invoice;
use App\Models\Payment;
use App\Jobs\SendInvoiceJob;
use Illuminate\Http\Request;
use App\Models\PaymentApproval;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Services\GoogleDriveService;
use Illuminate\Support\Facades\Auth;
use App\Services\Notifier;

class PaymentBookController extends Controller
{
    protected $drive;

    public function __construct(GoogleDriveService $drive)
    {
        $this->drive = $drive;
    }
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // Pecah pembayaran per status persetujuan: yang perlu diproses (pending),
        // sudah disetujui (approved), dan ditolak (rejected).
        $byStatus = fn (string $status) => Payment::with(['order.user', 'invoice', 'approval'])
            ->whereHas('order', function ($q) {
                $q->when(Auth::user()->hasRole('marketing'), fn ($query) => $query->where('user_id', Auth::id()));
            })
            ->whereHas('approval', fn ($q) => $q->where('status', $status))
            ->latest()
            ->get();

        $pending  = $byStatus('pending');
        $approved = $byStatus('approved');
        $rejected = $byStatus('rejected');

        return view('payments.book.index', compact('pending', 'approved', 'rejected'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create($code_order)
    {
        //
        $order = Order::with(['details', 'contact'])
            ->where('code_order', $code_order)
            ->firstOrFail();

        if ($order->status !== 'pending') {
            return redirect()->back()->with('error', 'Status order tidak valid.');
        }

        // Mengambil detail pertama dari collection hasMany
        $firstDetail = $order->details;
        if (!$firstDetail) {
            return redirect()->back()->with('error', 'Detail order tidak ditemukan.');
        }

        // LOGIKA PERHITUNGAN
        $totalCost = $firstDetail->cost_amount;
        $alreadyPaid = $order->paidNet();
        $remainingBalance = $totalCost - $alreadyPaid;

        // Jika sudah lunas tapi masih buka halaman ini
        if ($remainingBalance <= 0) {
            return redirect()->route('order.book.index')->with('info', 'Order ini sudah lunas.');
        }

        return view('payments.book.create', compact(
            'order',
            'totalCost',
            'alreadyPaid',
            'remainingBalance'
        ));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, string $code_order)
    {
        //
        $validate = $request->validate([
            'issued_at'      => 'required|date',
            'dued_at'        => 'required|date|after_or_equal:issued_at',
            'status'         => 'required|in:' . implode(',', Payment::TYPES_MASUK),
            'pay_amount'     => 'required|numeric|min:1',
            'proof_url'      => 'required|file|mimes:jpg,jpeg,png,pdf|max:' . \App\Support\BatasUnggah::kb(10240),
        ]);

        $order = Order::with(['details', 'contact'])
            ->where('code_order', $code_order)
            ->firstOrFail();

        // === UPLOAD STRUK (tetap sama, tanpa ubah handle image) ===
        $strukUrl = null;

        if ($request->hasFile('proof_url')) {
            $file = $request->file('proof_url');
            $year = Carbon::parse($validate['issued_at'])->format('Y');
            $folderPath = "Application/struk_pembayaran/{$year}";

            $folderId = $this->drive->getOrCreateFolderByPath($folderPath);
            if (!$folderId) {
                return back()->with('error', 'Gagal membuat folder Google Drive.');
            }

            $filename = $order->contact->cp_email . "_struk." . $file->getClientOriginalExtension();
            $uploadResult = $this->drive->uploadFile($file, $folderId, true, $filename);

            if (!$uploadResult || !isset($uploadResult['id'])) {
                Log::error('Upload struk gagal', $uploadResult ?? []);
                return back()->with('error', 'Gagal upload struk. Coba lagi.');
            }

            $strukUrl = $uploadResult['url'];
        }

        $emailRequested = $request->boolean('send_invoice_email');

        try {
            $payment = null;
            $invoiceId = DB::transaction(function () use ($validate, $order, $strukUrl, $emailRequested, &$payment) {
                $payment = Payment::create([
                    'order_id'     => $order->id,
                    'payment_type' => $validate['status'],
                    'amount'       => $validate['pay_amount'],
                    'proof_url'    => $strukUrl,
                    'paid_at'      => $validate['issued_at'],
                    // Lahir 'pending', bukan 'paid'. scopeIncome() menyaring status
                    // 'paid', jadi menulis 'paid' di sini membuat uang yang belum
                    // diverifikasi siapa pun langsung masuk Laporan Pemasukan, paidNet(),
                    // dan Target Marketing — approve() jadi sekadar hiasan.
                    'status'       => 'pending',
                ]);

                // INVOICE
                $invNo = "INV-" . str_replace('ORD-', '', $order->code_order). '-' . $payment->id;
                $invoice = Invoice::create([
                    'order_id'        => $order->id,
                    'payment_id'      => $payment->id,
                    'invoice_no'      => $invNo,
                    'issued_at'       => $validate['issued_at'],
                    'due_at'          => $validate['dued_at'],
                    'status'          => 'diterbitkan',
                    'email_requested' => $emailRequested,
                ]);

                \App\Models\InvoiceLog::create([
                    'invoice_id'  => $invoice->id,
                    'from_status' => '',
                    'to_status'   => 'diterbitkan',
                    'changed_by'  => Auth::id(),
                    'note'        => 'Invoice dibuat otomatis dari pembayaran.',
                ]);

                // APPROVAL
                PaymentApproval::create([
                    'payment_id' => $payment->id,
                    'status'     => 'pending',
                ]);

                // Status uang dihitung, tidak ditebak dari nama tipe pembayaran. Cabang
                // lama melunasi order begitu payment_type kebetulan bernama 'lunas',
                // tanpa pernah melihat nominalnya.
                $order->recalcStatus();

                return $invoice->id;
            });
            // Notifikasi di luar transaksi & non-fatal: pembayaran + invoice sudah
            // ter-commit. Jangan biarkan kegagalan notifikasi jatuh ke catch dan
            // mengubah respons jadi 'error' — idempotency akan salah melepas klaim
            // sehingga submit ulang token yang sama tak lagi ter-dedupe (pembayaran ganda).
            try {
                app(Notifier::class)->paymentSubmitted($payment, Auth::user());
            } catch (\Throwable $e) {
                Log::warning('Notifikasi pembayaran gagal: ' . $e->getMessage());
            }
            return redirect()->route('order.book.create')
                ->with('success', 'Pembayaran berhasil diajukan, menunggu approval');
        } catch (\Exception $e) {
            // Tangkap pesan error dari throw di atas
            Log::error('Proses simpan pembayaran gagal: ' . $e->getMessage());
            return back()->withInput()->with('error', 'Terjadi kesalahan: ' . $e->getMessage());
        }

    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $payment = Payment::with(['approval', 'order.details'])->findOrFail($id);

        // Order yang dibatalkan ikut soft-deleted, sehingga $payment->order bernilai
        // null di sini — ordernya harus dibaca withTrashed() agar penjagaan ini tidak
        // diam-diam terlewat. Sifatnya berlapis: pembatalan sudah menjadikan approval
        // 'rejected' sehingga cek di bawah ikut menutup jalan ini, tapi jaminan
        // "halaman order dibatalkan itu hanya-baca" tidak boleh bergantung pada efek
        // samping di kelas lain.
        $order = Order::withTrashed()->find($payment->order_id);
        abort_if($order && $order->isCancelled(), 403);

        if (optional($payment->approval)->status !== 'pending') {
            return back()->with('error', 'Pembayaran sudah diproses, tidak bisa diedit.');
        }

        $validate = $request->validate([
            'amount'       => 'required|numeric|min:1',
            'payment_type' => 'required|in:' . implode(',', Payment::TYPES_MASUK),
            'paid_at'      => 'required|date',
            'proof_url'    => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:' . \App\Support\BatasUnggah::kb(10240),
        ]);

        $strukUrl = $payment->proof_url;
        if ($request->hasFile('proof_url')) {
            $file = $request->file('proof_url');
            $year = Carbon::parse($validate['paid_at'])->format('Y');
            $folderId = $this->drive->getOrCreateFolderByPath("Application/struk_pembayaran/{$year}");
            if (!$folderId) {
                return back()->with('error', 'Gagal membuat folder Google Drive.');
            }
            $filename = $payment->order->contact->cp_email . "_struk." . $file->getClientOriginalExtension();
            $uploadResult = $this->drive->uploadFile($file, $folderId, true, $filename);
            if (!$uploadResult || !isset($uploadResult['url'])) {
                return back()->with('error', 'Gagal upload bukti. Coba lagi.');
            }
            $strukUrl = $uploadResult['url'];
        }

        DB::transaction(function () use ($payment, $validate, $strukUrl) {
            $payment->update([
                'amount'       => $validate['amount'],
                'payment_type' => $validate['payment_type'],
                'paid_at'      => $validate['paid_at'],
                'proof_url'    => $strukUrl,
            ]);

            // Rumus yang sama, tapi lewat satu pintu. Versi lama menganggap order
            // tanpa harga (cost 0) sebagai lunas, karena 0 - 0 <= 0.
            $payment->order?->recalcStatus();
        });

        return back()->with('success', 'Pembayaran berhasil diperbarui.');
    }

    public function approve($id)
    {
        $payment = Payment::with('approval')->findOrFail($id);

        if (optional($payment->approval)->status === 'approved') {
            return back()->with('info', 'Pembayaran sudah disetujui.');
        }

        try {
            $invoiceToEmail = null;

            DB::transaction(function () use ($payment, &$invoiceToEmail) {
                // Di sinilah 'paid' ditulis untuk PERTAMA kalinya — inilah yang membuat
                // approval punya arti bagi angka uang.
                $payment->update(['status' => 'paid']);

                $payment->approval()->update([
                    'status'      => 'approved',
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                ]);

                $invoice = Invoice::where('payment_id', $payment->id)->first();
                if ($invoice) {
                    $fromStatus = $invoice->status;
                    $invoice->update(['status' => 'lunas']);
                    \App\Models\InvoiceLog::create([
                        'invoice_id'  => $invoice->id,
                        'from_status' => $fromStatus,
                        'to_status'   => 'lunas',
                        'changed_by'  => auth()->id(),
                        'note'        => 'Disetujui otomatis saat payment approve.',
                    ]);

                    if ($invoice->email_requested) {
                        $invoiceToEmail = $invoice->id;
                    }
                }

                // Sama seperti di store(): dihitung dari nominal yang sudah disetujui,
                // bukan ditebak dari nama tipe pembayaran.
                $payment->load('order');
                $payment->order?->recalcStatus();
            });

            if (!empty($invoiceToEmail)) {
                SendInvoiceJob::dispatch($invoiceToEmail);
            }

            app(Notifier::class)->paymentApproved($payment, Auth::user());
            return redirect()->route('payment.index')->with('success', 'Pembayaran berhasil disetujui.');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal memproses approval: ' . $e->getMessage());
        }
    }

    public function reject($id)
    {
        // Validasi input note jika dikirim dari modal/form

        try {
            $payment = Payment::with('approval', 'order.user')->findOrFail($id);

            DB::transaction(function () use ($payment) {
                $payment->update(['status' => 'rejected']);

                $payment->approval()->update([
                    'status'      => 'rejected',
                    'note'        => 'Data tidak valid',
                    'approved_by' => auth()->id(),
                    'approved_at' => now(),
                ]);

                // Dihitung ulang, bukan disapu ke 'pending': order yang sudah lunas dari
                // pembayaran LAIN tak boleh ikut jatuh hanya karena satu pembayaran
                // tambahan ditolak.
                $payment->order?->recalcStatus();
            });

            app(Notifier::class)->paymentRejected($payment, Auth::user());
            return redirect()->route('payment.index')->with('warning', 'Pembayaran telah ditolak.');

        } catch (\Exception $e) {
            return back()->with('error', 'Gagal menolak pembayaran: ' . $e->getMessage());
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
        $payment = Payment::findOrFail($id);

        // Cegah hapus jika sudah approved (Opsional, tergantung kebijakan Anda)
        if($payment->status == 'paid') return back()->with('error', 'Pembayaran yang sudah diapprove tidak boleh dihapus.');

        $order = $payment->order;
        $payment->delete();
        $order?->recalcStatus();

        return back()->with('success', 'Data pembayaran berhasil dihapus.');
    }
}
