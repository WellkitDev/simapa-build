# Berkas ISBN Lanjutan & Performa Unggahan — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Menambah sertifikat HKI & barcode ISBN ke blok Berkas & Publikasi, menghapus round-trip OAuth ~220 ms dari setiap pemuatan enam halaman, dan menghidupkan antrian yang selama ini menelan seluruh email tanpa pernah mengeksekusinya.

**Architecture:** Empat berkas ISBN dipindah ke satu konstanta bersumber tunggal (label + aturan + wajib-atau-tidak) yang dibaca formulir, direktori, dan validasi. `GoogleDriveService` dikosongkan kerja jaringannya dari constructor lalu didaftarkan singleton. Antrian digerakkan `queue:work --stop-when-empty` dari scheduler, bukan daemon. Urutan simpan ISBN dibalik agar unggahan gagal tak meninggalkan registrasi tanpa berkas.

**Tech Stack:** Laravel 10, Blade, MySQL/MariaDB, PHPUnit, Google Drive API, Bootstrap 5.

**Spec:** `docs/superpowers/specs/2026-08-16-berkas-isbn-lanjutan-dan-performa-unggahan-design.md`

---

## Struktur berkas

| Berkas | Peran | Tugas |
|---|---|---|
| `app/Models/ManuscriptFile.php` | konstanta `BERKAS_ISBN` + helper turunan | 1 |
| `app/Services/ManuscriptFileService.php` | menerima slot ISBN sebagai slot sah | 1 |
| `app/Http/Controllers/Pages/BookIsbnController.php` | validasi & kelengkapan dari konstanta; urutan simpan | 2, 6 |
| `resources/views/titles/show.blade.php` | formulir Kelola berkas (perulangan) + bug collapse | 3, 7 |
| `resources/views/isbn/index.blade.php` | empat kolom berkas | 3 |
| `app/Services/GoogleDriveService.php` | otentikasi malas | 4 |
| `app/Providers/AppServiceProvider.php` | daftar singleton | 4 |
| `app/Console/Kernel.php` | `queue:work` terjadwal | 5 |
| `.env.example` | dokumentasi `QUEUE_CONNECTION` | 5 |
| `resources/views/layouts/master.blade.php` | kunci tombol unggah seluruh app | 7 |

**Catatan lingkungan:** test memakai `avidpedi_simapa_test` lewat `.env.testing`. **Jangan pernah menjalankan dua proses `php artisan test` bersamaan** — keduanya memakai basis data uji yang sama dan saling merusak status migrasi (pernah terjadi, gejalanya `SQLSTATE[HY000] 1412 Table definition has changed` yang berpindah-pindah). Bila itu terjadi, pulihkan dengan `php artisan migrate:fresh --env=testing --force`.

---

## Task 1: Satu sumber tunggal berkas ISBN + slot HKI & barcode

**Files:**
- Modify: `app/Models/ManuscriptFile.php`
- Modify: `app/Services/ManuscriptFileService.php` (validasi slot di `upload()`)
- Test: `tests/Feature/BookIsbnBerkasTest.php` (ubah test yang ada + tambah)

- [ ] **Step 1: Ubah test slot yang ada agar mencerminkan empat berkas**

Test `slot_isbn_terdaftar_tapi_tidak_bocor_ke_berkas_naskah` saat ini mengunci dua slot dan memeriksa `ManuscriptFile::SLOTS`. Desain baru memindahkan slot ISBN keluar dari `SLOTS` sepenuhnya, jadi ganti seluruh isinya dengan:

```php
    /** @test */
    public function empat_berkas_isbn_terdaftar_tapi_tidak_bocor_ke_berkas_naskah(): void
    {
        $this->assertSame(
            ['ebook', 'sertifikat_isbn', 'barcode_isbn', 'sertifikat_hki'],
            ManuscriptFile::slotsIsbn()
        );

        // Slot ISBN hidup di BERKAS_ISBN, BUKAN di SLOTS — SLOTS khusus tahap naskah.
        foreach (ManuscriptFile::slotsIsbn() as $slot) {
            $this->assertArrayNotHasKey($slot, ManuscriptFile::SLOTS, "{$slot} tak boleh jadi slot tahap naskah");
            $this->assertArrayNotHasKey($slot, ManuscriptFile::slotsFor(true));
            $this->assertArrayNotHasKey($slot, ManuscriptFile::slotsFor(false));
        }

        // Labelnya tetap bisa dibaca lewat slotLabel().
        $f = new ManuscriptFile(['slot' => 'barcode_isbn']);
        $this->assertSame('Barcode ISBN', $f->slotLabel());
    }

    /** @test */
    public function hanya_barcode_yang_wajib_dari_dua_berkas_baru(): void
    {
        $this->assertTrue(ManuscriptFile::BERKAS_ISBN['barcode_isbn']['wajibCetak']);
        $this->assertFalse(ManuscriptFile::BERKAS_ISBN['sertifikat_hki']['wajibCetak'],
            'Sertifikat HKI opsional — jangan diam-diam jadi wajib.');
    }
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=empat_berkas_isbn_terdaftar`
Expected: FAIL — `Call to undefined method App\Models\ManuscriptFile::slotsIsbn()`.

- [ ] **Step 3: Ganti SLOTS_ISBN dengan konstanta bermetadata**

Di `app/Models/ManuscriptFile.php`, **hapus** dua entri `'ebook'` dan `'sertifikat_isbn'` dari `SLOTS` (dikembalikan ke daftar tahap naskah saja), **hapus** konstanta `SLOTS_ISBN`, dan tambahkan setelah `SLOTS_BUKU`:

