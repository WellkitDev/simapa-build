# Export PDF Arsip Judul (Fase 2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tombol Export PDF di detail arsip (status disetujui) menghasilkan PDF konsolidasi (kop + judul/order/manuskrip/artefak+PIC + persetujuan), stream langsung.

**Architecture:** `TitleArchiveController@pdf` memuat data seperti `show()`, `Barryvdh\DomPDF\Facade\Pdf::loadView('archive.pdf', …)->stream(...)`. `defaultArtifacts()` diperluas `pic_name`. Guard: `canManage` + status `disetujui`. Tanpa migrasi.

**Tech Stack:** Laravel 11, `barryvdh/laravel-dompdf` (terpasang, dipakai Invoice/Tagihan), Blade. Test: PHPUnit `.env.testing`.

---

## File Structure

- `app/Services/TitleArchivalService.php` (**modify**) — `defaultArtifacts()` +`pic_name`.
- `app/Http/Controllers/Pages/TitleArchiveController.php` (**modify**) — `pdf()`.
- `routes/web.php` (**modify**) — `archive.pdf`.
- `resources/views/archive/pdf.blade.php` (**create**) — dokumen PDF.
- `resources/views/archive/show.blade.php` (**modify**) — tombol Export PDF.
- `tests/Feature/ArchivePdfTest.php` (**create**).

---

## Konteks untuk implementer

- Pola PDF existing: `TagihanController@pdf` → `use Barryvdh\DomPDF\Facade\Pdf;` `Pdf::loadView('view', $data)->stream('nama.pdf')`. View self-contained `<!DOCTYPE html>` + inline `<style>` `font-family: DejaVu Sans`.
- Logo: `public/assets/images/logo-av-90.png` ada. dompdf memuat file lokal via `public_path(...)`.
- `defaultArtifacts(Title)` (di `TitleArchivalService`) kembalikan list item `['key','label','type','value','file_name','pic_user_id','note']` (perlu +`pic_name`). Row existing dari `$title->archiveArtifacts` (relasi `pic`).
- Controller `TitleArchiveController` sudah punya `canManage()` (superadmin/manager/admin/production) + `$this->service` (TitleArchivalService). `show()` eager-load list dipakai ulang.
- Fixture eligible+approved pola dari `tests/Feature/TitleArchiveTest.php` (`eligibleBook()` + `TitleArchive::create(status disetujui)`).

---

### Task 1: pic_name + controller pdf() + route + PDF view + feature test

**Files:** `TitleArchivalService.php`, `TitleArchiveController.php`, `routes/web.php`, `resources/views/archive/pdf.blade.php`, `tests/Feature/ArchivePdfTest.php`

- [ ] **Step 1: Tulis feature test (gagal dulu)** — `tests/Feature/ArchivePdfTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\TitleProgress;
use App\Models\TitleArchive;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class ArchivePdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'manager', 'superadmin', 'production', 'admin'] as $r) {
            Role::create(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    private function book(string $archiveStatus): Title
    {
        $book = Title::create(['title' => 'Buku PDF ' . uniqid(), 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $owner = $this->user('production');
        $order = Order::create(['code_order' => 'ORD-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
        OrderDetail::create(['order_id' => $order->id, 'title_id' => $book->id, 'type' => 'bk_mandiri', 'title' => $book->title, 'slug' => 'b-' . uniqid(), 'chapters' => 1, 'cost_amount' => 100000, 'naskah_type' => 'mandiri', 'publication_type' => 'regular']);
        TitleProgress::create(['order_detail_id' => $order->details->id, 'status' => 'terbit', 'assigned_role' => 'superadmin', 'started_at' => now()]);
        Payment::create(['order_id' => $order->id, 'payment_type' => 'pelunasan', 'amount' => 100000, 'status' => 'paid', 'paid_at' => now()]);
        TitleArchive::create(['title_id' => $book->id, 'status' => $archiveStatus, 'approved_at' => now()]);
        return $book->fresh();
    }

    /** @test */
    public function pdf_streams_for_approved_title(): void
    {
        $book = $this->book('disetujui');
        $this->actingAs($this->user('manager'))->get(route('archive.pdf', $book->id))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    /** @test */
    public function pdf_forbidden_when_not_approved(): void
    {
        $book = $this->book('diajukan');
        $this->actingAs($this->user('manager'))->get(route('archive.pdf', $book->id))->assertForbidden();
    }

    /** @test */
    public function pdf_forbidden_for_marketing(): void
    {
        $book = $this->book('disetujui');
        $this->actingAs($this->user('marketing'))->get(route('archive.pdf', $book->id))->assertForbidden();
    }
}
```

