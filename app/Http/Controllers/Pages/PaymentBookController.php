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
        //
        $payments = Payment::with('order', 'invoice', 'approval')
            ->whereHas('order', function($q) {
                $q->with('details')->when(Auth::user()->hasRole('marketing'), function ($query) {
                    return $query->where('user_id', Auth::id());
                });
            })
            ->latest()
            ->get();

        return view('payments.book.index', compact('payments'));
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
        $alreadyPaid = $order->payments->where('status', 'paid')->sum('amount');
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
            'status'         => 'required|in:dp,lunas,pelunasan',
            'pay_amount'     => 'required|numeric|min:1',
            'proof_url'      => 'required|file|mimes:jpg,jpeg,png,pdf|max:10240',
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

        try {
            $invoiceId = DB::transaction(function () use ($validate, $order, $strukUrl) {
                $payment = Payment::create([
                    'order_id'     => $order->id,
                    'payment_type' => $validate['status'],
                    'amount'       => $validate['pay_amount'],
                    'proof_url'    => $strukUrl,
                    'paid_at'      => $validate['issued_at'],
                    'status'       => 'paid',
                ]);

                // INVOICE
                $invNo = "INV-" . str_replace('ORD-', '', $order->code_order). '-' . $payment->id;
                $invoice = Invoice::create([
                    'order_id'   => $order->id,
                    'payment_id' => $payment->id,
                    'invoice_no' => $invNo,
                    'issued_at'  => $validate['issued_at'],
                    'due_at'     => $validate['dued_at'],
                    'status'     => 'pending',
                ]);

                // APPROVAL
                PaymentApproval::create([
                    'payment_id' => $payment->id,
                    'status'     => 'pending',
                ]);

                 if ($payment->payment_type === 'lunas' || $payment->payment_type === 'pelunasan') {
                    $order->update([
                        'status' => 'lunas',
                    ]);
                 }

                return $invoice->id;
            });
            // Jika send_invoice_email
            if ($request->boolean('send_invoice_email')) {
                // Dispatch ke queue
                SendInvoiceJob::dispatch($invoiceId);
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
        //
    }

    //
    public function printInvoice(string $code_order)
    {
        // 1. Cari Invoice yang order-nya punya code_order ini
        // Pakai whereHas biar tetap mulai dari Invoice (seperti job yang sudah final)
        $invoice = Invoice::with([
            'order.details.authors',
            'order.details.scopes',
            'order.payments' => function ($query) {
                $query->where('status', 'paid')->orderBy('paid_at', 'asc');
            },
            'order.contact'
        ])
        ->whereHas('order', function ($query) use ($code_order) {
            $query->where('code_order', $code_order);
        })
        ->firstOrFail(); // Kalau gak ketemu → 404 otomatis

        // 2. Ambil relasi yang pasti ada
        $order  = $invoice->order;
        $detail = $order->details;

        if (!$detail) {
            abort(404, 'Detail order tidak ditemukan.');
        }

        // 3. Hitung keuangan — hanya dari payment yang sudah paid
        $totalCost        = $detail->cost_amount ?? 0;
        $alreadyPaid      = $order->payments->sum('amount'); // sudah difilter di query
        $remainingBalance = $totalCost - $alreadyPaid;

        // 4. Siapkan data untuk view (sama persis seperti di job)
        $data = [
            'invoice'          => $invoice,
            'order'            => $order,
            'detail'           => $detail,
            'totalCost'        => $totalCost,
            'alreadyPaid'      => $alreadyPaid,
            'remainingBalance' => $remainingBalance,
        ];

        // 5. Generate PDF
        $pdf = Pdf::loadView('payments.invoices.book_invoice_pdf', $data);
        // atau nama view yang kamu pakai, misal: 'pdf.invoice-book'

        // 6. Stream ke browser
        return $pdf->stream('Invoice_' . $invoice->invoice_no . '.pdf');
    }

    public function approve($id)
    {
        try {
            DB::transaction(function () use ($id) {
                $payment = Payment::with(['approval', 'order'])->findOrFail($id);

                // 1. Update status di tb_payments
                $payment->update(['status' => 'paid']);

                // 2. Update status di tb_payment_approvals
                $payment->approval()->update([
                    'status' => 'approved',
                    'approved_by' => auth()->id(),
                    'approved_at' => now()
                ]);

                // Optional: Jika ini pelunasan, update status Order jadi 'success'
                if ($payment->payment_type === 'lunas' || $payment->payment_type == 'pelunasan') {
                    $payment->order->update(['status' => 'lunas']);
                }
            });

            return redirect()->route('payment.index')->with('success', 'Pembayaran berhasil disetujui.');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal memproses approval: ' . $e->getMessage());
        }
    }

    public function reject($id)
    {
        // Validasi input note jika dikirim dari modal/form

        try {
            DB::transaction(function () use ($id) {
                // Gunakan with('approval') agar lebih efisien
                $payment = Payment::with('approval')->findOrFail($id);

                // 1. Update status di tb_payments menjadi 'failed' atau 'rejected'
                // Sesuaikan dengan enum/string yang Anda gunakan di database
                $payment->update(['status' => 'rejected']);

                // 2. Update status di tb_payment_approvals
                // Pastikan menggunakan updateOrCreate jika ada kemungkinan data approval belum terbuat
                $payment->approval()->update([
                    'status'      => 'rejected',
                    'note'        => 'Data tidak valid', // Alasan dari input user
                    'approved_by' => auth()->id(),
                    'approved_at' => now()
                ]);

                // 3. Optional: Jika pembayaran ditolak, pastikan status Order tetap 'pending'
                // atau kembali ke status sebelumnya (tidak menjadi 'lunas')
                if ($payment->order) {
                    $payment->order->update(['status' => 'pending']);
                }
            });

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

        $payment->delete();
        return back()->with('success', 'Data pembayaran berhasil dihapus.');
    }
}