```php
    /**
     * Berkas milik registrasi ISBN — bukan tahap naskah. Satu sumber tunggal: label,
     * aturan mime, atribut accept untuk input file, dan wajib-atau-tidak saat status
     * Cetak/Terbit. Formulir Kelola, Direktori ISBN, dan validasi controller sama-sama
     * membaca dari sini, jadi menambah slot kelima kelak cukup satu baris di satu tempat.
     *
     * Sengaja TERPISAH dari SLOTS supaya tak pernah bocor ke kartu berkas Detail Naskah.
     */
    public const BERKAS_ISBN = [
        'ebook' => [
            'label' => 'E-book', 'mimes' => 'pdf,epub,zip',
            'accept' => '.pdf,.epub,.zip', 'wajibCetak' => true,
        ],
        'sertifikat_isbn' => [
            'label' => 'Sertifikat ISBN', 'mimes' => 'pdf,jpg,jpeg,png',
            'accept' => '.pdf,.jpg,.jpeg,.png', 'wajibCetak' => true,
        ],
        'barcode_isbn' => [
            'label' => 'Barcode ISBN', 'mimes' => 'pdf,jpg,jpeg,png',
            'accept' => '.pdf,.jpg,.jpeg,.png', 'wajibCetak' => true,
        ],
        'sertifikat_hki' => [
            'label' => 'Sertifikat HKI', 'mimes' => 'pdf,jpg,jpeg,png',
            'accept' => '.pdf,.jpg,.jpeg,.png', 'wajibCetak' => false,
        ],
    ];

    /** Batas ukuran unggahan (KB) — seragam untuk seluruh berkas ISBN. */
    public const BATAS_KB = 20480;

    /** @return array<int,string> nama slot berkas ISBN, urut sesuai BERKAS_ISBN. */
    public static function slotsIsbn(): array
    {
        return array_keys(self::BERKAS_ISBN);
    }

    /** Seluruh slot yang sah disimpan: tahap naskah + berkas ISBN. */
    public static function slotSah(): array
    {
        return array_merge(array_keys(self::SLOTS), self::slotsIsbn());
    }

    /** @return array<string,string> aturan validasi unggahan per slot ISBN. */
    public static function rulesIsbn(): array
    {
        $out = [];
        foreach (self::BERKAS_ISBN as $slot => $b) {
            $out[$slot] = 'nullable|file|mimes:' . $b['mimes'] . '|max:' . self::BATAS_KB;
        }

        return $out;
    }
```

Lalu ganti `slotLabel()` agar mengenali keduanya:

```php
    public function slotLabel(): string
    {
        return self::SLOTS[$this->slot]
            ?? self::BERKAS_ISBN[$this->slot]['label']
            ?? $this->slot;
    }
```

- [ ] **Step 4: Izinkan slot ISBN disimpan oleh service**

`ManuscriptFileService::upload()` menolak slot di luar `ManuscriptFile::SLOTS`. Slot ISBN kini di luar daftar itu, jadi ganti penjagaannya:

```php
        if (! in_array($slot, ManuscriptFile::slotSah(), true)) {
            throw ValidationException::withMessages(['slot' => 'Slot naskah tidak valid.']);
        }
```

Penjagaan di `DetailNaskahController` (`'slot' => 'required|in:' . implode(',', array_keys(ManuscriptFile::SLOTS))`) sengaja **dibiarkan sempit** — halaman naskah memang tak boleh mengunggah e-book atau barcode.

- [ ] **Step 5: Perbaiki pemakaian SLOTS_ISBN yang kini hilang**

`BookIsbnController::index()` memakai `ManuscriptFile::SLOTS_ISBN`. Ganti jadi `ManuscriptFile::slotsIsbn()`.

- [ ] **Step 6: Jalankan test**

Run: `php artisan test --filter=BookIsbnBerkasTest`
Expected: PASS. Bila ada test lama yang masih menyebut `SLOTS_ISBN`, perbaiki penyebutannya — bukan assertion-nya.

- [ ] **Step 7: Penjaga regresi**

Run: `php artisan test --filter=Naskah`
Expected: tak ada kegagalan. Ini yang akan menangkap kalau penghapusan slot dari `SLOTS` merusak kartu berkas Detail Naskah.

- [ ] **Step 8: Commit**

```bash
git add app/Models/ManuscriptFile.php app/Services/ManuscriptFileService.php app/Http/Controllers/Pages/BookIsbnController.php tests/Feature/BookIsbnBerkasTest.php
git -c user.name="WellkitDev" -c user.email="rahmatpurnomo808@gmail.com" commit -m "$(cat <<'EOF'
isbn: berkas ISBN jadi satu sumber tunggal + slot barcode & sertifikat HKI

Co-Authored-By: Mira <admin@avidpedia.com>
EOF
)"
```

---

## Task 2: Validasi & kelengkapan dibaca dari konstanta

**Files:**
- Modify: `app/Http/Controllers/Pages/BookIsbnController.php`
- Test: `tests/Feature/BookIsbnValidasiTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Tambahkan ke `tests/Feature/BookIsbnValidasiTest.php`. Sesuaikan juga helper `lengkap()` yang sudah ada agar menyertakan barcode — tanpa itu seluruh test lain di berkas ini akan merah begitu barcode diwajibkan:

```php
    // Tambahkan ke array di dalam lengkap(), setelah 'sertifikat_isbn':
    //     'barcode_isbn' => UploadedFile::fake()->create('barcode.png', 5, 'image/png'),

    /** @test */
    public function status_cetak_menolak_bila_barcode_belum_ada(): void
    {
        $book = $this->buku();

        $this->actingAs($this->admin())
            ->post(route('isbn.store'), $this->lengkap($book, ['barcode_isbn']))
            ->assertSessionHasErrors('barcode_isbn');

        $this->assertDatabaseMissing('tb_book_isbns', ['title_id' => $book->id]);
    }

    /** @test */
    public function sertifikat_hki_tidak_pernah_menghalangi(): void
    {
        $book = $this->buku();

        // lengkap() memang tak pernah menyertakan HKI — ia opsional selamanya.
        $this->actingAs($this->admin())
            ->post(route('isbn.store'), $this->lengkap($book))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tb_book_isbns', ['title_id' => $book->id, 'status' => 'cetak']);
    }

    /** @test */
    public function sertifikat_hki_tersimpan_bila_diunggah(): void
    {
        $book = $this->buku();

        $this->actingAs($this->admin())->post(route('isbn.store'), array_merge(
            $this->lengkap($book),
            ['sertifikat_hki' => UploadedFile::fake()->create('hki.pdf', 15, 'application/pdf')]
        ))->assertSessionHasNoErrors();

        $isbn = \App\Models\BookIsbn::where('title_id', $book->id)->firstOrFail();
        $this->assertSame('hki.pdf', $isbn->berkas('sertifikat_hki')?->original_name);
    }
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=status_cetak_menolak_bila_barcode_belum_ada`
Expected: FAIL — `Session is missing expected key 'barcode_isbn'`.

- [ ] **Step 3: Turunkan aturan dari konstanta**

Di `BookIsbnController`, **hapus** konstanta `BERKAS_RULES` dan ganti seluruh pemakaiannya:

- `array_merge([...], self::BERKAS_RULES)` → `array_merge([...], ManuscriptFile::rulesIsbn())`
- `foreach (array_keys(self::BERKAS_RULES) as $slot)` (dua tempat: `validated()` dan `simpanBerkas()`) → `foreach (ManuscriptFile::slotsIsbn() as $slot)`

- [ ] **Step 4: Kelengkapan berkas dibaca dari konstanta**

Ganti `assertBerkasLengkap()`:

```php
    /**
     * Berkas wajib saat Cetak/Terbit, TAPI yang sudah pernah diunggah dihitung terisi —
     * menyimpan ulang tak boleh memaksa memilih berkas yang sama sekali lagi. Slot mana
     * yang wajib dibaca dari ManuscriptFile::BERKAS_ISBN, bukan didaftar ulang di sini.
     */
    private function assertBerkasLengkap(Request $request, ?BookIsbn $isbn): void
    {
        if ($request->input('status') !== 'cetak') {
            return;
        }

        $galat = [];
        foreach (ManuscriptFile::BERKAS_ISBN as $slot => $berkas) {
            if (! $berkas['wajibCetak']) {
                continue;
            }
            if ($request->hasFile($slot) || ($isbn && $isbn->berkas($slot))) {
                continue;
            }
            $galat[$slot] = $berkas['label'] . ' wajib diunggah untuk status Cetak/Terbit.';
        }

        if ($galat !== []) {
            throw ValidationException::withMessages($galat);
        }
    }
