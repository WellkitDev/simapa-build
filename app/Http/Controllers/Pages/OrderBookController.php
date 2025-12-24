<?php

namespace App\Http\Controllers\Pages;

use Carbon\Carbon;
use App\Models\Order;
use App\Models\Scope;
use App\Models\Author;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Str;
use App\Helpers\Utf8Cleaner;
use App\Jobs\SendInvoiceJob;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Services\GoogleDriveService;
use Illuminate\Support\Facades\Auth;

class OrderBookController extends Controller
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
        $invoices = Invoice::with(['order.authors', 'order.users', 'order.payments'])
                       ->latest('issued_at')
                ->get();

        return view('pages.order.book.index', compact('invoices'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
        $scopes = Scope::all();
        return view('pages.order.book.create', compact('scopes'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
        $validate = $request->validate([
            'type'               => 'required|in:bk_mandiri,bk_kolab',
            'title'              => 'required|string|max:255',
            'scope_id'           => 'nullable',
            'chapters'           => 'nullable|integer|min:1',

            'naskah_type'        => 'required|in:dibuatkan,mandiri',
            'publication_type'   => 'required|in:regular,fastrack',

            'issued_at' => 'required|date',
            'dued_at' => 'required|date|after_or_equal:issued_at',
            'cost_amount'        => 'required|numeric|min:0',
            'pay_amount'         => 'required|numeric|min:0',
            'status'             => 'required|in:dp,lunas,pelunasan',
            'struk_payment'      => 'required|file|mimes:jpg,jpeg,png,pdf|max:10240',

            'contact_phone'      => 'required|string',
            'contact_email'      => 'required|email',

            'authors'            => 'required|array|min:1',
            'authors.*.name'     => 'required|string',
            'authors.*.email'    => 'nullable|email',
            'authors.*.phone'    => 'nullable|string',
            'authors.*.affiliation' => 'nullable|string',
            'authors.*.possition'   => 'required|integer|min:1',
            'note'               => 'nullable|string',
            'send_invoice_email' => 'sometimes|boolean',
        ]);

        $validate['title'] = Utf8Cleaner::clean($validate['title']);
        $validate['note']  = Utf8Cleaner::clean($validate['note'] ?? '');

        foreach ($validate['authors'] as &$author) {
            foreach ($author as $k => $v) {
                if (is_string($v)) {
                    $author[$k] = Utf8Cleaner::clean($v);
                }
            }
        }

        // Ambil title yang sudah dibersihkan
        $cleanTitle = $validate['title'];

        // Cek apakah sudah ada order dengan judul sama + email sama
        $existingOrder = Order::where('title', $cleanTitle)
                            ->where('contact_email', $request->contact_email)
                            ->first();

        if ($existingOrder) {
            return redirect()->back()
                    ->withInput()
                    ->with('error', 'Kamu sudah pernah membuat order dengan judul buku yang sama. Silakan cek riwayat order atau hubungi admin jika ada kesalahan.');
        }

        // Kalau lolos, lanjut generate slug seperti biasa (title + author utama)
        // Ambil nama author utama (posisi 1)
        $primaryAuthorName = '';
        if ($validate['authors'] && count($validate['authors']) > 0) {
            // Cari author dengan possition = 1
            $primary = collect($validate['authors'])->firstWhere('possition', 1);
            if ($primary) {
                // Bersihkan nama: hapus gelar, spasi berlebih
                $cleanName = preg_replace('/[^a-zA-Z0-9\s-]/', '', $primary['name']);
                $cleanName = strtolower(trim($cleanName));
                $cleanName = preg_replace('/\s+/', '-', $cleanName);
                $primaryAuthorName = $cleanName;
            }
        }

        $baseSlug = Str::slug($validate['title']);
        if ($primaryAuthorName) {
            $baseSlug .= '-' . $primaryAuthorName;
        }

        // Optional: tetap buat slug unik kalau kebetulan bentrok (jarang, tapi aman)
        $slug = $baseSlug;
        $counter = 1;
        do {
            $exists = Order::where('slug', $slug)->exists();
            if ($exists) {
                $counter++;
                $slug = $baseSlug . '-' . $counter;
            }
        } while ($exists);

        // Generate code_order (misal ORD-202512-0001)
        $yearMonth = date('Ym');
        $lastOrder = Order::where('code_order', 'like', "ORD-{$yearMonth}-%")->latest()->first();
        $seq = $lastOrder ? intval(substr($lastOrder->code_order, -4)) + 1 : 1;
        $codeOrder = "ORD-{$yearMonth}-" . str_pad($seq, 4, '0', STR_PAD_LEFT);
        // Simpan Order
        $order = Order::create([
            'code_order' => $codeOrder,
            'type' => $validate['type'],
            'title' => $validate['title'],
            'slug' => $slug,
            'chapters' => $validate['chapters'] ?? null,
            'indexation' => $validate['indexation'] ?? null,
            'naskah_type' => $validate['naskah_type'],
            'publication_type' => $validate['publication_type'],
            'cost_amount' => $validate['cost_amount'],
            'pay_amount' => $validate['pay_amount'],
            'debit_amount' => $validate['cost_amount'] - $validate['pay_amount'], // Sisa
            'status' => 'pending',
            'user_id' => Auth::id(),
            'note' => $validate['note'],
            'contact_phone' => $validate['contact_phone'],
            'contact_email' => $validate['contact_email'],
        ]);

        // Handle science (create new if not exists)
        $scope_id = $request->scope_id;
        if (!is_numeric($scope_id)) {
            $scope = Scope::firstOrCreate(['scope' => $scope_id]);
            $scope_id = $scope->id;
        }
        $order->scopes()->attach($scope_id);

        // Simpan Authors
        $authorPivots = [];
        foreach ($validate['authors'] as $authorData) {
            $author = Author::create($authorData);
            $authorPivots[$author->id] = ['possition' => $authorData['possition']];
        }
        $order->authors()->attach($authorPivots);


        // === UPLOAD STRUK (tetap sama, tanpa ubah handle image) ===
        $strukId = null;
        $strukUrl = null;

        if ($request->hasFile('struk_payment')) {
            $file = $request->file('struk_payment');
            $year = Carbon::parse($validate['issued_at'])->format('Y');
            $folderPath = "Application/struk_pembayaran/{$year}";

            $folderId = $this->drive->getOrCreateFolderByPath($folderPath);
            if (!$folderId) {
                return back()->with('error', 'Gagal membuat folder Google Drive.');
            }

            $filename = $validate['contact_email'] . "_struk." . $file->getClientOriginalExtension();
            $uploadResult = $this->drive->uploadFile($file, $folderId, true, $filename);

            if (!$uploadResult || !isset($uploadResult['id'])) {
                Log::error('Upload struk gagal', $uploadResult ?? []);
                return back()->with('error', 'Gagal upload struk. Coba lagi.');
            }

            $strukId = $uploadResult['id'];
            $strukUrl = $uploadResult['url'];
        }

        // Simpan Payment
        $payment = Payment::create([
            'order_id' => $order->id,
            'type' => $validate['status'],
            'amount' => $validate['pay_amount'],
            'date' => $validate['issued_at'],
            'struk_id' => $strukId,
            'struk_url' => $strukUrl,
            'status' => 'paid', // Asumsi awal paid
        ]);

        // Generate Invoice
        $invNo = "INV-" . str_replace('ORD-', '', $codeOrder). '-' . $payment->id; // Misal INV-202512-0001-01

        $invoiceDetails = [ // Array untuk cast
            'jenis_layanan' => $order->type,
            'judul' => $order->title,
            'jumlah_bab' => $order->chapters,
            'scope' => $order->scopes->pluck('scope')->implode(', '),
            'target_indeksasi' => $order->indexation,
            'jumlah_penulis' => count($validate['authors']),
            'penulis' => $order->authors->sortBy('pivot.possition')->map(function($a) {
                return $a->name . ' (' . $a->affiliation . ')';
            })->toArray(),
            'kontak' => $order->contact_phone . ' | ' . $order->contact_email,
            'marketing' => Auth::user()->name, // Asumsi
            'total_tagihan' => $order->cost_amount,
            // Tambah riwayat pembayaran dll.
        ];
        $invoiceDetails = Utf8Cleaner::clean($invoiceDetails);
        $invoice = Invoice::create([
            'order_id' => $order->id,
            'inv_no' => $invNo,
            'details' => Utf8Cleaner::clean($invoiceDetails),
            'issued_at' => $validate['issued_at'],
            'dued_at' => $validate['dued_at'],
        ]);

        // Generate PDF dulu di controller
        $pdf = Pdf::loadView('pages.invoices.order_book_inv', [
            'order'   => $order,
            'invoice' => $invoice,
            'payment' => $payment,
        ]);

        $pdfContent = $pdf->output();
        $fileName   = $invoice->inv_no . '.pdf';

        // === UPLOAD PDF KE GOOGLE DRIVE (sync di controller) ===
        try {
            // Simpan temporary dulu
            $tempPath = storage_path('app/temp/' . $fileName);
            if (!file_exists(dirname($tempPath))) {
                mkdir(dirname($tempPath), 0755, true);
            }
            file_put_contents($tempPath, $pdfContent);

            // Buat folder di Drive
            $folderPath = "Application/orders/{$order->id}/invoices";
            $folderId   = $this->drive->getOrCreateFolderByPath($folderPath);

            if ($folderId) {
                $uploadResult = $this->drive->uploadFile($tempPath, $folderId, true);

                if ($uploadResult && isset($uploadResult['id'])) {
                    // Update invoice dengan link Drive
                    $invoice->update([
                        'inv_pdf_id'  => $uploadResult['id'],
                        'inv_pdf_url' => $uploadResult['url'],
                    ]);
                }
            }

            // Hapus temp file
            @unlink($tempPath);
        } catch (\Exception $e) {
            Log::error('Gagal upload PDF invoice ke Drive', ['error' => $e->getMessage()]);
            // Tetap lanjut kirim email meskipun upload gagal
        }

        // Jika send_invoice_email
        if ($request->boolean('send_invoice_email')) {
            // Dispatch ke queue
            SendInvoiceJob::dispatch($invoice->id);
        }
        if ($order->slug) {
            # code...
        }

        return redirect()->back()->with('success', 'Order berhasil disimpan!');
    }

    /**
     * Display the specified resource.
     */
    public function show()
    {
        //
        return view('pages.order.book.show');
    }
    public function inv()
    {
        //
        return view('pages.invoices.inv_book');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
        return view('pages.order.book.edit');
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
