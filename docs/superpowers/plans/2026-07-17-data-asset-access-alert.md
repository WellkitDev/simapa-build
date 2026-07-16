# Alert Akses Gudang Data — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Di Gudang Data, gagal akses menampilkan popup alert + kembali ke daftar, bukan halaman 403/404 mentah.

**Architecture:** Exception khusus `DataAssetAccessException` dengan method `render()` — Laravel memanggilnya otomatis, jadi controller tetap ramping (satu baris per guard) dan perubahan terkurung di Gudang Data. `render()` mengembalikan `redirect()->route('data.index')->with('error', $pesan)`; `session('error')` sudah dirender jadi popup SweetAlert2 oleh `layouts/master.blade.php:121`. Aturan akses TIDAK berubah — hanya penyajiannya.

**Tech Stack:** Laravel 11, PHPUnit, SweetAlert2 (sudah terpasang global). Tanpa migrasi, tanpa dependency baru, tanpa view baru.

**Spec:** `docs/superpowers/specs/2026-07-17-data-asset-access-alert-design.md`

---

## Konvensi

- Bahasa pesan: Indonesia, sapaan "Kamu" (ikut `ManagementUserController:229` "Kamu tidak bisa menghapus akun sendiri!").
- Commit: author `WellkitDev`, trailer `Co-authored-by: Mira <admin@avidpedia.com>`. **JANGAN** `git add -A` — selalu path eksplisit (repo ini punya file lokal yang tak boleh ter-commit: `avidpedi_simapa.sql`, `template-web/`, `.env.testing`, seeder produksi).
- Test dijalankan lewat `.env.testing` → DB `avidpedi_simapa_test`, **bukan** DB dev.

## File Structure

| File | Tanggung jawab |
|---|---|
| `app/Exceptions/DataAssetAccessException.php` (**baru**) | Satu-satunya tempat pesan akses Gudang Data + cara menyajikannya (redirect+flash / JSON). |
| `app/Http/Controllers/Pages/DataAssetController.php` (**diubah**) | Guard jadi 2 helper privat; method publik tetap ramping. |
| `tests/Feature/DataAssetTest.php` (**diubah**) | 4 assertion 403 → redirect+alert; +2 test baru. |

---

## Task 1: Exception + helper controller (TDD)

**Files:**
- Create: `app/Exceptions/DataAssetAccessException.php`
- Modify: `app/Http/Controllers/Pages/DataAssetController.php` (method `edit`/`update`/`download`/`destroy` + 2 helper baru)
- Test: `tests/Feature/DataAssetTest.php` (baris 81, 94-96 + 2 test baru)

- [x] **Step 1: Ubah 4 assertion existing jadi redirect+alert (fase merah)**

Di `tests/Feature/DataAssetTest.php`, ganti baris 81 (di dalam `private_hidden_shared_visible`):

```php
        $this->actingAs($b)->get(route('data.download', $priv->id))
            ->assertRedirect(route('data.index'))
            ->assertSessionHas('error', 'Kamu tidak punya akses ke data ini.');
```

Ganti baris 94-96 (di dalam `only_owner_edits_and_deletes`):

```php
        $pesanPemilik = 'Hanya pemilik yang bisa mengubah atau menghapus data ini.';
        $this->actingAs($b)->get(route('data.edit', $asset->id))
            ->assertRedirect(route('data.index'))->assertSessionHas('error', $pesanPemilik);
        $this->actingAs($b)->put(route('data.update', $asset->id), ['name' => 'Ubah', 'type' => 'link', 'url' => 'https://x', 'visibility' => 'private'])
            ->assertRedirect(route('data.index'))->assertSessionHas('error', $pesanPemilik);
        $this->actingAs($b)->delete(route('data.destroy', $asset->id))
            ->assertRedirect(route('data.index'))->assertSessionHas('error', $pesanPemilik);
```

> Perhatikan: assertion setelahnya (`$this->assertNull(DataAsset::find($asset->id))` + `Storage::assertMissing($path)`) **tetap** — itu yang membuktikan akses sungguh diblokir, bukan cuma dialihkan. Jangan hapus.

- [x] **Step 2: Tambah 2 test baru (masih fase merah)**

Tambahkan di akhir class `DataAssetTest`, sebelum `}` penutup:

```php
    /** @test */
    public function download_of_deleted_asset_shows_alert(): void
    {
        Storage::fake();
        $a = $this->user('marketing');
        $file = UploadedFile::fake()->create('hapus.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->actingAs($a)->post(route('data.store'), ['name' => 'Akan Dihapus', 'type' => 'file', 'file' => $file, 'visibility' => 'private'])->assertRedirect();
        $asset = DataAsset::where('name', 'Akan Dihapus')->first();
        $id = $asset->id;
        $asset->delete();

        $this->actingAs($a)->get(route('data.download', $id))
            ->assertRedirect(route('data.index'))
            ->assertSessionHas('error', 'Data tidak ditemukan atau sudah dihapus.');
    }

    /** @test */
    public function alert_message_distinguishes_reason(): void
    {
        $a = $this->user('marketing');
        $b = $this->user('manager');
        $shared = DataAsset::create(['name' => 'Dibagikan', 'type' => 'link', 'url' => 'https://x', 'owner_id' => $a->id, 'visibility' => 'shared', 'shared_roles' => []]);
        $private = DataAsset::create(['name' => 'Pribadi', 'type' => 'link', 'url' => 'https://x', 'owner_id' => $a->id, 'visibility' => 'private']);

        // Boleh lihat (shared) tapi bukan pemilik → pesan kepemilikan.
        $this->actingAs($b)->get(route('data.edit', $shared->id))
            ->assertSessionHas('error', 'Hanya pemilik yang bisa mengubah atau menghapus data ini.');

        // Tak boleh lihat sama sekali → pesan akses.
        $this->actingAs($b)->get(route('data.download', $private->id))
            ->assertSessionHas('error', 'Kamu tidak punya akses ke data ini.');
    }
```

- [x] **Step 3: Jalankan test — pastikan GAGAL**

Run: `php artisan test --filter=DataAssetTest`
Expected: **FAIL**. `private_hidden_shared_visible` & `only_owner_edits_and_deletes` gagal karena respons masih 403 (`Response status code [403] is not a redirect status code`); `download_of_deleted_asset_shows_alert` gagal karena `findOrFail` melempar 404; `alert_message_distinguishes_reason` gagal karena session tak punya `error`.

- [x] **Step 4: Buat exception**

Buat `app/Exceptions/DataAssetAccessException.php`:

```php
<?php

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

- [x] **Step 5: Pasang helper di controller**

Di `app/Http/Controllers/Pages/DataAssetController.php`, tambah import di bawah `use App\Models\DataAsset;`:

```php
use App\Exceptions\DataAssetAccessException;
```

Tambahkan 2 helper privat tepat sebelum `private function validated(`:

```php
    private function findViewable(int $id): DataAsset
    {
        $asset = DataAsset::find($id);
        if (! $asset) {
            throw DataAssetAccessException::notFound();
        }
        if (! $asset->canView(Auth::user())) {
            throw DataAssetAccessException::cannotView();
        }

        return $asset;
    }

    private function findOwned(int $id): DataAsset
    {
        $asset = DataAsset::find($id);
        if (! $asset) {
            throw DataAssetAccessException::notFound();
        }
        if (! $asset->isOwner(Auth::user())) {
            throw DataAssetAccessException::notOwner();
        }

        return $asset;
    }
```

- [x] **Step 6: Pakai helper di 4 method**

`edit` — ganti 2 baris pertama:

```php
    public function edit(int $id)
    {
        $asset = $this->findOwned($id);

        return view('data-assets.edit', compact('asset'));
    }
```

`update` — ganti 2 baris pertama (sisanya tetap):

```php
    public function update(Request $request, int $id)
    {
        $asset = $this->findOwned($id);

        $data = $this->validated($request, false);
        $payload = $this->payload($request, $data, $asset);
        $asset->update($payload);

        return redirect()->route('data.index')->with('success', 'Data diperbarui.');
    }
```

`download` — ganti 2 baris pertama; **`abort_if` 404 tetap** (itu data cacat, bukan soal akses):

```php
    public function download(int $id)
    {
        $asset = $this->findViewable($id);
        abort_if($asset->type !== 'file' || ! $asset->file_path, 404);

        return Storage::download($asset->file_path, $asset->file_name);
    }
```

`destroy` — ganti 2 baris pertama (sisanya tetap):

```php
    public function destroy(int $id)
    {
        $asset = $this->findOwned($id);

        if ($asset->type === 'file' && $asset->file_path) {
            Storage::delete($asset->file_path);
        }
        $asset->delete();

        return redirect()->route('data.index')->with('success', 'Data dihapus.');
    }
```

- [x] **Step 7: Jalankan test — pastikan LULUS**

Run: `php artisan test --filter=DataAssetTest`
Expected: **PASS**, 7 test (5 lama + 2 baru).

- [x] **Step 8: Commit**

```bash
git add app/Exceptions/DataAssetAccessException.php app/Http/Controllers/Pages/DataAssetController.php tests/Feature/DataAssetTest.php
git commit -F <path-pesan>
```

Pesan commit (tulis ke file lalu `-F`, jangan here-string PowerShell di dalam tool Bash):

```
feat(data): alert akses Gudang Data (ganti halaman 403)

Gagal akses kini redirect ke daftar Gudang + popup SweetAlert2 lewat
session(error), bukan halaman 403/404 mentah. Pemicu utama: halaman
basi saat pemilik menghapus data atau mencabut role berbagi.

DataAssetAccessException.render() menyajikan pesan (JSON 403 bila
expectsJson). Pesan dibedakan: tak ditemukan / tak boleh lihat / bukan
pemilik. Aturan akses tidak berubah - aksi tetap diblokir.

Co-authored-by: Mira <admin@avidpedia.com>
```

---

## Task 2: Regresi + verifikasi di aplikasi sungguhan

**Files:** tak ada perubahan kode; hanya menjalankan & mengamati.

- [x] **Step 1: Suite penuh**

Run: `php artisan test`
Expected: PASS semua (**508** = 506 sebelumnya + 2 test baru).

- [x] **Step 2: Blade tetap sehat**

Run: `php artisan view:cache && php artisan view:clear`
Expected: "Blade templates cached successfully." tanpa error.

- [x] **Step 3: Verifikasi alert sungguhan lewat HTTP**

Alert adalah perilaku **browser** — test hanya membuktikan `session('error')` terisi, bukan bahwa popupnya muncul. Buktikan HTML-nya benar-benar memanggil `swalError`:

```bash
php artisan serve --port=8123   # jalankan di background
```

Buat 2 user sementara (owner marketing + penyusup manager), login via curl (ambil `_token` dari `/login`), buat 1 aset private milik owner, lalu sebagai penyusup:

```bash
curl -s -b penyusup.jar -o /dev/null -w "%{http_code} -> %{redirect_url}\n" \
  http://127.0.0.1:8123/gudang/1/download     # harap 302 -> /gudang (bukan 403)
curl -s -b penyusup.jar http://127.0.0.1:8123/gudang | grep -o "swalError(.*)"
```

Expected: redirect 302 ke `/gudang`, dan halaman berikutnya memuat `window.swalError("Kamu tidak punya akses ke data ini.")`.

- [x] **Step 4: Bersihkan**

Hapus 2 user sementara (`forceDelete`) + aset ujinya, matikan server, pastikan `DataAsset::count()` dan `storage/app/data-assets/` kembali seperti semula, dan `git status` bersih dari sampah uji.

- [x] **Step 5: Centang plan ini + commit**

```bash
git add docs/superpowers/plans/2026-07-17-data-asset-access-alert.md
git commit -F <path-pesan>   # docs(plan): tandai alert akses Gudang Data selesai
```

---

## Self-Review

- **Cakupan spec:** exception+3 pesan (T1 S4) · 2 helper (T1 S5) · 4 method (T1 S6) · `index` tak diubah ✓ · `abort_if` 404 download dipertahankan ✓ (T1 S6) · redirect ke `data.index` bukan `back()` ✓ · 4 assertion diubah (T1 S1) · 2 test baru (T1 S2) · regresi+view:cache (T2 S1-2). Semua tersentuh.
- **Placeholder:** tak ada — tiap step berisi kode/perintah utuh.
- **Konsistensi tipe:** `notFound()`/`cannotView()`/`notOwner()` static factory dipakai persis dengan nama sama di helper T1 S5; `findViewable`/`findOwned` dipakai persis di T1 S6; string pesan identik antara exception (S4) dan assertion test (S1-S2).
- **Catatan:** T1 menggabung 4 assertion + 2 test baru dalam satu fase merah karena keduanya menguji satu perubahan perilaku yang sama; memecahnya membuat test baru lulus tanpa pernah merah.