```

- [ ] **Step 5: Jalankan test**

Run: `php artisan test --filter=BookIsbnValidasiTest`
Expected: PASS (8 test).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Pages/BookIsbnController.php tests/Feature/BookIsbnValidasiTest.php
git -c user.name="WellkitDev" -c user.email="rahmatpurnomo808@gmail.com" commit -m "$(cat <<'EOF'
isbn: barcode wajib saat Cetak/Terbit, sertifikat HKI opsional

Co-Authored-By: Mira <admin@avidpedia.com>
EOF
)"
```

---

## Task 3: Formulir & direktori memakai perulangan

**Files:**
- Modify: `resources/views/titles/show.blade.php` (blok Berkas & Publikasi)
- Modify: `resources/views/isbn/index.blade.php`
- Test: `tests/Feature/BookIsbnBerkasTest.php`

- [ ] **Step 1: Tulis test yang gagal**

```php
    /** @test */
    public function direktori_menampilkan_keempat_kolom_berkas(): void
    {
        $book = $this->buku();
        \App\Models\BookIsbn::create([
            'title_id' => $book->id, 'status' => 'cetak', 'no_isbn' => '978-602-2',
        ]);
        foreach (['ebook' => 'e', 'sertifikat_isbn' => 's', 'barcode_isbn' => 'b', 'sertifikat_hki' => 'h'] as $slot => $tanda) {
            ManuscriptFile::create([
                'title_id' => $book->id, 'title_chapter_id' => null, 'slot' => $slot,
                'version' => 1, 'original_name' => $slot . '.pdf',
                'drive_url' => 'https://drive/' . $tanda, 'uploaded_by' => $this->user('admin')->id,
            ]);
        }

        $isi = $this->actingAs($this->user('marketing'))
            ->get(route('isbn.index'))->assertOk()->getContent();

        foreach (['E-book', 'Sertifikat ISBN', 'Barcode ISBN', 'Sertifikat HKI'] as $label) {
            $this->assertStringContainsString($label, $isi);
        }
        foreach (['https://drive/e', 'https://drive/s', 'https://drive/b', 'https://drive/h'] as $url) {
            $this->assertStringContainsString($url, $isi);
        }
    }
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=direktori_menampilkan_keempat_kolom_berkas`
Expected: FAIL — `Failed asserting that '…' contains "Barcode ISBN"`.

- [ ] **Step 3: Formulir jadi perulangan**

Di `resources/views/titles/show.blade.php`, ganti blok `@php` di awal `#isbnForm` dan seluruh isi `<div ... id="isbnBerkas">` dengan versi berbasis perulangan:

```blade
                @php
                    $statusKini = old('status', optional($isbn)->status);
                    $berkasIsbn = \App\Models\ManuscriptFile::BERKAS_ISBN;
                @endphp
```

```blade
                    <div class="border-top mt-3 pt-3 {{ in_array($statusKini, ['ber_isbn', 'cetak'], true) ? '' : 'd-none' }}" id="isbnBerkas">
                        <div class="fw-bold small mb-2">Berkas &amp; Publikasi</div>
                        <div class="row g-2">
                            @foreach($berkasIsbn as $slot => $b)
                                @php $tersimpan = $isbn?->berkas($slot); @endphp
                                <div class="col-md-4">
                                    <label class="form-label small mb-1">
                                        {{ $b['label'] }}
                                        @if($b['wajibCetak'])
                                            <span class="text-danger d-none" data-isbn-cetak>*</span>
                                        @else
                                            <span class="text-muted">(opsional)</span>
                                        @endif
                                    </label>
                                    <input type="file" name="{{ $slot }}" class="form-control form-control-sm" accept="{{ $b['accept'] }}">
                                    @if($tersimpan)
                                        <div class="form-text"><a href="{{ $tersimpan->drive_url }}" target="_blank" rel="noopener">{{ $tersimpan->original_name }}</a> · v{{ $tersimpan->version }} · {{ $tersimpan->uploader?->name ?? '—' }}</div>
                                    @else
                                        <div class="form-text">Belum ada berkas.</div>
                                    @endif
                                </div>
                            @endforeach
                            <div class="col-md-4">
                                <label class="form-label small mb-1">Link Terbit di web avidpedia <span class="text-danger d-none" data-isbn-cetak>*</span></label>
                                <input type="url" name="link_terbit" value="{{ old('link_terbit', optional($isbn)->link_terbit) }}" class="form-control form-control-sm" placeholder="https://avidpedia.com/...">
                            </div>
                        </div>
                        <div class="form-text mt-2">Mengunggah ulang menambah versi baru — berkas lama tetap tersimpan.</div>
                    </div>
```

Variabel `$ebook` dan `$sertifikat` yang lama tidak dipakai lagi — hapus dari blok `@php`.

- [ ] **Step 4: Direktori jadi empat kolom**

Di `resources/views/isbn/index.blade.php`, ganti `<thead>`/`<tbody>` agar kolom berkas dihasilkan perulangan:

```blade
            <thead><tr>
                <th>Kode</th><th>Judul</th><th>No. ISBN</th>
                @foreach(\App\Models\ManuscriptFile::BERKAS_ISBN as $b)
                    <th>{{ $b['label'] }}</th>
                @endforeach
                <th>Link Terbit</th><th>Status</th><th>Penerbit</th><th>Tgl ISBN</th><th>Aksi</th>
            </tr></thead>
            <tbody>
                @foreach($books as $b)
                    <tr>
                        <td>{{ $b->code ?? '—' }}</td>
                        <td class="dt-judul">{{ $b->title }}</td>
                        <td>{{ $b->bookIsbn?->no_isbn ?: '—' }}</td>
                        @foreach(array_keys(\App\Models\ManuscriptFile::BERKAS_ISBN) as $slot)
                            @php $f = $berkas[$b->id . ':' . $slot] ?? null; @endphp
                            <td>@if($f && $f->drive_url)<a href="{{ $f->drive_url }}" target="_blank" rel="noopener">Unduh</a>@else—@endif</td>
                        @endforeach
                        <td>@if($b->bookIsbn?->link_terbit)<a href="{{ $b->bookIsbn->link_terbit }}" target="_blank" rel="noopener">Buka</a>@else—@endif</td>
                        <td>@if($b->bookIsbn)<span class="badge bg-info">{{ $b->bookIsbn->statusLabel() }}</span>@else<span class="badge bg-light text-dark border">Belum didaftarkan</span>@endif</td>
                        <td>{{ $b->bookIsbn?->penerbit ?: '—' }}</td>
                        <td>{{ optional($b->bookIsbn?->tgl_isbn)->format('d M Y') ?? '—' }}</td>
                        <td>@if($canManage)<a href="{{ route('title.show', $b->id) }}" class="btn btn-xs btn-outline-primary">Kelola</a>@else<span class="text-muted">—</span>@endif</td>
                    </tr>
                @endforeach
            </tbody>
```

Kolom `<th>` dan `<td>` kini sama-sama dihasilkan dari konstanta yang sama, jadi jumlahnya tak mungkin lagi meleset — itulah alasan perulangan ini, bukan sekadar merapikan.

- [ ] **Step 5: Jalankan test + regresi**

Run: `php artisan test --filter=BookIsbn`
Expected: PASS seluruhnya.

Run: `php artisan test --filter=Title`
Expected: tak ada kegagalan (`show.blade.php` dirender `TitleSubmitButtonTest`).

- [ ] **Step 6: Commit**

```bash
git add resources/views/titles/show.blade.php resources/views/isbn/index.blade.php tests/Feature/BookIsbnBerkasTest.php
git -c user.name="WellkitDev" -c user.email="rahmatpurnomo808@gmail.com" commit -m "$(cat <<'EOF'
isbn: formulir dan direktori merender berkas dari satu daftar

Co-Authored-By: Mira <admin@avidpedia.com>
EOF
)"
```

---

## Task 4: GoogleDriveService berhenti menahan setiap halaman

Ini perbaikan berdampak terbesar di seluruh rencana. Enam controller menyuntik service ini di constructor, dan constructor-nya melakukan round-trip OAuth ke Google. Halaman yang tak pernah menyentuh berkas pun membayarnya.

**Files:**
- Modify: `app/Services/GoogleDriveService.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/GoogleDriveLazyTest.php` (baru)

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/GoogleDriveLazyTest.php`:

```php
<?php
// tests/Feature/GoogleDriveLazyTest.php

namespace Tests\Feature;

use App\Http\Controllers\Pages\OrderBookController;
use App\Services\GoogleDriveService;
use Tests\TestCase;

/**
 * GoogleDriveService dulu berotentikasi di constructor-nya. Karena ia disuntik ke
 * constructor enam controller, SETIAP pemuatan Daftar Order, Pembayaran, Profil,
 * Manajemen User, Submission Jurnal, dan Laporan Harian membayar round-trip OAuth
 * ~220 ms sebelum satu baris kode halaman berjalan — termasuk halaman yang tak
 * pernah menyentuh berkas.
 *
 * Ambangnya sengaja longgar (50 ms lawan ~220 ms terukur): selisihnya terlalu besar
 * untuk salah baca, bahkan di mesin yang sedang sibuk.
 */
class GoogleDriveLazyTest extends TestCase
{
    private const AMBANG_MS = 50;

    private function msUntuk(callable $fn): float
    {
        $t = microtime(true);
        $fn();

        return (microtime(true) - $t) * 1000;
    }

    /** @test */
    public function membangun_service_tidak_menyentuh_jaringan(): void
    {
        $ms = $this->msUntuk(fn () => new GoogleDriveService());

        $this->assertLessThan(self::AMBANG_MS, $ms,
            "Konstruksi memakan {$ms} ms — otentikasi masih terjadi di constructor.");
    }

    /** @test */
    public function controller_yang_menyuntiknya_ikut_bebas(): void
    {
        $ms = $this->msUntuk(fn () => app(OrderBookController::class));

        $this->assertLessThan(self::AMBANG_MS, $ms,
            "Resolusi OrderBookController memakan {$ms} ms — halaman Daftar Order masih menunggu Google.");
    }

    /** @test */
    public function service_dibagikan_satu_instance_per_request(): void
    {
        $this->assertSame(
            app(GoogleDriveService::class),
            app(GoogleDriveService::class),
            'Tanpa singleton, tiap pemakaian di satu request membayar otentikasi lagi.'
        );
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=GoogleDriveLazyTest`
Expected: FAIL. Dua bentuk kegagalan sama-sama sah dan sama-sama membuktikan masalahnya: bila kredensial Drive hidup, assertion waktu yang gagal (~220 ms); bila tidak, `Exception: Failed to fetch access token` yang dilempar dari constructor. Catat mana yang Anda lihat.

- [ ] **Step 3: Kosongkan constructor**

Di `app/Services/GoogleDriveService.php`, ganti properti dan constructor:

```php
    private ?GoogleClient $client = null;
    private ?GoogleDrive $service = null;

    /**
     * Constructor SENGAJA kosong. Service ini disuntik ke constructor enam controller,
     * dan Laravel membangun dependensi controller pada setiap request — jadi kerja
     * apa pun di sini dibayar oleh halaman yang tak pernah menyentuh berkas.
     * Otentikasi terjadi saat client()/service() pertama kali dipanggil.
     */
    public function __construct()
    {
    }

    private function client(): GoogleClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $client = new GoogleClient();
        $client->setClientId(config('filesystems.disks.google.clientId'));
        $client->setClientSecret(config('filesystems.disks.google.clientSecret'));
        $client->setAccessType('offline');
        $client->setApprovalPrompt('force'); // penting untuk refresh token

        $refreshToken = config('filesystems.disks.google.refreshToken');
        if (! $refreshToken) {
            throw new \Exception('Refresh token is missing in config.');
        }

        $token = $client->fetchAccessTokenWithRefreshToken($refreshToken);
        if (isset($token['error'])) {
            throw new \Exception('Failed to fetch access token: ' . ($token['error_description'] ?? $token['error']));
        }

        $client->setAccessToken($token);

        return $this->client = $client;
    }

