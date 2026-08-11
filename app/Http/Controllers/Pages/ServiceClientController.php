<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\ServiceClient;
use App\Models\ServiceInvoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ServiceClientController extends Controller
{
    public function index()
    {
        $clients = ServiceClient::withCount('invoices')->orderBy('name')->get();

        return view('services.clients.index', compact('clients'));
    }

    public function show(int $id)
    {
        $client = ServiceClient::with('invoices')->findOrFail($id);

        return view('services.clients.show', compact('client'));
    }

    public function store(Request $request)
    {
        $data = $this->rules($request);
        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();

        ServiceClient::create($data);

        return redirect()->route('service.client.index')->with('success', 'Klien jasa ditambahkan.');
    }

    public function update(Request $request, int $id)
    {
        $client = ServiceClient::findOrFail($id);

        $data = $this->rules($request);
        $data['updated_by'] = Auth::id();
        $client->update($data);

        return redirect()->route('service.client.index')
            ->with('success', 'Klien jasa diperbarui. Invoice yang sudah terbit tidak berubah.');
    }

    public function destroy(int $id)
    {
        $client = ServiceClient::findOrFail($id);

        DB::transaction(function () use ($client) {
            // Soft delete Eloquent tidak memicu FK nullOnDelete di database, jadi
            // tautannya dilepas manual. Snapshot di invoice sengaja TIDAK disentuh —
            // dokumen yang sudah terbit harus tetap mencetak isi yang sama.
            ServiceInvoice::where('service_client_id', $client->id)
                ->update(['service_client_id' => null]);

            $client->delete();
        });

        // 'info', bukan 'warning': layouts/master hanya merender success/error/info.
        return redirect()->route('service.client.index')
            ->with('info', 'Klien dihapus. Invoice lamanya tetap utuh.');
    }

    private function rules(Request $request): array
    {
        return $request->validate([
            'name'        => 'required|string|max:190',
            'institution' => 'nullable|string|max:190',
            'email'       => 'nullable|email|max:190',
            'phone'       => 'nullable|string|max:40',
            'address'     => 'nullable|string',
            'note'        => 'nullable|string',
        ]);
    }
}