- [ ] **Step 2: Jalankan — GAGAL** (`Route [archive.pdf] not defined`).
Run: `php artisan test --env=testing tests/Feature/ArchivePdfTest.php`
Expected: FAIL.

- [ ] **Step 3: `defaultArtifacts()` +`pic_name`** — di `app/Services/TitleArchivalService.php`, dalam loop `defaultArtifacts`, ubah blok `$out[] = [...]` menjadi menyertakan `pic_name`:

```php
            $out[] = [
                'key'         => $key,
                'label'       => $def['label'],
                'type'        => $def['type'],
                'value'       => $row->value ?? ($prefill[$key] ?? null),
                'file_name'   => $row->file_name ?? null,
                'pic_user_id' => $row->pic_user_id ?? null,
                'pic_name'    => $row ? optional($row->pic)->name : null,
                'note'        => $row->note ?? null,
            ];
```

- [ ] **Step 4: `pdf()` di `TitleArchiveController.php`** — tambah method (setelah `show()`), + `use Barryvdh\DomPDF\Facade\Pdf;` di atas (atau FQN):

```php
    public function pdf(int $id)
    {
        abort_unless($this->canManage(), 403);
        $title = Title::with([
            'chapters', 'scope', 'bookIsbn', 'archive.approver', 'archive.submitter',
            'archiveArtifacts.pic',
            'orderDetails.order.user', 'orderDetails.order.invoices', 'orderDetails.order.payments', 'orderDetails.order.details', 'orderDetails.titleProgress',
        ])->findOrFail($id);
        abort_unless(optional($title->archive)->status === 'disetujui', 403);

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('archive.pdf', [
            'title'     => $title,
            'artifacts' => $this->service->defaultArtifacts($title),
            'custom'    => $title->archiveArtifacts->where('is_custom', true)->values(),
            'isPaidOff' => $title->isPaidOff(),
            'isFinal'   => $title->manuscriptIsFinal(),
        ])->stream('Arsip_' . ($title->code ?: $title->id) . '.pdf');
    }
```

- [ ] **Step 5: Route** — di `routes/web.php`, setelah `Route::get('management/archive/{id}', …)->name('archive.show')…`, tambah:

```php
    Route::get('management/archive/{id}/pdf', [\App\Http\Controllers\Pages\TitleArchiveController::class, 'pdf'])->name('archive.pdf')->whereNumber('id');
```

- [ ] **Step 6: View `resources/views/archive/pdf.blade.php`**

```blade
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; }
        .head { border-bottom: 2px solid #333; padding-bottom: 8px; margin-bottom: 14px; }
        .head h1 { margin: 4px 0 0; font-size: 18px; }
        .muted { color: #666; }
        h2 { font-size: 13px; margin: 16px 0 6px; border-bottom: 1px solid #ccc; padding-bottom: 3px; }
        table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        td, th { padding: 5px 7px; border: 1px solid #ccc; text-align: left; vertical-align: top; }
        th { background: #f3f3f3; }
        dt { font-weight: bold; }
    </style>
</head>
<body>
    <div class="head">
        @if(file_exists(public_path('assets/images/logo-av-90.png')))
            <img src="{{ public_path('assets/images/logo-av-90.png') }}" height="36">
        @endif
        <h1>ARSIP JUDUL SELESAI</h1>
        <div class="muted">{{ $title->code ? $title->code . ' — ' : '' }}{{ $title->title }} · Dicetak: {{ now()->format('d M Y H:i') }}</div>
    </div>

    <h2>Info Judul</h2>
    <table>
        <tr><th width="25%">Kode</th><td>{{ $title->code ?? '-' }}</td></tr>
        <tr><th>Judul</th><td>{{ $title->title }}</td></tr>
        <tr><th>Jenis / Tipe</th><td>{{ ucfirst($title->jenis) }} / {{ ucfirst($title->tipe_naskah) }}</td></tr>
        <tr><th>Bidang Ilmu</th><td>{{ $title->scope?->scope ?? '-' }}</td></tr>
    </table>

    <h2>Info Order</h2>
    <table>
        <thead><tr><th>Kode Order</th><th>Marketing</th><th>Tanggal</th><th>Biaya</th><th>Bayar</th></tr></thead>
        <tbody>
        @forelse($title->orderDetails as $od)
            <tr>
                <td>{{ $od->order?->code_order ?? '-' }}</td>
                <td>{{ $od->order?->user?->name ?? '-' }}</td>
                <td>{{ optional($od->order?->ordered_at)->format('d M Y') ?? '-' }}</td>
                <td>Rp {{ number_format((int) $od->cost_amount, 0, ',', '.') }}</td>
                <td>{{ $od->order && $od->order->isLunas() ? 'Lunas' : 'Belum' }}</td>
            </tr>
        @empty
            <tr><td colspan="5">Belum ada order.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>Info Manuskrip</h2>
    <table>
        <tr><th width="25%">Status</th><td>{{ $title->manuscriptStatusLabel() ?? '-' }}</td></tr>
        @if($title->jenis === 'buku' && $title->chapters->isNotEmpty())
            <tr><th>Bab</th><td>{{ $title->chapters->pluck('judul')->join(', ') }}</td></tr>
        @endif
    </table>

    <h2>Artefak Penyelesaian</h2>
    <table>
        <thead><tr><th width="22%">Item</th><th>Nilai</th><th width="20%">PIC</th><th width="22%">Catatan</th></tr></thead>
        <tbody>
        @foreach($artifacts as $a)
            <tr>
                <td>{{ $a['label'] }}</td>
                <td>{{ $a['value'] ?: '-' }}{{ $a['type'] === 'file' && $a['file_name'] ? ' (' . $a['file_name'] . ')' : '' }}</td>
                <td>{{ $a['pic_name'] ?? '-' }}</td>
                <td>{{ $a['note'] ?? '-' }}</td>
            </tr>
        @endforeach
        @foreach($custom as $c)
            <tr>
                <td>{{ $c->label }}</td>
                <td>{{ $c->value ?: '-' }}</td>
                <td>{{ optional($c->pic)->name ?? '-' }}</td>
                <td>{{ $c->note ?? '-' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <h2>Persetujuan</h2>
    <table>
        <tr><th width="25%">Status</th><td>Disetujui</td></tr>
        <tr><th>Disetujui oleh</th><td>{{ optional($title->archive->approver)->name ?? '-' }}</td></tr>
        <tr><th>Tanggal</th><td>{{ optional($title->archive->approved_at)->format('d M Y H:i') ?? '-' }}</td></tr>
        <tr><th>Catatan</th><td>{{ $title->archive->approval_note ?? '-' }}</td></tr>
    </table>
</body>
</html>
```