    private function service(): GoogleDrive
    {
        return $this->service ??= new GoogleDrive($this->client());
    }
```

- [ ] **Step 4: Ganti seluruh pemakaian jadi pemanggil malas**

Di berkas yang sama, ganti setiap `$this->service->` jadi `$this->service()->` dan setiap `$this->client->` jadi `$this->client()->`, **kecuali** di dalam `client()`/`service()` itu sendiri. Titik yang terdampak ada di method: `getAccessToken()`, `makeFileToPublic()`, `uploadFile()`, `getFileIdFromPath()`, `getImageStream()`, `deleteFile()`, `getOrCreateFolderByPath()`.

Verifikasi tak ada yang tertinggal:

```bash
grep -n 'this->service->\|this->client->' app/Services/GoogleDriveService.php
```
Expected: tak ada keluaran.

- [ ] **Step 5: Jaga agar kegagalan tetap berupa null, bukan halaman 500**

`ManuscriptFileService` mengandalkan `uploadFile()` mengembalikan `null` saat gagal untuk melempar pesan berbahasa Indonesia. Dengan otentikasi kini terjadi di dalam `uploadFile()`, kegagalan otentikasi harus ikut tertangkap. Blok `try` di `uploadFile()` menangkap `\Exception`, dan `client()` melempar `\Exception` — jadi sudah tertangkap. **Buktikan, jangan diasumsikan**, dengan menambahkan test ini ke `GoogleDriveLazyTest`:

```php
    /** @test */
    public function gagal_otentikasi_mengembalikan_null_bukan_melempar(): void
    {
        // Tanpa refresh token, client() melempar — uploadFile() harus tetap menelannya
        // jadi null, karena ManuscriptFileService mengandalkan itu untuk pesan Indonesia.
        config(['filesystems.disks.google.refreshToken' => null]);

        $hasil = (new GoogleDriveService())->uploadFile(
            \Illuminate\Http\UploadedFile::fake()->create('x.pdf', 1, 'application/pdf')
        );

        $this->assertNull($hasil);
    }
```

- [ ] **Step 6: Daftarkan singleton**

Di `app/Providers/AppServiceProvider.php`, dalam `register()`:

```php
        // Satu instance per request: otentikasi Drive dibayar paling banyak sekali,
        // dan halaman yang tak menyentuh berkas tidak membayarnya sama sekali.
        $this->app->singleton(\App\Services\GoogleDriveService::class);
```

- [ ] **Step 7: Jalankan test**

Run: `php artisan test --filter=GoogleDriveLazyTest`
Expected: PASS (4 test).

- [ ] **Step 8: Penjaga regresi — ini yang paling penting di task ini**

Run: `php artisan test`
Expected: seluruh suite hijau (baseline 1057 lolos + 1 dilewati, ditambah test baru). Perubahan ini menyentuh jalur unggahan seluruh aplikasi — pembayaran, order, profil, laporan harian, arsip judul, checklist dokumen — jadi suite penuh, bukan filter.

- [ ] **Step 9: Ukur ulang, buktikan angkanya berubah**

Jangan cukup dengan test hijau. Ukur ulang biaya resolusi controller yang sama seperti sebelum perbaikan:

```bash
php artisan tinker --execute="foreach (['OrderBookController','PaymentBookController','ProfileController'] as \$c) { \$cls = 'App\\\\Http\\\\Controllers\\\\Pages\\\\' . \$c; \$t = microtime(true); app(\$cls); echo str_pad(\$c, 28) . round((microtime(true)-\$t)*1000) . ' ms' . PHP_EOL; }"
```
Expected: ketiganya di bawah 10 ms (sebelumnya 263/210/223 ms). Laporkan angka sesungguhnya.

- [ ] **Step 10: Commit**

```bash
git add app/Services/GoogleDriveService.php app/Providers/AppServiceProvider.php tests/Feature/GoogleDriveLazyTest.php
git -c user.name="WellkitDev" -c user.email="rahmatpurnomo808@gmail.com" commit -m "$(cat <<'EOF'
drive: otentikasi Google pindah dari constructor ke pemakaian pertama

GoogleDriveService disuntik ke constructor enam controller, dan constructor-nya
memanggil fetchAccessTokenWithRefreshToken() — round-trip OAuth ke Google. Karena
Laravel membangun dependensi controller di setiap request, Daftar Order membayar
263 ms, Pembayaran 210 ms, Profil 223 ms, Manajemen User 233 ms, Submission Jurnal
216 ms, dan Laporan Harian 221 ms sebelum satu baris kode halamannya berjalan —
padahal tak satu pun menyentuh berkas.

Kini constructor kosong dan otentikasi terjadi saat benar-benar dipakai, plus
didaftarkan singleton supaya satu request membayar paling banyak sekali.

Co-Authored-By: Mira <admin@avidpedia.com>
EOF
)"
```

---

## Task 5: Antrian benar-benar dieksekusi

Keempat job email `implements ShouldQueue`, produksi memakai `QUEUE_CONNECTION=database`, dan tak ada `queue:work` di mana pun. Setiap email invoice, refund, slip gaji, dan invoice layanan masuk tabel `jobs` lalu diam selamanya.

**Files:**
- Modify: `app/Console/Kernel.php`
- Modify: `.env.example`
- Test: `tests/Feature/QueueScheduleTest.php` (baru)

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/QueueScheduleTest.php`:

```php
<?php
// tests/Feature/QueueScheduleTest.php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Produksi berjalan di cPanel yang tak mengizinkan proses permanen, jadi antrian
 * digerakkan cron yang sama dengan scheduler. Tanpa ini seluruh job ShouldQueue —
 * email invoice, refund, slip gaji, invoice layanan — masuk tabel jobs lalu diam
 * selamanya, tanpa satu pun pesan galat.
 */
class QueueScheduleTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function scheduler_menjalankan_queue_work_yang_berhenti_sendiri(): void
    {
        $perintah = collect(app(Schedule::class)->events())
            ->map(fn ($e) => $e->command ?? '')
            ->filter(fn (string $c) => str_contains($c, 'queue:work'))
            ->values();

        $this->assertCount(1, $perintah, 'queue:work harus terjadwal tepat sekali.');
        $this->assertStringContainsString('--stop-when-empty', $perintah[0],
            'Tanpa --stop-when-empty prosesnya jadi daemon, yang tak boleh di shared hosting.');
    }

    /** @test */
    public function job_yang_diantrikan_benar_benar_dieksekusi(): void
    {
        config(['queue.default' => 'database']);
        Cache::forget('bukti-antrian');

        dispatch(function () {
            Cache::put('bukti-antrian', 'jalan', 60);
        });

        // Sebelum worker jalan: job menunggu, efeknya belum ada.
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertNull(Cache::get('bukti-antrian'));

        Artisan::call('queue:work', ['--stop-when-empty' => true, '--quiet' => true]);

        $this->assertSame(0, DB::table('jobs')->count(), 'Job harus habis dikerjakan.');
        $this->assertSame('jalan', Cache::get('bukti-antrian'));
    }
}
```

Test kedua sengaja menjalankan worker sungguhan alih-alih memeriksa string konfigurasi. Memeriksa bahwa baris scheduler ada tidak membuktikan job pernah dieksekusi — dan justru itu bug yang sedang diperbaiki.

**Kalau test kedua bermasalah**, kemungkinan besar penyebabnya salah satu dari dua hal, dan keduanya punya jalan keluar yang tidak mengurangi nilai test:

- *Closure tak bisa diserialisasi.* Ganti closure dengan job sungguhan yang paling murah efek sampingnya, atau buat kelas job kecil khusus test di `tests/`.
- *Worker tak melihat baris `jobs` karena `RefreshDatabase` menahannya di dalam transaksi.* Ganti trait jadi `DatabaseTransactions` untuk test ini saja, atau pakai `$this->beginDatabaseTransaction()` manual. Bila tetap buntu, laporkan — **jangan** menurunkan test jadi sekadar memeriksa isi konfigurasi. Membuktikan job benar-benar berjalan adalah seluruh alasan test ini ada.

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=scheduler_menjalankan_queue_work`
Expected: FAIL — `Failed asserting that actual size 0 matches expected size 1`.

- [ ] **Step 3: Jadwalkan worker**

Di `app/Console/Kernel.php`, tambahkan di dalam `schedule()`:

```php
        // Produksi berjalan di cPanel yang tak mengizinkan proses permanen, jadi
        // antrian digerakkan cron yang sama dengan scheduler. --stop-when-empty
        // membuatnya mati begitu antrian habis (bukan daemon), --max-time menjamin
        // ia berhenti sebelum menit berikutnya memanggil lagi.
        //
        // Tanpa baris ini seluruh job ShouldQueue — email invoice, refund, slip gaji,
        // invoice layanan — masuk tabel jobs lalu diam selamanya tanpa pesan galat.
        $schedule->command('queue:work --stop-when-empty --max-time=50 --tries=3')
            ->everyMinute()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/queue-work.log'));
```

- [ ] **Step 4: Perjelas dokumentasi konfigurasi**

Di `.env.example`, ganti baris `QUEUE_CONNECTION=sync` jadi:

```
# database = job dikerjakan di latar; MENUNTUT cron `* * * * * php artisan schedule:run`
# hidup, karena worker dijalankan dari scheduler. Tanpa cron itu, seluruh email
# masuk tabel `jobs` dan tidak pernah terkirim.
# sync = job dikerjakan langsung di dalam request; aman tanpa cron, tapi request
# yang mengirim email jadi lambat.
QUEUE_CONNECTION=database
```

- [ ] **Step 5: Jalankan test**

Run: `php artisan test --filter=QueueScheduleTest`
Expected: PASS (2 test).

- [ ] **Step 6: Bereskan duplikat di `.env` dev**

Berkas `.env` tidak masuk git, jadi ini langkah manual, bukan perubahan kode. `.env` dev punya `QUEUE_CONNECTION` **dua kali** — baris 21 (`sync`) dan baris 61 (`database`). Yang belakangan menang, jadi menyunting baris 21 tak berefek dan itu jebakan bagi siapa pun sesudah kita.

Hapus baris 21, sisakan satu-satunya di baris 61. Verifikasi:

```bash
grep -c "^QUEUE_CONNECTION" .env
php artisan config:clear && php artisan tinker --execute="echo config('queue.default');"
```
Expected: `1`, lalu `database`.

- [ ] **Step 7: Commit**

```bash
git add app/Console/Kernel.php .env.example tests/Feature/QueueScheduleTest.php
git -c user.name="WellkitDev" -c user.email="rahmatpurnomo808@gmail.com" commit -m "$(cat <<'EOF'
antrian: queue:work dijalankan scheduler, bukan daemon

Keempat job email implements ShouldQueue dan produksi memakai
QUEUE_CONNECTION=database, tapi tak ada queue:work di mana pun — tidak di
deploy.sh, tidak di scheduler, tidak di cron. Akibatnya email invoice, refund,
slip gaji, dan invoice layanan masuk tabel jobs lalu diam selamanya.

cPanel tak mengizinkan proses permanen, jadi worker dipanggil dari cron yang
sama dengan scheduler: --stop-when-empty agar mati saat antrian habis.

