<?php

namespace App\Http\Controllers\Pages;

use App\Models\Order;
use App\Models\Scope;
use App\Models\Title;
use App\Models\Author;
use App\Models\Tagihan;
use App\Models\TagihanLog;
use App\Models\OrderDetail;
use Illuminate\Support\Str;
use App\Models\OrderContact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class OrderJournalController extends Controller
{
    // Catatan: index() dihapus — tak ada route yang menunjuk ke sana dan view-nya
    // ('pages.order.journals.index') sudah tidak ada. Daftar order untuk semua
    // jenis order dilayani OrderBookController@index.

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request)
    {
        //
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
                    'type'          => in_array($t->type, ['at_mandiri', 'at_kolab'], true) ? $t->type : null,
                ];
            }
        }

        $titles = Title::where('status', 'disetujui')->active()->where('jenis', 'artikel')
            ->when(! Auth::user()->hasAnyRole(['manager', 'superadmin']), function ($q) {
                $q->where(function ($qq) {
                    $qq->whereNull('assigned_to')->orWhere('assigned_to', Auth::id());
                });
            })
            ->with('scope')->orderBy('title')->get();

        return view('orders.journal.create', \compact('scopes', 'prefill', 'fromTagihan', 'titles'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
        $validate = $request->validate([
            'type'               => 'required|in:at_mandiri,at_kolab',
            'title_id'           => 'required|string|max:300',
            'scope_id'           => 'nullable',

            'indexation'         => 'required|string',
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
                    'user_id' => Auth::user()->id,
                    'status' => 'pending',
                    'note' => $validate['note'] ?? null,
                    'ordered_at' => $validate['issued_at'],
                ]);

                // Resolusi scope dulu.
                $scope_id = $validate['scope_id'] ?? null;
                if (!is_numeric($scope_id) && !empty($scope_id)) {
                    $scope_id = Scope::firstOrCreate(['scope' => $scope_id])->id;
                }

                // Resolusi judul (jenis artikel).
                $title = app(\App\Services\TitleService::class)->resolveForOrder($validate['title_id'], [
                    'jenis'      => 'artikel',
                    'order_type' => $validate['type'],
                    'scope_id'   => $scope_id ?: null,
                    'indeksasi'  => $validate['indexation'] ?? null,
                ], Auth::user());

                // ORDER DETAIL
                $detail = OrderDetail::create([
                    'order_id' => $order->id,
                    'type' => $validate['type'],
                    'title_id' => $title->id,
                    'title' => $title->title,
                    'slug' => Str::slug($title->title) . '-' . $order->id,
                    'indexation' => $validate['indexation'],
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

                // Auto-create TitleProgress agar naskah artikel masuk Manuscript Tracker
                // (warisi status grup jika judul sudah ada).
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
     * Detail order jurnal.
     *
     * Halaman detail order bersifat generik (orders/book/show) — sudah menangani
     * tipe at_mandiri/at_kolab dan dipakai daftar order untuk semua jenis order.
     * View lama 'pages.order.journals.show' ikut terhapus saat views dirapikan,
     * jadi route ini diarahkan ke sana alih-alih 500.
     */
    public function show(string $code_order)
    {
        return redirect()->route('order.book.show', ['code_order' => $code_order]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $code_order)
    {
        $order = Order::withTrashed()->with(['details' => fn ($q) => $q->withTrashed(), 'details.authors', 'details.scopes', 'contact'])
            ->where('code_order', $code_order)->firstOrFail();
        abort_unless($order->isEditable(), 403);
        $scopes = Scope::all();
        $titles = Title::where('status', 'disetujui')->active()->where('jenis', 'artikel')
            ->when(! Auth::user()->hasAnyRole(['manager', 'superadmin']), function ($q) {
                $q->where(function ($qq) {
                    $qq->whereNull('assigned_to')->orWhere('assigned_to', Auth::id());
                });
            })
            ->with('scope')->orderBy('title')->get();

        return view('orders.journal.edit', compact('order', 'scopes', 'titles'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $code_order)
    {
        $request->validate([
            'type'                  => 'required|in:at_mandiri,at_kolab',
            'title_id'              => 'required|string|max:300',
            'scope_id'              => 'nullable',
            'indexation'            => 'required|string',
            'naskah_type'           => 'required|in:dibuatkan,mandiri',
            'publication_type'      => 'required|in:regular,fastrack',
            'issued_at'             => 'required|date',
            'cost_amount'           => 'required|numeric|min:0',
            'contact_phone'         => 'required|string',
            'contact_email'         => 'required|email',
            'authors'               => 'required|array|min:1',
            'authors.*.name'        => 'required|string',
            'authors.*.email'       => 'nullable|email',
            'authors.*.phone'       => 'nullable|string',
            'authors.*.affiliation' => 'nullable|string',
            'authors.*.position'    => 'required|integer|min:1',
            'note'                  => 'nullable|string',
        ]);

        $titleName = app(\App\Services\TitleService::class)->titleNameFrom($request->title_id);
        if ($titleName === '' || mb_strlen($titleName) > 255) {
            return back()->withInput()
                ->withErrors(['title_id' => 'Judul wajib diisi dan maksimal 255 karakter.']);
        }

        abort_unless(
            Order::withTrashed()->where('code_order', $code_order)->firstOrFail()->isEditable(),
            403
        );

        try {
            DB::transaction(function () use ($request, $code_order) {
                $order = Order::where('code_order', $code_order)->firstOrFail();
                $order->update([
                    'note'       => $request->note,
                    'ordered_at' => $request->issued_at,
                ]);

                $detail = $order->details;

                $scopeId = null;
                if ($request->filled('scope_id')) {
                    $scopeId = $request->scope_id;
                    if (! is_numeric($scopeId)) {
                        $scopeId = Scope::firstOrCreate(['scope' => $scopeId])->id;
                    }
                }

                $title = app(\App\Services\TitleService::class)->resolveForOrder($request->title_id, [
                    'jenis'      => 'artikel',
                    'order_type' => $request->type,
                    'scope_id'   => $scopeId,
                    'indeksasi'  => $request->indexation,
                ], Auth::user());

                $order->details()->update([
                    'title_id'         => $title->id,
                    'title'            => $title->title,
                    'type'             => $request->type,
                    'indexation'       => $request->indexation,
                    'naskah_type'      => $request->naskah_type,
                    'publication_type' => $request->publication_type,
                    'cost_amount'      => $request->cost_amount,
                ]);

                if ($scopeId) {
                    $detail->scopes()->sync([$scopeId]);
                }

                $order->contact()->update([
                    'cp_phone' => $request->contact_phone,
                    'cp_email' => $request->contact_email,
                ]);

                $detail->authors()->detach();
                foreach ($request->authors as $authorData) {
                    $author = Author::updateOrCreate(
                        ['email' => $authorData['email']],
                        [
                            'name'        => $authorData['name'],
                            'affiliation' => $authorData['affiliation'] ?? null,
                            'phone'       => $authorData['phone'] ?? null,
                        ]
                    );
                    $detail->authors()->attach($author->id, ['position' => $authorData['position'] ?? 1]);
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
