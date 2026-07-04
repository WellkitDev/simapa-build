# Spec — Export PDF Arsip Judul (Fase 2)

- **Tanggal:** 2026-07-04
- **Branch:** `archive-pdf`
- **Scope:** Tombol **Export PDF** di detail arsip yang menghasilkan PDF konsolidasi (kop + info judul/order/manuskrip/artefak+PIC + persetujuan). Unduh/stream langsung. Hanya untuk arsip **disetujui**.
- **Di luar scope:** menyimpan PDF ke Google Drive; template PDF yang bisa di-custom; PDF untuk status non-`disetujui`.

> Lanjutan Arsip Judul Fase 1 (`a00d00e`). Detail arsip (`TitleArchiveController@show`) sudah konsolidasi judul+order+manuskrip+artefak+approve. dompdf `barryvdh/laravel-dompdf` sudah terpasang & dipakai untuk Invoice/Tagihan (`Barryvdh\DomPDF\Facade\Pdf::loadView(...)->stream(...)`).

---

## 1. Tujuan & Kriteria Sukses

1. Dari detail arsip judul **disetujui**, pengelola dapat menekan **Export PDF** → PDF konsolidasi ter-generate & ter-stream ke browser.
2. PDF berisi: kop (logo Avidpedia + judul dokumen + kode/judul + tgl cetak), Info Judul, Info Order (+status lunas), Info Manuskrip (+bab), Artefak Penyelesaian (label/nilai/link/PIC/catatan), Persetujuan (approver + tgl + catatan).
3. Endpoint PDF hanya untuk status `disetujui` (403 selain itu) dan hanya `canManage` (superadmin/manager/admin/production; 403 selain itu).
4. Perilaku tertutup test; suite tetap hijau. Tanpa migrasi/model baru.

## 2. Route & Controller

- **Route** (grup auth, sesudah rute `archive.*` existing): `GET management/archive/{id}/pdf` name `archive.pdf` `whereNumber('id')`.
- **`TitleArchiveController@pdf(int $id)`**:
  ```php
  abort_unless($this->canManage(), 403);
  $title = Title::with([ /* sama seperti show(): chapters, scope, bookIsbn, archive.approver, archiveArtifacts.pic,
      orderDetails.order.user, orderDetails.order.invoices, orderDetails.order.payments, orderDetails.order.details, orderDetails.titleProgress */ ])->findOrFail($id);
  abort_unless(optional($title->archive)->status === 'disetujui', 403);
  return \Barryvdh\DomPDF\Facade\Pdf::loadView('archive.pdf', [
      'title'     => $title,
      'artifacts' => $this->service->defaultArtifacts($title),
      'isPaidOff' => $title->isPaidOff(),
      'isFinal'   => $title->manuscriptIsFinal(),
  ])->stream('Arsip_' . ($title->code ?: $title->id) . '.pdf');
  ```

## 3. View — `resources/views/archive/pdf.blade.php`

Self-contained HTML (pola `payments/tagihan/tagihan_pdf.blade.php`): `<!DOCTYPE html>` + inline `<style>` (`font-family: DejaVu Sans`), tabel `border-collapse`. Tanpa aset eksternal kecuali logo lokal via `public_path`.

- **Kop**: `<img src="{{ public_path('assets/images/logo-av-90.png') }}" height="40">` + judul "ARSIP JUDUL SELESAI" + baris kode/judul + "Dicetak: {{ now()->format('d M Y H:i') }}".
- **Info Judul**: kode, judul, jenis, tipe naskah, bidang (scope).
- **Info Order**: tabel per orderDetail — Kode Order, Marketing (`order->user->name`), Tanggal, Biaya (`number_format cost_amount`), Pembayaran (`order->isLunas()` → Lunas/Belum).
- **Info Manuskrip**: `manuscriptStatusLabel()`; bila buku, daftar bab (`chapters->judul`).
- **Artefak Penyelesaian**: tabel — Label, Nilai (link → tampil URL; file → nama file / URL; teks → nilai), PIC (`pic->name` via resolve dari `$artifacts` pic_user_id — lihat catatan), Catatan. Termasuk baris custom (`archiveArtifacts where is_custom`).
- **Persetujuan**: status, approver (`archive->approver->name`), tanggal (`archive->approved_at`), catatan (`archive->approval_note`).

> **Catatan PIC di PDF:** `defaultArtifacts()` mengembalikan `pic_user_id` (bukan objek). Untuk menampilkan nama PIC di PDF, controller kirim juga peta id→nama, atau view resolve via `\App\Models\User::find`. **Keputusan:** tambahkan `pic_name` ke tiap item di `defaultArtifacts()` (resolve dari relasi/`User`) agar view sederhana — ATAU kirim `$staff` peta. Implementasi: perluas `defaultArtifacts()` menambah `'pic_name'` (dari `optional($row->pic)->name` bila row ada; else null) + eager-load `archiveArtifacts.pic`. Untuk custom rows, `$title->archiveArtifacts->where('is_custom')` sudah punya relasi `pic`.

## 4. UI — tombol di `archive/show.blade.php`

Pada kartu header/persetujuan, bila `$st === 'disetujui'` (dan `$canManage`), tambah:
```blade
<a href="{{ route('archive.pdf', $title->id) }}" target="_blank" class="btn btn-sm btn-outline-dark">Export PDF</a>
```
(letakkan di header detail dekat tombol "Kembali", atau di kartu "Disetujui").

## 5. Rencana Test — `tests/Feature/ArchivePdfTest.php`

- `pdf_streams_for_approved_title`: judul + archive `disetujui` → `GET archive.pdf` (manager) → `assertOk()` + `assertHeader('content-type', 'application/pdf')` (atau `assertHeader('content-type', ... contains pdf)`; dompdf `stream()` set `application/pdf`).
- `pdf_forbidden_when_not_approved`: archive `diajukan` → `GET archive.pdf` → 403.
- `pdf_forbidden_for_marketing`: marketing → 403.
- **Regresi**: `TitleArchiveTest` tetap hijau; `php artisan view:cache` bersih.

Suite via `.env.testing`, `GoogleDriveService` di-mock (untuk fixture eligibleBook bila reuse). Tanpa migrasi.

## 6. Komponen

- **Baru:** `resources/views/archive/pdf.blade.php`; test `tests/Feature/ArchivePdfTest.php`.
- **Diubah:** `app/Http/Controllers/Pages/TitleArchiveController.php` (+`pdf()`); `routes/web.php` (+`archive.pdf`); `resources/views/archive/show.blade.php` (+tombol Export PDF); `app/Services/TitleArchivalService.php` (`defaultArtifacts` +`pic_name`).
- **Tak diubah:** model/tabel.

## 7. Asumsi & Risiko

- PDF text-based (dompdf) — logo lokal via `public_path` (dompdf mengizinkan file lokal). Bila logo gagal dimuat, PDF tetap ter-generate (fallback tanpa logo tak esensial).
- Hanya `disetujui` → bukti selesai resmi; button hide + endpoint guard.
- Nama PIC dibutuhkan di PDF → `defaultArtifacts` diperluas `pic_name` (perubahan kecil, kompatibel; view show existing tak terpengaruh karena hanya menambah key).
- Stream langsung (tak simpan) → tanpa storage/Drive.