Co-Authored-By: Mira <admin@avidpedia.com>
EOF
)"
```

---

## Task 6: Unggahan gagal tak boleh meninggalkan registrasi tanpa berkas

**Files:**
- Modify: `app/Http/Controllers/Pages/BookIsbnController.php`
- Test: `tests/Feature/BookIsbnValidasiTest.php`

- [ ] **Step 1: Tulis test yang gagal**

```php
    /** @test */
    public function unggahan_gagal_tidak_meninggalkan_registrasi_tanpa_berkas(): void
    {
        // Drive menolak: uploadFile() mengembalikan null → service melempar.
        $this->mock(\App\Services\GoogleDriveService::class, function ($m) {
            $m->shouldReceive('uploadFile')->andReturn(null);
        });

        $book = $this->buku();

        $this->actingAs($this->admin())
            ->post(route('isbn.store'), $this->lengkap($book))
            ->assertSessionHasErrors();

        $this->assertDatabaseMissing('tb_book_isbns', ['title_id' => $book->id]);
    }
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=unggahan_gagal_tidak_meninggalkan_registrasi`
Expected: FAIL — baris `tb_book_isbns` sudah terlanjur dibuat sebelum unggahan dicoba.

- [ ] **Step 3: Balik urutannya**

`simpanBerkas()` saat ini menerima `BookIsbn` hanya untuk mengambil judulnya. Ubah agar menerima `Title` langsung, sehingga bisa dipanggil **sebelum** record ada:

```php
    /**
     * Simpan berkas ISBN yang ikut terkirim bersama formulir. Menerima Title, bukan
     * BookIsbn, supaya bisa dijalankan SEBELUM record ditulis — unggahan yang gagal
     * tak boleh meninggalkan registrasi Cetak/Terbit tanpa berkas.
     *
     * Sengaja tanpa transaksi DB: menahan transaksi terbuka selama panggilan jaringan
     * lambat justru menahan kunci tabel. Baris ManuscriptFile yatim yang mungkin
     * tertinggal tak berbahaya — berkas memang berversi dan menumpuk secara alami.
     */
    private function simpanBerkas(Request $request, Title $title): void
    {
        $svc = app(ManuscriptFileService::class);
        foreach (ManuscriptFile::slotsIsbn() as $slot) {
            if ($request->hasFile($slot)) {
                $svc->upload($title, null, $slot, $request->file($slot), Auth::user());
            }
        }
    }
```

Di `store()`, susun ulang jadi:

```php
        $data = $this->validated($request);
        $this->assertBerkasLengkap($request, null);
        $this->simpanBerkas($request, $title);

        $data['title_id']   = $title->id;
        $data['created_by'] = Auth::id();
        $isbn = BookIsbn::create($data);
        $this->syncManuscript($isbn);
```

Di `update()`:

```php
        $isbn  = BookIsbn::findOrFail($id);
        $title = $isbn->title()->first();
        $data  = $this->validated($request);
        $this->assertBerkasLengkap($request, $isbn);
        if ($title) {
            $this->simpanBerkas($request, $title);
        }
        $isbn->update($data);
        $this->syncManuscript($isbn);
```

Tambahkan import `use App\Models\Title;` bila belum ada.

- [ ] **Step 4: Jalankan test**

Run: `php artisan test --filter=BookIsbnValidasiTest`
Expected: PASS (9 test).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Pages/BookIsbnController.php tests/Feature/BookIsbnValidasiTest.php
git -c user.name="WellkitDev" -c user.email="rahmatpurnomo808@gmail.com" commit -m "$(cat <<'EOF'
isbn: berkas naik sebelum registrasi ditulis

Co-Authored-By: Mira <admin@avidpedia.com>
EOF
)"
```

---

## Task 7: Umpan balik unggahan + formulir tak lagi terbuka karena galat orang lain

**Files:**
- Modify: `resources/views/layouts/master.blade.php` (atau layout yang memuat skrip global — periksa dulu mana yang dipakai `titles/show.blade.php`)
- Modify: `resources/views/titles/show.blade.php`
- Test: `tests/Feature/BookIsbnBerkasTest.php`

- [ ] **Step 1: Tulis test yang gagal**

```php
    /** @test */
    public function formulir_isbn_tidak_terbuka_karena_galat_form_lain(): void
    {
        $book = $this->buku();
        \App\Models\BookIsbn::create(['title_id' => $book->id, 'status' => 'ber_isbn', 'no_isbn' => '978-3']);

        // Galat milik form lain di halaman yang sama.
        $isi = $this->actingAs($this->user('admin'))
            ->withSession(['errors' => new \Illuminate\Support\MessageBag(['catatan_dokumen' => 'wajib'])])
            ->get(route('title.show', $book->id))->assertOk()->getContent();

        $this->assertStringNotContainsString('id="isbnForm" class="collapse show"', $isi);
        $this->assertStringNotContainsString('class="collapse show" id="isbnForm"', $isi);
    }
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=formulir_isbn_tidak_terbuka_karena_galat_form_lain`
Expected: FAIL — collapse terbuka karena `$errors->any()` bereaksi pada galat apa pun.

- [ ] **Step 3: Batasi pemicunya**

Di `titles/show.blade.php`, ganti `{{ $errors->any() ? 'show' : '' }}` pada `#isbnForm` dengan:

```blade
                @php
                    // Hanya galat milik formulir ini yang boleh membukanya — galat form
                    // Cek Kelengkapan Dokumen di halaman yang sama tak ada urusannya.
                    $kolomIsbn = array_merge(
                        ['status', 'no_pendaftaran', 'no_isbn', 'no_buku_cetak', 'penerbit',
                         'tgl_daftar', 'tgl_isbn', 'tgl_terbit', 'link_terbit', 'catatan'],
                        array_keys(\App\Models\ManuscriptFile::BERKAS_ISBN)
                    );
                @endphp
```

dan pakai `{{ $errors->hasAny($kolomIsbn) ? 'show' : '' }}`.

- [ ] **Step 4: Kunci tombol unggah seluruh aplikasi**

Cari dulu layout yang benar-benar dipakai halaman-halaman ini:

```bash
head -3 resources/views/titles/show.blade.php resources/views/isbn/index.blade.php resources/views/naskah/detail.blade.php
```

Di layout itu, tambahkan skrip global (bukan per-halaman — form unggah tersebar di ISBN, naskah, bab, pembayaran, laporan harian, profil):

```javascript
<script>
// Unggahan berjalan sinkron: berkas 20 MB berarti request menggantung beberapa detik.
// Tanpa penanda, halaman terlihat mati dan orang menekan tombolnya berkali-kali —
// yang berarti berkas terunggah berganda. Satu penangan terdelegasi menutup keduanya
// untuk SELURUH form unggah di aplikasi, bukan cuma yang kebetulan diingat.
document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement) || form.enctype !== 'multipart/form-data') return;
    var tombol = form.querySelector('button[type="submit"], button:not([type])');
    if (!tombol || tombol.dataset.mengunggah) return;
    tombol.dataset.mengunggah = '1';
    tombol.disabled = true;
    tombol.dataset.teksAsli = tombol.innerHTML;
    tombol.innerHTML = 'Mengunggah…';
}, true);
</script>
```

- [ ] **Step 5: Jalankan test + regresi**

