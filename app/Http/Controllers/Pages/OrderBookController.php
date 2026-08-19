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
use App\Models\Title;
use App\Services\GoogleDriveService;
use App\Services\OrderCancellationService;
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
    public function index(Request $request)
    {
        $trashed = $request->boolean('trashed');

        // details di-eager load withTrashed() karena detail order yang dibatalkan
        // ikut soft-deleted — tanpa ini kolom Judul/Penulis null dan view pecah.
        $orders = Order::with([
                'payments.approval',
                'details' => fn ($q) => $q->withTrashed(),
                'details.authors',
            ])
            ->when($trashed, fn ($q) => $q->onlyTrashed())
            ->when(Auth::user()->hasRole('marketing'), fn ($q) => $q->where('user_id', Auth::id()))
            ->latest()
            ->get();

        return view('orders.book.index', compact('orders', 'trashed'));
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

        $titles = Title::where('status', 'disetujui')->active()->where('jenis', 'buku')
            ->when(! Auth::user()->hasAnyRole(['manager', 'superadmin']), function ($q) {
                $q->where(function ($qq) {
                    $qq->whereNull('assigned_to')->orWhere('assigned_to', Auth::id());
                });
            })
            ->with('scope')->orderBy('title')->get();

        return \view('orders.book.create', \compact('scopes', 'prefill', 'fromTagihan', 'titles'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
        $validate = $request->validate([
            'type'               => 'required|in:bk_mandiri,bk_kolab',
            'title_id'           => 'required|string|max:300',
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
        ] + app(\App\Services\OrderOwnerService::class)->aturanValidasi(Auth::user()));

        // Nama judul: prefix "new:" dipangkas, id dipetakan ke nama judulnya.
        $titleName = app(\App\Services\TitleService::class)->titleNameFrom($validate['title_id']);

        if ($titleName === '' || mb_strlen($titleName) > 255) {
            return redirect()->back()->withInput()
                ->withErrors(['title_id' => 'Judul wajib diisi dan maksimal 255 karakter.']);
        }

        // Mencari Order yang memiliki Detail dengan judul sama DAN Contact dengan email sama
        $isDuplicate = Order::whereHas('details', function ($query) use ($titleName) {
                $query->where('title', $titleName);
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
                    // Superadmin boleh menugaskan order ke marketing lain atau ke
                    // dirinya sendiri; role lain selalu jadi pemiliknya sendiri.
                    'user_id' => app(\App\Services\OrderOwnerService::class)
                        ->tentukan(Auth::user(), $validate['user_id'] ?? null),
                    'status' => 'pending',
                    'note' => $validate['note'] ?? null,
                    'ordered_at' => $validate['issued_at'],
                ]);

                // Resolusi scope dulu (dipakai untuk judul baru dari order).
                $scope_id = $validate['scope_id'] ?? null;
                if (!is_numeric($scope_id) && !empty($scope_id)) {
                    $scope_id = Scope::firstOrCreate(['scope' => $scope_id])->id;
                }

                // Resolusi judul: id yang ada, atau buat Title baru (asal=order) dari field order.
                $title = app(\App\Services\TitleService::class)->resolveForOrder($validate['title_id'], [
                    'jenis'      => 'buku',
                    'order_type' => $validate['type'],
                    'scope_id'   => $scope_id ?: null,
                    'indeksasi'  => null,
                ], Auth::user());

                // ORDER DETAIL
                $detail = OrderDetail::create([
                    'order_id' => $order->id,
                    'type' => $validate['type'],
                    'title_id' => $title->id,
                    'title' => $title->title,
                    'slug' => Str::slug($title->title) . '-' . $order->id,
                    'chapters' => $validate['chapters'] ?? null,
                    'naskah_type' => $validate['naskah_type'],
                    'publication_type' => $validate['publication_type'],
                    'cost_amount' => $validate['cost_amount'],
                ]);

                if ($scope_id) {
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
        // withTrashed() di dua tingkat: order yang dibatalkan harus tetap bisa dilihat
        // read-only, dan detail-nya ikut soft-deleted saat pembatalan.
        $order = Order::withTrashed()->with([
            'details' => fn ($q) => $q->withTrashed(),
            'details.authors',
            'details.scopes',
            'payments.approval',
            'payments.invoice',
            'invoices',
            'contact',
            'cancelledBy',
        ])->where('code_order', $code_order)->firstOrFail();

        // Marketing hanya boleh membuka order miliknya sendiri — menyamakan halaman ini
        // dengan filter kepemilikan yang sudah dipakai index() dan destroy(). Sebelum
        // withTrashed() di atas, order yang dibatalkan 404 untuk semua orang; tanpa
        // penjagaan ini, membukanya justru jadi lebih longgar daripada sebelumnya.
        abort_if(Auth::user()->hasRole('marketing') && $order->user_id !== Auth::id(), 403);

        $firstDetail = $order->details;

        // Hitung Keuangan
        $totalCost = $firstDetail->cost_amount ?? 0;

        $alreadyPaid = $order->paidNet();

        $remainingBalance = $totalCost - $alreadyPaid;

        return view('orders.book.show', compact(
        'order',
        'firstDetail',
        'totalCost',
        'alreadyPaid',
        'remainingBalance'
        ));
    }
    // Catatan: inv() dihapus — tak ada route/referensi ke method ini dan
    // view-nya ('pages.invoices.inv_book') sudah tidak ada.

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $code_order)
    {
        //
        // Load order beserta semua relasinya
        $order = Order::withTrashed()->with(['details' => fn ($q) => $q->withTrashed(), 'details.authors', 'contact', 'invoices'])
            ->where('code_order', $code_order)->firstOrFail();
        abort_unless($order->isEditable(), 403);

        // Ambil data scope untuk dropdown
        $scopes = Scope::all();
        $titles = Title::where('status', 'disetujui')->active()->where('jenis', 'buku')
            ->when(! Auth::user()->hasAnyRole(['manager', 'superadmin']), function ($q) {
                $q->where(function ($qq) {
                    $qq->whereNull('assigned_to')->orWhere('assigned_to', Auth::id());
                });
            })
            ->with('scope')->orderBy('title')->get();

        return view('orders.edit', compact('order', 'scopes', 'titles'));

    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $code_order)
    {
        // Pemilik sekarang ikut jadi nilai yang sah: order lama bisa dimiliki user
        // non-marketing, dan menyimpan ulang nilai itu tak boleh ditolak.
        $pemilikSekarang = Order::withTrashed()->find($code_order)?->user_id;

        // 1. Validasi Input
        $request->validate([
            'type'               => 'required|in:bk_mandiri,bk_kolab,at_mandiri,at_kolab',
            'title_id'           => 'required|string|max:300',
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
        ] + app(\App\Services\OrderOwnerService::class)->aturanValidasi(Auth::user(), $pemilikSekarang));

        $titleName = app(\App\Services\TitleService::class)->titleNameFrom($request->title_id);
        if ($titleName === '' || mb_strlen($titleName) > 255) {
            return back()->withInput()
                ->withErrors(['title_id' => 'Judul wajib diisi dan maksimal 255 karakter.']);
        }

        // Resolusi order di update() memakai findOrFail (form Edit buku mengirim
        // $order->id sebagai parameter {code_order}) — penjagaan harus memakai
        // resolusi yang sama persis agar tidak meleset ke order lain.
        abort_unless(Order::withTrashed()->findOrFail($code_order)->isEditable(), 403);

        try {
            DB::transaction(function () use ($request, $code_order) {
                $order = Order::findOrFail($code_order);
                $order->update([
                    'note' => $request->note,
                    'ordered_at' => $request->issued_at,
                    // Superadmin boleh memindahkan order ke marketing lain; role lain
                    // tidak, jadi bagi mereka nilainya tetap seperti semula.
                    'user_id' => app(\App\Services\OrderOwnerService::class)
                        ->tentukanUntukPerubahan(Auth::user(), $request->input('user_id'), $order->user_id),
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
                $detail = $order->details;

                $scopeId = null;
                if ($request->filled('scope_id')) {
                    $scopeId = $request->scope_id;
                    if (! is_numeric($scopeId)) {
                        $scopeId = Scope::firstOrCreate(['scope' => $scopeId])->id;
                    }
                }

                $title = app(\App\Services\TitleService::class)->resolveForOrder($request->title_id, [
                    'jenis'      => 'buku',
                    'order_type' => $request->type,
                    'scope_id'   => $scopeId,
                    'indeksasi'  => null,
                ], Auth::user());

                $order->details()->update([
                    'title_id'         => $title->id,
                    'title'            => $title->title,
                    'type'             => $request->type,
                    'indexation'       => $index,
                    'chapters'         => $bab,
                    'naskah_type'      => $request->naskah_type,
                    'publication_type' => $request->publication_type,
                    'cost_amount'      => $request->cost_amount,
                ]);

                if ($scopeId) {
                    $detail->scopes()->sync([$scopeId]);
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
     * Batalkan order (soft delete berjenjang). Dipakai order buku MAUPUN jurnal —
     * route-nya generik di grup order.*.
     *
     * Tanpa try/catch: OrderCancellationException punya render() sendiri (pola
     * CashEntryGuardException) yang mengubah dirinya jadi back()->with('error').
     */
    public function destroy(Request $request, string $code_order)
    {
        $order = Order::where('code_order', $code_order)->firstOrFail();

        // Marketing hanya boleh menyentuh order miliknya sendiri — sejalan dengan
        // filter kepemilikan yang sudah dipakai index().
        abort_if(Auth::user()->hasRole('marketing') && $order->user_id !== Auth::id(), 403);

        $data = $request->validate(['cancel_reason' => 'nullable|string|max:1000']);

        app(OrderCancellationService::class)->cancel($order, $data['cancel_reason'] ?? null, Auth::user());

        return redirect()->route('order.book.index')
            ->with('success', 'Order ' . $order->code_order . ' dibatalkan.');
    }

    /** Pulihkan order yang dibatalkan (manager/superadmin — dijaga permission order.restore). */
    public function restore(string $code_order)
    {
        $order = Order::withTrashed()->where('code_order', $code_order)->firstOrFail();

        app(OrderCancellationService::class)->restore($order, Auth::user());

        return redirect()->route('order.book.index')
            ->with('success', 'Order ' . $order->code_order . ' dipulihkan.');
    }
}
