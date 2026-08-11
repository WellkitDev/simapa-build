<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\ServiceCatalog;
use Illuminate\Http\Request;

class ServiceCatalogController extends Controller
{
    public function index()
    {
        $catalogs = ServiceCatalog::orderBy('category')->orderBy('position')->orderBy('name')->get();

        return view('services.catalogs.index', [
            'catalogs'   => $catalogs->groupBy('category'),
            'categories' => ServiceCatalog::CATEGORIES,
            'units'      => ServiceCatalog::UNITS,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        ServiceCatalog::create($data);

        return redirect()->route('service.catalog.index')->with('success', 'Layanan ditambahkan ke katalog.');
    }

    public function update(Request $request, int $id)
    {
        $catalog = ServiceCatalog::findOrFail($id);
        $catalog->update($this->validated($request));

        return redirect()->route('service.catalog.index')->with('success', 'Layanan diperbarui.');
    }

    public function destroy(int $id)
    {
        ServiceCatalog::findOrFail($id)->delete();

        // 'info', bukan 'warning': layouts/master hanya merender success/error/info,
        // jadi pesan ber-key 'warning' tak pernah sampai ke layar sama sekali.
        return redirect()->route('service.catalog.index')
            ->with('info', 'Layanan dihapus dari katalog. Invoice lama tidak berubah.');
    }

    /**
     * Buang pemisah ribuan sebelum validasi, lalu validasi.
     *
     * Harga di modul ini RUPIAH BULAT. Pembersih di bawah membuang titik, koma,
     * dan spasi tanpa bisa membedakan pemisah ribuan dari titik desimal — jadi
     * "1.500,50" akan jadi 150050. Itu diterima sadar: tarif jasa tak pernah bersen.
     * Konsekuensinya form HARUS menerima angka bulat saja; lihat catatan di view
     * soal `(int)` pada data-catalog, yang mencegah "350000.00" kembali ke sini.
     */
    private function validated(Request $request): array
    {
        foreach (['price', 'price_max'] as $field) {
            if ($request->filled($field)) {
                // Pertahankan tanda minus agar nominal negatif tetap DITOLAK min:0.
                $request->merge([$field => preg_replace('/[.,\s]/', '', (string) $request->input($field))]);
            }
        }

        // `is_active` SENGAJA di luar daftar aturan. Kalau ia divalidasi sebagai
        // `nullable|boolean`, nilai kosong lolos sebagai null lalu menabrak kolom
        // NOT NULL dan berakhir 500. Di luar daftar, union `+` di bawah selalu
        // yang mengisinya, dan checkbox yang tak dicentang jadi false.
        return $request->validate([
            'category'    => 'required|in:' . implode(',', array_keys(ServiceCatalog::CATEGORIES)),
            'name'        => 'required|string|max:190',
            'price'       => 'required|numeric|min:0|max:9999999999999.99',
            'price_max'   => 'nullable|numeric|min:0|max:9999999999999.99|gte:price',
            'unit'        => 'nullable|in:' . implode(',', array_keys(ServiceCatalog::UNITS)),
            'description' => 'nullable|string',
            'position'    => 'nullable|integer|min:0',
        ]) + ['is_active' => $request->boolean('is_active')];
    }
}
