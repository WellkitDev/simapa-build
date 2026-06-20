<?php

namespace App\Http\Controllers\Pages;

use App\Models\Invoice;
use App\Models\InvoiceLog;
use App\Models\Order;
use App\Models\Payment;
use App\Support\InvoicePdfData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    public function index()
    {
        $invoices = Invoice::with(['order.details', 'payment'])
            ->when(Auth::user()->hasRole('marketing'), fn($q) =>
                $q->whereHas('order', fn($o) => $o->where('user_id', Auth::id()))
            )
            ->latest()
            ->get();

        return view('payments.invoices.index', compact('invoices'));
    }

    public function show(int $id)
    {
        $invoice = Invoice::with([
            'order.details', 'payment', 'logs.changedBy', 'cancelledBy', 'refundedBy',
        ])->findOrFail($id);

        return view('payments.invoices.show', compact('invoice'));
    }

    public function edit(int $id)
    {
        if (!Auth::user()->hasAnyRole(['manager', 'superadmin'])) {
            abort(403);
        }

        $invoice  = Invoice::with('order')->findOrFail($id);
        $orders   = Order::with('details')->latest()->get();
        $payments = Payment::where('status', 'paid')->get();
        return view('payments.invoices.edit', compact('invoice', 'orders', 'payments'));
    }

    public function update(Request $request, int $id)
    {
        if (!Auth::user()->hasAnyRole(['manager', 'superadmin'])) {
            abort(403);
        }

        $invoice = Invoice::findOrFail($id);

        $data = $request->validate([
            'invoice_no' => 'required|string|max:100|unique:tb_invoices,invoice_no,' . $id,
            'issued_at'  => 'required|date',
            'due_at'     => 'required|date|after_or_equal:issued_at',
            'note'       => 'nullable|string',
            'payment_id' => 'nullable|exists:tb_payments,id',
        ]);

        $invoice->update($data);

        return redirect()->route('invoice.show', $invoice->id)
            ->with('success', 'Invoice berhasil diperbarui.');
    }

    public function updateStatus(Request $request, int $id)
    {
        if (!Auth::user()->hasAnyRole(['manager', 'superadmin'])) {
            abort(403);
        }

        $invoice = Invoice::findOrFail($id);

        $data = $request->validate([
            'status' => 'required|in:draft,diterbitkan,jatuh_tempo,lunas,dibatalkan,refund',
            'note'   => 'nullable|string',
        ]);

        $fromStatus = $invoice->status;

        DB::transaction(function () use ($invoice, $data, $fromStatus) {
            $invoice->update(['status' => $data['status']]);

            InvoiceLog::create([
                'invoice_id'  => $invoice->id,
                'from_status' => $fromStatus,
                'to_status'   => $data['status'],
                'changed_by'  => Auth::id(),
                'note'        => $data['note'] ?? null,
            ]);
        });

        return back()->with('success', 'Status invoice diperbarui.');
    }

    public function cancel(Request $request, int $id)
    {
        if (!Auth::user()->hasRole('superadmin')) {
            abort(403);
        }

        $request->validate(['note' => 'required|string']);

        $invoice    = Invoice::findOrFail($id);
        $fromStatus = $invoice->status;

        DB::transaction(function () use ($invoice, $fromStatus, $request) {
            $invoice->update([
                'status'       => 'dibatalkan',
                'cancelled_by' => Auth::id(),
                'cancelled_at' => now(),
            ]);

            InvoiceLog::create([
                'invoice_id'  => $invoice->id,
                'from_status' => $fromStatus,
                'to_status'   => 'dibatalkan',
                'changed_by'  => Auth::id(),
                'note'        => $request->note,
            ]);
        });

        return back()->with('warning', 'Invoice dibatalkan.');
    }

    public function refund(Request $request, int $id)
    {
        if (!Auth::user()->hasRole('superadmin')) {
            abort(403);
        }

        $request->validate(['note' => 'required|string']);

        $invoice = Invoice::findOrFail($id);

        if ($invoice->status !== 'lunas') {
            return back()->withErrors(['note' => 'Invoice harus berstatus lunas untuk di-refund.']);
        }

        DB::transaction(function () use ($invoice, $request) {
            $invoice->update([
                'status'      => 'refund',
                'refunded_by' => Auth::id(),
                'refunded_at' => now(),
            ]);

            InvoiceLog::create([
                'invoice_id'  => $invoice->id,
                'from_status' => 'lunas',
                'to_status'   => 'refund',
                'changed_by'  => Auth::id(),
                'note'        => $request->note,
            ]);
        });

        return back()->with('success', 'Invoice berhasil di-refund.');
    }

    public function logs(int $id)
    {
        $invoice = Invoice::with('logs.changedBy')->findOrFail($id);
        return response()->json($invoice->logs);
    }

    public function pdf(int $id)
    {
        $invoice = Invoice::query()
            ->when(Auth::user()->hasRole('marketing'), fn ($q) =>
                $q->whereHas('order', fn ($o) => $o->where('user_id', Auth::id())))
            ->findOrFail($id);

        $data = InvoicePdfData::for($invoice);
        abort_if(!$data['detail'], 404, 'Detail order tidak ditemukan.');

        return Pdf::loadView('payments.invoices.book_invoice_pdf', $data)
            ->stream('Invoice_' . $invoice->invoice_no . '.pdf');
    }
}
