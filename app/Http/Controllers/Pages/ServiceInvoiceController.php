<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\ServiceCatalog;
use App\Models\ServiceClient;
use App\Models\ServiceInvoice;
use App\Support\ServiceInvoiceForm;
use App\Support\ServiceInvoiceNumber;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ServiceInvoiceController extends Controller
{
    public function index(Request $request)
    {
        // Tanpa ini, filter yang salah ketik (mis. to=bukan-tanggal) diam-diam
        // menghasilkan nol baris lewat where() yang tak pernah cocok — operator
        // mengira invoice-nya hilang, bukan mengira filternya salah format.
        $request->validate([
            'work_status'    => 'nullable|in:belum,proses,selesai,batal',
            'payment_status' => 'nullable|in:belum,dp,lunas',
            'from'           => 'nullable|date',
            'to'             => 'nullable|date',
        ]);

        $invoices = ServiceInvoice::query()
            ->when($request->filled('work_status'),    fn ($q) => $q->where('work_status', $request->input('work_status')))
            ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', $request->input('payment_status')))
            // `where`, bukan `whereDate`: issued_at SUDAH bertipe DATE, jadi
            // whereDate() cuma membungkusnya dengan date() dan membuat indeksnya
            // tak terpakai tanpa memberi apa pun.
            ->when($request->filled('from'), fn ($q) => $q->where('issued_at', '>=', $request->input('from')))
            ->when($request->filled('to'),   fn ($q) => $q->where('issued_at', '<=', $request->input('to')))
            ->latest('issued_at')
            ->latest('id')
            ->get();

        return view('services.invoices.index', compact('invoices'));
    }

    public function create()
    {
        return view('services.invoices.form', [
            'invoice'  => null,
            'mode'     => 'create',
            'clients'  => ServiceClient::orderBy('name')->get(),
            'catalogs' => ServiceCatalog::active()->orderBy('category')->orderBy('position')->get(),
        ]);
    }

    public function store(Request $request)
    {
        ServiceInvoiceForm::normalize($request);
        $data = $request->validate(ServiceInvoiceForm::rules());
        ServiceInvoiceForm::assertDiscount($data);

        $invoice = ServiceInvoiceNumber::retrying(fn () => DB::transaction(function () use ($data) {
            $issuedAt = Carbon::parse($data['issued_at']);
            $client   = ServiceInvoiceForm::resolveClient($data);

            $invoice = ServiceInvoice::create(
                ServiceInvoiceForm::snapshotFrom($client, $data) + [
                    'invoice_no'     => ServiceInvoiceNumber::next($issuedAt),
                    'issued_at'      => $issuedAt->toDateString(),
                    'due_at'         => $data['due_at'] ?? null,
                    'discount'       => $data['discount'] ?? 0,
                    'note'           => $data['note'] ?? null,
                    'internal_note'  => $data['internal_note'] ?? null,
                    'work_status'    => 'belum',
                    'payment_status' => 'belum',
                    'created_by'     => Auth::id(),
                    'updated_by'     => Auth::id(),
                ]
            );

            ServiceInvoiceForm::syncItems($invoice, $data);
            $invoice->recalcTotals();

            $invoice->logs()->create([
                'event'      => 'created',
                'to_status'  => 'belum',
                'changed_by' => Auth::id(),
            ]);

            return $invoice;
        }));

        return redirect()->route('service.invoice.show', $invoice->id)
            ->with('success', 'Invoice layanan ' . $invoice->invoice_no . ' dibuat.');
    }

    public function show(int $id)
    {
        $invoice = ServiceInvoice::with(['items', 'payments', 'logs.changedBy', 'client'])->findOrFail($id);

        return view('services.invoices.show', compact('invoice'));
    }
}
