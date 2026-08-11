<?php

namespace App\Support;

use App\Models\ServiceClient;
use App\Models\ServiceInvoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Aturan validasi + perakitan data form invoice layanan. Dipisah dari controller
 * supaya store() dan update() memakai definisi yang sama persis, dan controller-nya
 * tetap bisa dibaca sekali lihat.
 */
class ServiceInvoiceForm
{
    public static function rules(): array
    {
        return [
            'service_client_id'  => 'nullable|exists:tb_service_clients,id',
            'client_name'        => 'required|string|max:190',
            'client_institution' => 'nullable|string|max:190',
            'client_email'       => 'nullable|email|max:190',
            'client_phone'       => 'nullable|string|max:40',
            'client_address'     => 'nullable|string',
            'issued_at'          => 'required|date',
            'due_at'             => 'nullable|date|after_or_equal:issued_at',
            'discount'           => 'nullable|numeric|min:0|max:9999999999999.99',
            'note'               => 'nullable|string',
            'internal_note'      => 'nullable|string',

            'items'                      => 'required|array|min:1',
            'items.*.service_catalog_id' => 'nullable|exists:tb_service_catalogs,id',
            'items.*.name'               => 'required|string|max:190',
            'items.*.description'        => 'nullable|string',
            'items.*.qty'                => 'required|numeric|min:0.01|max:999999',
            'items.*.unit_price'         => 'required|numeric|min:0|max:9999999999999.99',
        ];
    }

    /**
     * Buang pemisah ribuan dari kolom UANG saja, sebelum validasi.
     *
     * `qty` SENGAJA tidak ikut: qty boleh pecahan ("1.5"), dan membuang titiknya
     * akan diam-diam mengubahnya jadi 15.
     *
     * Tanda minus dipertahankan supaya nominal negatif tetap DITOLAK aturan min:0,
     * bukan diam-diam dibalik jadi positif.
     */
    public static function normalize(Request $request): void
    {
        if ($request->filled('discount')) {
            $request->merge(['discount' => self::digits($request->input('discount'))]);
        }

        $items = $request->input('items', []);
        if (! is_array($items)) {
            return;
        }

        foreach ($items as $i => $row) {
            if (isset($row['unit_price'])) {
                $items[$i]['unit_price'] = self::digits($row['unit_price']);
            }
        }
        $request->merge(['items' => $items]);
    }

    private static function digits($value): string
    {
        return preg_replace('/[.,\s]/', '', (string) $value);
    }

    /** Diskon tidak boleh melebihi subtotal item yang dikirim. */
    public static function assertDiscount(array $data): void
    {
        $subtotal = 0.0;
        foreach ($data['items'] as $row) {
            $subtotal += round((float) $row['qty'] * (float) $row['unit_price'], 2);
        }

        if ((float) ($data['discount'] ?? 0) > $subtotal) {
            throw ValidationException::withMessages([
                'discount' => 'Diskon tidak boleh melebihi subtotal layanan (Rp '
                    . number_format($subtotal, 0, ',', '.') . ').',
            ]);
        }
    }

    /**
     * Klien dari master bila dipilih; kalau diketik manual, baris master baru dibuat
     * lalu dipakai. Tidak ada invoice tanpa induk klien kecuali klien itu dihapus kelak.
     */
    public static function resolveClient(array $data): ServiceClient
    {
        if (! empty($data['service_client_id'])) {
            return ServiceClient::findOrFail($data['service_client_id']);
        }

        // Email dipakai sebagai kunci alami SEBELUM membuat baris baru. Tanpa ini,
        // operator yang mengetik klien yang sama di empat invoice (alih-alih
        // memilihnya dari daftar) melahirkan empat baris master — dan pertanyaan
        // "pekerjaan apa saja untuk Universitas X", satu-satunya tugas nyata
        // service_client_id menurut spec §2.2, cuma terjawab seperempatnya.
        //
        // Sisa risikonya: klien TANPA email masih bisa terduplikasi. Diterima
        // sadar; penutupnya kelak pencocokan nama+instansi atau kolom `code`.
        $email = trim((string) ($data['client_email'] ?? ''));

        if ($email !== '') {
            $existing = ServiceClient::whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first();

            if ($existing) {
                return $existing;
            }
        }

        return ServiceClient::create([
            'name'        => $data['client_name'],
            'institution' => $data['client_institution'] ?? null,
            'email'       => $data['client_email'] ?? null,
            'phone'       => $data['client_phone'] ?? null,
            'address'     => $data['client_address'] ?? null,
            'created_by'  => Auth::id(),
            'updated_by'  => Auth::id(),
        ]);
    }

    /** Salinan data klien untuk invoice — SNAPSHOT, bukan referensi. */
    public static function snapshotFrom(ServiceClient $client, array $data): array
    {
        return [
            'service_client_id'  => $client->id,
            'client_name'        => $data['client_name'],
            'client_institution' => $data['client_institution'] ?? null,
            'client_email'       => $data['client_email'] ?? null,
            'client_phone'       => $data['client_phone'] ?? null,
            'client_address'     => $data['client_address'] ?? null,
        ];
    }

    /** Buat ulang seluruh baris item dari input tervalidasi. */
    public static function syncItems(ServiceInvoice $invoice, array $data): void
    {
        $invoice->items()->delete();

        foreach (array_values($data['items']) as $position => $row) {
            $qty   = (float) $row['qty'];
            $price = (float) $row['unit_price'];

            $invoice->items()->create([
                'service_catalog_id' => $row['service_catalog_id'] ?? null,
                'name'               => $row['name'],
                'description'        => $row['description'] ?? null,
                'qty'                => $qty,
                'unit_price'         => $price,
                'subtotal'           => round($qty * $price, 2),
                'position'           => $position,
            ]);
        }
    }
}
