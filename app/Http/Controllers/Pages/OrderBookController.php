<?php

namespace App\Http\Controllers\Pages;

use Carbon\Carbon;
use App\Models\Order;
use App\Models\Scope;
use App\Models\Author;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\OrderDetail;
use Illuminate\Support\Str;
use App\Helpers\Utf8Cleaner;
use App\Jobs\SendInvoiceJob;
use App\Models\OrderContact;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
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
        return \view('orders.book.create', \compact('scopes'));
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

            'issued_at'          => 'required|date',
            'cost_amount'        => 'required|numeric|min:0',
            'contact_phone'      => 'required|string',
            'contact_email'      => 'required|email',

            'authors'            => 'required|array|min:1',
            'authors.*.name'     => 'required|string',
            'authors.*.email'    => 'nullable|email',
            'authors.*.phone'    => 'nullable|string',
            'authors.*.affiliation' => 'nullable|string',
            'authors.*.position'   => 'required|integer|min:1',
            'note'               => 'nullable|string',
            'send_invoice_email' => 'sometimes|boolean',
        ]);

        // Mencari Order yang memiliki Detail dengan judul sama DAN Contact dengan email sama
        $isDuplicate = Order::whereHas('details', function ($query) use ($validate) {
                $query->where('title', $validate['title']);
            })
            ->whereHas('contact', function ($query) use ($validate) {
                $query->where('cp_email', $validate['contact_email']);
            })
            ->exists();

        // Jika ditemukan duplikat, return kembali dengan pesan error
        if ($isDuplicate) {
            return redirect()->back()
                ->withInput() // Mengembalikan input user agar tidak perlu ketik ulang
                ->with('error', 'Gagal: Judul naskah dengan Email kontak tersebut sudah terdaftar sebelumnya.');
        }

        // PROSES TRANSAKSI (JIKA TIDAK DUPLIKAT)
        try {
            $newOrder = DB::transaction(function () use ($validate) {
                // Generate code_order (misal ORD-202512-0001)
                $yearMonth = date('Ym');
                $lastOrder = Order::where('code_order', 'like', "ORD-{$yearMonth}-%")->lockForUpdate()->latest()->first();
                $seq = $lastOrder ? intval(substr($lastOrder->code_order, -4)) + 1 : 1;
                $codeOrder = "ORD-{$yearMonth}-" . str_pad($seq, 4, '0', STR_PAD_LEFT);

                // ORDER (HEAD)
                $order = Order::create([
                    'code_order' => $codeOrder,
                    'user_id' => Auth::user()->id,
                    'status' => 'pending',
                    'note' => $validate['note'] ?? null,
                    'ordered_at' => $validate['issued_at'],
                ]);

                // Generate slug
                $cleanTitle = $validate['title'];
                $baseSlug = Str::slug($cleanTitle);
                $finalSlug = $baseSlug . '-' . $order->id;

                // ORDER DETAIL
                $detail = OrderDetail::create([
                    'order_id' => $order->id,
                    'type' => $validate['type'],
                    'title' => $validate['title'],
                    'slug' => $finalSlug,
                    'chapters' => $validate['chapters'],
                    'naskah_type' => $validate['naskah_type'],
                    'publication_type' => $validate['publication_type'],
                    'cost_amount' => $validate['cost_amount'],
                ]);

                //handlle science scope (create new if not exists)
                $scope_id = $validate['scope_id'];
                if (!is_numeric($scope_id) && !empty($scope_id)) {
                    $scope = Scope::firstOrCreate(['scope' => $scope_id]);
                    $scope_id = $scope->id;
                }

                if($scope_id) {
                    $detail->scopes()->attach($scope_id);
                }

                // Handle Save Authors
                $authorPivots = [];
                foreach ($validate['authors'] as $authorData) {
                    $author = Author::create($authorData);
                    $authorPivots[$author->id] = ['position' => $authorData['position']];
                }
                $detail->authors()->attach($authorPivots);

                // ORDER CONTACT
                OrderContact::create([
                    'order_id' => $order->id,
                    'cp_phone'    => $validate['contact_phone'],
                    'cp_email'    => $validate['contact_email'],
                ]);
                return $order;
            });
            return redirect()
                ->route('order.book.payment.create', ['code_order' => $newOrder->code_order])
                ->with('success', 'Order berhasil dibuat');
        } catch (\Exception $e) {
            // Tangkap pesan error dari throw di atas
            return response()->json([
                'message' => $e->getMessage()
            ], 422);
        }

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
