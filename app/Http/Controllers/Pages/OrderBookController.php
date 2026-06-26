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
use App\Models\TitleProgress;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Services\GoogleDriveService;
use App\Services\TitleArchiveService;
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
        // Mengambil order milik user yang sedang login dengan relasi payments
        // $orders = Order::with(['payments.approval', 'details.authors'])
        //     ->where('user_id', auth()->id()) // Sesuaikan dengan sistem auth Anda
        //     ->latest()
        //     ->get();
        $orders = Order::with(['payments.approval', 'details.authors'])
            ->when(Auth::user()->hasRole('marketing'), function ($q) {
                return $q->where('user_id', Auth::id());
            }) // Sesuaikan dengan sistem auth Anda
            ->latest()
            ->get();

        return view('orders.book.index', compact('orders'));
    }

    public function indexJudul(TitleArchiveService $archive)
    {
        $details = OrderDetail::with(['order.user', 'authors', 'titleProgress'])
            ->when(Auth::user()->hasRole('marketing'), fn ($q) =>
                $q->whereHas('order', fn ($o) => $o->where('user_id', Auth::id())))
            ->get();

        $judulData = $archive->groupDetails($details);

        return view('orders.index-title', compact('judulData'));
    }

    public function detailJudul($id, TitleArchiveService $archive)
    {
        $base = OrderDetail::query()
            ->when(Auth::user()->hasRole('marketing'), fn ($q) =>
                $q->whereHas('order', fn ($o) => $o->where('user_id', Auth::id())));

        $clicked = (clone $base)->with('order')->findOrFail($id);
        $key     = $archive->groupKey($clicked);

        $details = (clone $base)
            ->with(['order.user', 'authors', 'titleProgress'])
            ->get()
            ->filter(fn ($d) => $archive->groupKey($d) === $key)
            ->values();

        $summary = $archive->summarize($details);

        return view('orders.detail-title-group', compact('summary', 'details'));
    }

    public function progressDetail($id)
    {
        $detail = OrderDetail::with([
                'authors',
                'scopes',
                'order.user',
                'titleProgress.logs.changedBy',
            ])
            ->where('id', $id)
            ->whereHas('order', function ($q) {
                $q->when(Auth::user()->hasRole('marketing'), fn ($query) =>
                    $query->where('tb_orders.user_id', Auth::id()));
            })
            ->firstOrFail();

        // Fallback: create TitleProgress for legacy data created before this feature.
        if (!$detail->titleProgress) {
            DB::transaction(function () use ($detail) {
                TitleProgress::create([
                    'order_detail_id' => $detail->id,
                    'status'          => 'menunggu_proses',
                    'assigned_role'   => 'marketing',
                    'updated_by'      => Auth::id(),
                    'started_at'      => now(),
                ]);
            });
            $detail->load('titleProgress.logs.changedBy');
        }

        return view('orders.detail-title', compact('detail'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request)
    {
        $scopes = Scope::all();

        $prefill = [];
        $fromTagihan = null;
        if ($request->filled('from_tagihan')) {
            $t = \App\Models\Tagihan::where('id', $request->integer('from_tagihan'))
                ->where('created_by', Auth::id())
                ->where('status', 'disetujui')
                ->first();
            if ($t) {
                $fromTagihan = $t->id;
                $prefill = [
                    'title'         => $t->title,
                    'contact_email' => $t->client_email,
                    'contact_phone' => $t->client_phone,
                    'cost_amount'   => $t->amount,
                    'note'          => $t->note,
                    'type'          => in_array($t->type, ['bk_mandiri', 'bk_kolab'], true) ? $t->type : null,
                ];
            }
        }

        return \view('orders.book.create', \compact('scopes', 'prefill', 'fromTagihan'));
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
                    'chapters' => $validate['chapters'] ?? null,
                    'naskah_type' => $validate['naskah_type'],
                    'publication_type' => $validate['publication_type'],
                    'cost_amount' => $validate['cost_amount'],
                ]);

                //handlle science scope (create new if not exists)
                $scope_id = $validate['scope_id'] ?? null;
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

                // Auto-create TitleProgress (warisi status grup jika judul sudah ada).
                app(\App\Services\TitleProgressService::class)->createForDetail($detail, Auth::id());

                // ORDER CONTACT
                OrderContact::create([
                    'order_id' => $order->id,
                    'cp_phone'    => $validate['contact_phone'],
                    'cp_email'    => $validate['contact_email'],
                ]);
                return $order;
            });

            // Link-back: jika order dibuat dari tagihan, tandai tagihan jadi_order.
            try {
                if ($request->filled('from_tagihan')) {
                    $t = \App\Models\Tagihan::where('id', $request->integer('from_tagihan'))
                        ->where('created_by', Auth::id())
                        ->where('status', 'disetujui')
                        ->first();
                    if ($t) {
                        $t->update([
                            'status'     => 'jadi_order',
                            'order_id'   => $newOrder->id,
                            'order_code' => $newOrder->code_order,
                        ]);
                        \App\Models\TagihanLog::create([
                            'tagihan_id'  => $t->id,
                            'from_status' => 'disetujui',
                            'to_status'   => 'jadi_order',
                            'changed_by'  => Auth::id(),
                            'note'        => 'Order ' . $newOrder->code_order . ' dibuat dari tagihan.',
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Tagihan link-back failed: ' . $e->getMessage());
            }

            return redirect()
                ->route('payment.create', ['code_order' => $newOrder->code_order])
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
    public function show($code_order)
    {
        //
       // Gunakan where() untuk mencari berdasarkan code_order alih-alih ID
        $order = Order::with([
        'details.authors',
        'details.scopes',
        'payments.approval',
        'payments.invoice',
        'invoices',
        'contact'
        ])->where('code_order', $code_order)->firstOrFail();

        // Ambil detail pertama (karena hasMany tetapi di logika Anda biasanya hanya ada 1)
        $firstDetail = $order->details;

        // Hitung Keuangan
        // Pastikan hanya menjumlahkan pembayaran yang statusnya 'paid' atau 'approved'
        $totalCost = $firstDetail->cost_amount ?? 0;

        $alreadyPaid = $order->payments
        ->where('status', 'paid')
        ->sum('amount');

        $remainingBalance = $totalCost - $alreadyPaid;

        return view('orders.book.show', compact(
        'order',
        'firstDetail',
        'totalCost',
        'alreadyPaid',
        'remainingBalance'
        ));
    }
    public function inv()
    {
        //
        return view('pages.invoices.inv_book');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $code_order)
    {
        //
        // Load order beserta semua relasinya
        $order = Order::with(['details.authors', 'contact', 'invoices'])->where('code_order', $code_order)->firstOrFail();

        // Ambil data scope untuk dropdown
        $scopes = Scope::all();

        return view('orders.edit', compact('order', 'scopes'));

    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $code_order)
    {
        //
        // 1. Validasi Input
        $request->validate([
            'type'               => 'required|in:bk_mandiri,bk_kolab,at_mandiri,at_kolab',
            'title'              => 'required|string|max:255',
            'scope_id'           => 'nullable',
            'chapters'           => 'nullable|integer|min:1',
            'naskah_type'        => 'required|in:dibuatkan,mandiri',
            'publication_type'   => 'required|in:regular,fastrack',
            'indexation'         => 'nullable|string',
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
        ]);

        try {
            DB::transaction(function () use ($request, $code_order) {
                $order = Order::findOrFail($code_order);
                $order->update([
                    'note' => $request->note,
                    'ordered_at' => $request->issued_at,
                ]);
                $index = '';
                if ($request->has('indexation')) {
                    $index = $request->indexation;
                }
                $bab = '0';
                if ($request->has('chapters')) {
                    $bab = $request->chapters;
                }
                // 2. Update Detail Order
                $order->details()->update([
                    'title' => $request->title,
                    'type' => $request->type,
                    'indexation' =>  $index,
                    'chapters' => $bab,
                    'naskah_type' => $request->naskah_type,
                    'publication_type' => $request->publication_type,
                    'cost_amount' => $request->cost_amount,
                ]);

                if ($request->has('scope_id')) {
                    // Jika scope_id di form edit hanya bisa pilih satu (bukan multiple)
                    // sync akan menghapus scope lama dan menggantinya dengan yang baru
                    $order->details->scopes()->sync([$request->scope_id]);
                }

                // 3. Update Contact Person
                $order->contact()->update([
                    'cp_phone' => $request->contact_phone,
                    'cp_email' => $request->contact_email,
                ]);

                // 4. Update Authors (Hapus yang lama, simpan yang baru dari form)
                $detail = $order->details;
                $detail->authors()->detach();

                foreach ($request->authors as $authorData) {
                    // Cari atau buat author berdasarkan email (agar tidak duplikat di tb_authors)
                    $author = \App\Models\Author::updateOrCreate(
                        ['email' => $authorData['email']],
                        [
                            'name'        => $authorData['name'],
                            'affiliation' => $authorData['affiliation'] ?? null,
                            'phone'       => $authorData['phone'] ?? null,
                        ]
                    );

                    // Hubungkan kembali ke tabel pivot dengan data position
                    $detail->authors()->attach($author->id, [
                        'position' => $authorData['position'] ?? 1,
                    ]);
                }

            });

            return redirect()->route('order.book.index')->with('success', 'Order #' . $code_order . ' berhasil diperbarui.');

        } catch (\Exception $e) {
            return back()->with('error', 'Terjadi kesalahan: ' . $e->getMessage())->withInput();
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