- [ ] **Step 7: Jalankan — PASS**
Run: `php artisan test --env=testing tests/Feature/ArchivePdfTest.php`
Expected: 3 passed.

- [ ] **Step 8: Commit**

```bash
git add app/Services/TitleArchivalService.php app/Http/Controllers/Pages/TitleArchiveController.php routes/web.php resources/views/archive/pdf.blade.php tests/Feature/ArchivePdfTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(archive): export PDF detail arsip (disetujui, stream) + pic_name

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 2: Tombol Export PDF di detail + verifikasi

**Files:** `resources/views/archive/show.blade.php`

- [ ] **Step 1: Tombol Export PDF** — di `resources/views/archive/show.blade.php`, pada header detail, ubah tombol "Kembali" agar didahului tombol Export PDF bila disetujui. Ganti:

```blade
    <a href="{{ route('archive.index') }}" class="btn btn-sm btn-outline-secondary">Kembali</a>
```

menjadi:

```blade
    <div class="d-flex gap-2">
        @if($st === 'disetujui' && $canManage)
            <a href="{{ route('archive.pdf', $title->id) }}" target="_blank" class="btn btn-sm btn-outline-dark">Export PDF</a>
        @endif
        <a href="{{ route('archive.index') }}" class="btn btn-sm btn-outline-secondary">Kembali</a>
    </div>
```

- [ ] **Step 2: view:cache + suite**
Run: `php artisan view:cache && php artisan view:clear`
Expected: sukses tanpa error.
Run: `php artisan test --env=testing`
Expected: semua PASS (baseline 423 + 3 baru = 426 passed).

- [ ] **Step 3: Commit**

```bash
git add resources/views/archive/show.blade.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(archive): tombol Export PDF di detail arsip (hanya disetujui)

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

## Self-Review (penulis plan)

**1. Spec coverage:** §2 route+controller (guard canManage+disetujui) → Task 1 Step 4-5 + test forbidden cases. §3 view PDF (kop/logo + 5 seksi + PIC) → Task 1 Step 6. §3 pic_name → Task 1 Step 3. §4 tombol → Task 2. §5 test → Task 1 (3 test). ✓

**2. Placeholder scan:** tak ada TBD/TODO; kode nyata tiap step.

**3. Type/nama konsistensi:** rute `archive.pdf` konsisten controller↔view↔test↔tombol. `defaultArtifacts` item +`pic_name` dipakai view `$a['pic_name']`. Guard status `disetujui`. Variabel view (`$title,$artifacts,$custom,$isPaidOff,$isFinal`) dikirim controller↔dipakai blade. Tanpa migrasi. `show.blade` `$st` sudah didefinisikan di view existing (Fase 1) → tombol pakai `$st`/`$canManage` yang sudah ada.
