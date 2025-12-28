<?php

namespace App\Http\Controllers\Pages;

use Carbon\Carbon;
use App\Models\Order;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\Request;
use App\Models\PaymentApproval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Services\GoogleDriveService;

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
                    ->latest()
                    ->get();

        return view('payments.book.index', compact('payments'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(string $code_order)
    {
        //
        $order = Order::with(['details', 'contact'])
            ->where('code_order', $code_order)
            ->firstOrFail();

        if ($order->status !== 'pending') {
            return redirect()->back()->with('error', 'Status order tidak valid.');
        }

        // Mengambil detail pertama dari collection hasMany
        $firstDetail = $order->details->first();
        if (!$firstDetail) {
            return redirect()->back()->with('error', 'Detail order tidak ditemukan.');
        }

        // LOGIKA PERHITUNGAN
        $totalCost = $firstDetail->cost_amount;
        $alreadyPaid = $order->payments->sum('amount');
        $remainingBalance = $totalCost - $alreadyPaid;

        // Jika sudah lunas tapi masih buka halaman ini
        if ($remainingBalance <= 0) {
            return redirect()->route('orders.index')->with('info', 'Order ini sudah lunas.');
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
            $newOrder = DB::transaction(function () use ($validate, $order, $strukUrl) {
                $payment = Payment::create([
                    'order_id'     => $order->id,
                    'payment_type' => $validate['status'],
                    'amount'       => $validate['pay_amount'],
                    'proof_url'    => $strukUrl,
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
                    'status'     => 'paid',
                ]);

                // APPROVAL
                PaymentApproval::create([
                    'payment_id' => $payment->id,
                    'status'     => 'pending',
                ]);
            });

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

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
