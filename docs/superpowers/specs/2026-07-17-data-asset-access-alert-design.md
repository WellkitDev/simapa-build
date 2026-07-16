# Spec — Alert Akses Gudang Data (ganti halaman 403)

- **Tanggal:** 2026-07-17
- **Branch:** `data-access-alert`
- **Scope:** Di **Gudang Data saja**, gagal akses tidak lagi menampilkan halaman error 403/404 mentah, melainkan **redirect ke daftar Gudang + popup alert** SweetAlert2 yang sudah ada di aplikasi.
- **Di luar scope:** 47 titik `abort_unless(...403)` di controller lain (tetap 403); endpoint JSON papan manuskrip (`chapter.advance`/`chapter.assign`) memang butuh 403 asli; aturan akses itu sendiri tidak diubah.
- **Keputusan user:** cakupan **Gudang Data saja** · pesan **dibedakan** per sebab.

## Masalah

`DataAssetController` memakai `findOrFail()` + `abort_unless(..., 403)`. User yang menabrak batas akses mendapat halaman error mentah tanpa jalan kembali. Pemicu paling nyata bukan iseng menebak URL, tapi **halaman basi**: pemilik menghapus data atau mencabut role berbagi sementara daftar Gudang di layar user lain masih terbuka; user klik "Unduh" → 403/404 telanjang.

Aplikasi ini **sudah punya** pola alert: `session('error')` dirender jadi popup SweetAlert2 "Gagal" di `layouts/master.blade.php:121` (`window.swalError`), dan 8+ controller lain sudah memakai `back()->with('error', ...)` (mis. `CashAccountController:48`, `ManagementUserController:229`). Gudang Data adalah yang menyimpang, bukan sebaliknya.

## Pendekatan: exception khusus dengan `render()`

Dipilih dari 3 opsi:
- **(A) Exception + `render()` — DIPILIH.** Controller tetap ramping (`$asset = $this->findOwned($id);` satu baris seperti sekarang); perubahan terkurung di Gudang Data; `render()` bisa sekalian melayani JSON bila nanti ada AJAX.
- (B) Early-return `DataAsset|RedirectResponse` — menambah cek `instanceof` berulang di 5 method + tipe union berisik.
- (C) Handler global 403 di `bootstrap/app.php` — paling sedikit kode tapi mengubah **seluruh** aplikasi; bertabrakan dengan cakupan dan merusak endpoint JSON.

## 1. Komponen baru — `app/Exceptions/DataAssetAccessException.php`

```php
namespace App\Exceptions;

use Exception;
use Illuminate\Http\Request;

class DataAssetAccessException extends Exception
{
    public static function notFound(): self
    {
        return new self('Data tidak ditemukan atau sudah dihapus.');
    }

    public static function cannotView(): self
    {
        return new self('Kamu tidak punya akses ke data ini.');
    }

    public static function notOwner(): self
    {
        return new self('Hanya pemilik yang bisa mengubah atau menghapus data ini.');
    }

    public function render(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 403);
        }

        return redirect()->route('data.index')->with('error', $this->getMessage());
    }
}
```

Laravel memanggil `render()` pada exception secara otomatis — tak perlu registrasi di `bootstrap/app.php`.

## 2. Diubah — `App\Http\Controllers\Pages\DataAssetController`

Dua helper privat menggantikan `findOrFail()` + `abort_unless()`:

```php
private function findViewable(int $id): DataAsset
{
    $asset = DataAsset::find($id);
    if (! $asset) { throw DataAssetAccessException::notFound(); }
    if (! $asset->canView(Auth::user())) { throw DataAssetAccessException::cannotView(); }

    return $asset;
}

private function findOwned(int $id): DataAsset
{
    $asset = DataAsset::find($id);
    if (! $asset) { throw DataAssetAccessException::notFound(); }
    if (! $asset->isOwner(Auth::user())) { throw DataAssetAccessException::notOwner(); }

    return $asset;
}
```

Pemakaian per method:

| Method | Lama | Baru |
|---|---|---|
| `edit` | `findOrFail` + `abort_unless(isOwner, 403)` | `$asset = $this->findOwned($id);` |
| `update` | `findOrFail` + `abort_unless(isOwner, 403)` | `$asset = $this->findOwned($id);` |
| `destroy` | `findOrFail` + `abort_unless(isOwner, 403)` | `$asset = $this->findOwned($id);` |
| `download` | `findOrFail` + `abort_unless(canView, 403)` | `$asset = $this->findViewable($id);` |
| `index` | filter `canView` | **tak diubah** |

`download` tetap mempertahankan `abort_if($asset->type !== 'file' || ! $asset->file_path, 404)` — itu bukan soal akses melainkan data cacat (link tak punya file), dan tak dapat dicapai lewat UI.

**Redirect ke `data.index`, bukan `back()`** — disengaja: pada tembakan URL langsung tak ada referer, `back()` bisa memantul ke URL yang sama dan berulang.

## 3. Yang TIDAK berubah

Aturan akses tetap sama persis — aksi tetap **diblokir total**, hanya penyajiannya yang berubah dari halaman error jadi alert. `DataAsset::canView`/`isOwner`, view, rute, dan skema tak disentuh. Tanpa migrasi, tanpa dependency baru, tanpa view baru (SweetAlert2 global sudah menangani `session('error')`).

## 4. Testing — `tests/Feature/DataAssetTest.php`

4 assertion existing berubah dari 403 jadi redirect+alert:

| Baris | Lama | Baru |
|---|---|---|
| 81 | `get(data.download, priv)` → `assertForbidden()` | `assertRedirect(route('data.index'))` + `assertSessionHas('error')` |
| 94 | `get(data.edit)` → `assertForbidden()` | idem |
| 95 | `put(data.update)` → `assertForbidden()` | idem |
| 96 | `delete(data.destroy)` → `assertForbidden()` | idem |

Test baru:
- `download_of_deleted_asset_shows_alert`: buat aset file, hapus recordnya, lalu GET `data.download` → `assertRedirect(route('data.index'))` + `assertSessionHas('error')` (bukan 404).
- `alert_message_distinguishes_reason`: non-pemilik GET `data.edit` aset **shared** (jadi ia boleh lihat tapi bukan pemilik) → pesan "Hanya pemilik…"; sedangkan aset **private** milik orang lain via `data.download` → pesan "Kamu tidak punya akses…".

Regresi: suite penuh (506) tetap hijau; `php artisan view:cache` bersih.

## 5. Risiko

- **Akses tetap tertutup** — yang berubah hanya presentasi. Uji tetap membuktikan non-pemilik gagal mengubah/menghapus.
- Pesan "Kamu tidak punya akses ke data ini" mengakui data itu ada (keputusan user: dibedakan). Dapat diterima — ini aplikasi staf internal, bukan publik.

## 6. Komponen

- **Baru:** `app/Exceptions/DataAssetAccessException.php`.
- **Diubah:** `app/Http/Controllers/Pages/DataAssetController.php` (2 helper + 4 method); `tests/Feature/DataAssetTest.php` (4 assertion + 2 test baru).