Run: `php artisan test --filter=BookIsbn` lalu `php artisan test --filter=Title`
Expected: keduanya hijau.

- [ ] **Step 6: Commit**

```bash
git add resources/views/titles/show.blade.php resources/views/layouts/ tests/Feature/BookIsbnBerkasTest.php
git -c user.name="WellkitDev" -c user.email="rahmatpurnomo808@gmail.com" commit -m "$(cat <<'EOF'
unggah: tombol terkunci saat mengunggah + formulir ISBN hanya bereaksi pada galatnya sendiri

Co-Authored-By: Mira <admin@avidpedia.com>
EOF
)"
```

---

## Task 8: Verifikasi akhir

- [ ] **Step 1: Suite penuh**

Run: `php artisan test`
Expected: hijau seluruhnya. Baseline sebelum pekerjaan ini: 1057 lolos, 1 dilewati.

- [ ] **Step 2: Buktikan angka performanya berubah**

```bash
php artisan tinker "C:/Users/rahma/AppData/Local/Temp/claude/c--xampp-htdocs-simapa-v2-skeleton-simapa-avid-simapa-avidpedia-com/351baa8a-ce1c-4fef-989a-1006b150673c/scratchpad/ukur-controller.php"
```
Expected: keenam controller di bawah 10 ms (sebelumnya 210–263 ms). Laporkan tabel sesungguhnya, bukan klaim.

- [ ] **Step 3: Buktikan antrian hidup di dev**

```bash
php artisan tinker --execute="dispatch(function () { \Illuminate\Support\Facades\Log::info('bukti antrian jalan'); }); echo 'menunggu: ' . \Illuminate\Support\Facades\DB::table('jobs')->count() . PHP_EOL;"
php artisan queue:work --stop-when-empty
php artisan tinker --execute="echo 'sisa: ' . \Illuminate\Support\Facades\DB::table('jobs')->count() . PHP_EOL;"
```
Expected: `menunggu: 1` lalu `sisa: 0`, dan barisnya muncul di `storage/logs/laravel.log`.

- [ ] **Step 4: Catat penyimpangan**

Isi bagian di bawah, lalu commit.

---

## Catatan penyimpangan

Selesai 16 Agustus 2026 di branch `feat/isbn-berkas-dan-bab-mandiri`. Suite penuh:
**1071 lolos, 1 dilewati, nol gagal**. Commit: `22e64c2` (T1) · `e170b15` (T2) ·
`6e69816` (T3) · `85ba0ff` (T4) · `170b4ce` (T5) · `9b27f05` (T6) · `48a4ce9` (T7) ·
`59dac55` (perbaikan susulan). Belum di-push, belum di-merge.

**1. Hasil terukur Task 4 — ini angka intinya.** Resolusi controller sesudah perbaikan:

| Controller | Sebelum | Sesudah |
|---|---|---|
| OrderBookController | 263 ms | **1 ms** |
| PaymentBookController | 210 ms | **0 ms** |
| ProfileController | 223 ms | **0 ms** |
| ManagementUserController | 233 ms | **0 ms** |
| JournalSubmissionController | 216 ms | **0 ms** |
| DailyReportController | 221 ms | **0 ms** |

Sekitar 1,37 detik waktu mati hilang dari keenam halaman itu.

**2. Test antrian sempat merusak test lain — ditemukan hanya karena suite dijalankan
penuh.** `Artisan::call('queue:work', ['--quiet' => true])` menyetel verbosity QUIET pada
objek output konsol yang dipakai bersama seluruh suite, dan setelan itu bertahan ke test
berikutnya. `StripCodePrefixCommandTest` (dua test, sama sekali tak berkaitan) jadi gagal
di suite penuh tapi hijau saat difilter sendiri — `expectsOutputToContain()` tak lagi
melihat keluaran apa pun. Diperbaiki dengan membuang `--quiet` (`59dac55`), dan alasannya
ditulis sebagai komentar di test supaya tak dipasang lagi oleh orang berikutnya.
**Pelajaran: menjalankan suite terfilter saja akan meloloskan kelas bug ini.**

**3. Bukti antrian di dev lewat tinker gagal — artefak, bukan masalah.** `dispatch(closure)`
dari tinker menghasilkan `Error: Call to a member function bindTo() on null`, karena
`serializable-closure` tak bisa membangun ulang closure yang lahir dari kode `eval`. Yang
penting tetap terbukti: job masuk tabel `jobs`, worker mengambilnya, antrian terkuras
sampai nol. Bukti sesungguhnya ada di `QueueScheduleTest::job_yang_diantrikan_benar_benar_dieksekusi`,
yang dispatch dari berkas nyata dan memeriksa efek sampingnya — hijau. Baris `failed_jobs`
sisa uji coba sudah dibersihkan (`queue:flush`).

**4. Test collapse ditulis sesudah perbaikan, lalu dibuktikan terbalik.** Untuk Revisi E
saya sempat menulis test setelah kodenya diperbaiki, jadi belum pernah melihatnya merah —
itu tak membuktikan apa-apa. Kondisi lama (`$errors->any()`) dikembalikan sementara untuk
memastikan test-nya benar-benar gagal, baru perbaikannya dipasang lagi.

**5. `.env` dev dibereskan manual** (berkas ini tak masuk git): baris `QUEUE_CONNECTION=sync`
yang mati di baris 21 dihapus, menyisakan satu-satunya `=database`. Nilai efektifnya tidak
berubah — yang hilang cuma jebakannya. Diverifikasi `grep -c` = 1 dan
`config('queue.default')` = `database`.

**6. Utang yang sengaja tidak dibayar:** `GoogleDriveService::uploadFile()` masih memakai
`file_get_contents()` dan `uploadType: 'multipart'`, jadi berkas 20 MB tetap masuk memori
PHP seluruhnya dan seluruh transfer terjadi di dalam request. Unggahan juga tetap sinkron,
bukan job. Keduanya keputusan sadar (spec §10), bukan kelalaian.

**BELUM DIKERJAKAN — perlu Anda:** pemeriksaan lewat peramban (tak seorang pun membuka
formulir baru atau mengunggah berkas sungguhan), dan **konfirmasi bahwa cron
`* * * * * php artisan schedule:run` benar-benar terpasang di cPanel produksi**. Tanpa cron
itu Revisi C tak berefek: email tetap tertahan, dan `naskah:check-overdue` yang dijadwalkan
sejak modul naskah juga tak pernah berjalan sejak awal.
