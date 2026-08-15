# Berkas ISBN & Alur Bab Naskah Mandiri — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Memberi bab bernaskah author jalan keluar ke Selesai (sehingga buku kolaborasi tidak lagi tertahan di gerbang Layout), dan melengkapi Direktori ISBN dengan e-book, sertifikat ISBN, serta link terbit yang bisa diunduh marketing.

**Architecture:** Empat perubahan terpisah di atas kode yang sudah ada. Revisi A menambah satu aturan tahap pada `ChapterProgress`/`TitleProgressService` dan membongkar rantai `@elseif` di tabel bab. Revisi B menumpangkan berkas ISBN pada `tb_manuscript_files` lewat dua slot baru, dengan satu kolom baru `link_terbit`. Revisi C memperketat validasi `BookIsbnController`. Revisi D menambah satu penyaring pada antrian Meja Kerja.

**Tech Stack:** Laravel 10, Blade, MySQL/MariaDB, PHPUnit, Google Drive (lewat `GoogleDriveService` yang di-mock di test), Bootstrap 5.

**Spec:** `docs/superpowers/specs/2026-08-16-isbn-berkas-dan-bab-mandiri-design.md`

---

## Struktur berkas

| Berkas | Peran | Tugas |
|---|---|---|
| `app/Models/ChapterProgress.php` | aturan tahap bab; bab mandiri melompati Pembuatan | 1 |
| `app/Services/TitleProgressService.php` | pemicu maju otomatis saat unggah | 2 |
| `resources/views/naskah/partials/bab-table.blade.php` | sel Aksi per bab | 3 |
| `app/Http/Controllers/Pages/Naskah/MejaKerjaController.php` | antrian pelaksana | 4 |
| `database/migrations/2026_08_16_000001_add_link_terbit_to_tb_book_isbns.php` | kolom `link_terbit` | 5 |
| `app/Models/ManuscriptFile.php` | slot `ebook` & `sertifikat_isbn` | 5 |
| `app/Models/BookIsbn.php` | `link_terbit` fillable + helper `berkas()` | 5 |
| `app/Http/Controllers/Pages/BookIsbnController.php` | unggah berkas, validasi, data direktori | 6, 7, 8 |
| `resources/views/titles/show.blade.php` | formulir Kelola registrasi ISBN | 6 |
| `resources/views/isbn/index.blade.php` | tabel Direktori ISBN | 7 |
| `tests/Feature/NaskahBabMandiriTest.php` | Revisi A + D | 1–4 |
| `tests/Feature/BookIsbnBerkasTest.php` | Revisi B | 5–7 |
| `tests/Feature/BookIsbnValidasiTest.php` | Revisi C | 8 |

**Catatan lingkungan:** test berjalan di basis data `avidpedi_simapa_test` lewat `.env.testing`. Jangan pernah mengarahkannya ke `avidpedi_simapa`. `GoogleDriveService` selalu di-mock di test; tanpa itu test akan mencoba menembak Drive sungguhan.

---

## Task 1: Bab bernaskah mandiri melompati tahap Pembuatan

**Files:**
- Modify: `app/Models/ChapterProgress.php:68-76`
- Test: `tests/Feature/NaskahBabMandiriTest.php` (buat baru)

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/NaskahBabMandiriTest.php`:

```php
<?php
// tests/Feature/NaskahBabMandiriTest.php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\ChapterProgress;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\ChapterAuthorService;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Bab bernaskah mandiri: naskahnya dikirim authornya sendiri, jadi tak pernah punya
 * pelaksana dan tahap Pembuatan tak punya makna untuknya. Sebelum perbaikan ini bab
 * seperti itu terkunci selamanya di status awalnya, dan satu bab macet menahan
 * seluruh buku di gerbang Layout.
 */
class NaskahBabMandiriTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        $this->mock(GoogleDriveService::class, function ($m) {
            $m->shouldReceive('uploadFile')->andReturn(['id' => 'drive-1', 'url' => 'https://drive/1']);
        });
    }

    private function user(string $role, ?string $bidang = null): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        if ($bidang !== null) {
            $u->profile()->create(['bidang' => $bidang]);
        }

        return $u->fresh();
    }

    /**
     * Buku kolaborasi dengan SATU order per bab — bentuk data yang sebenarnya:
     * `order_details.chapters` menyimpan NOMOR bab, dan `naskah_type` order itulah
     * yang menentukan asal naskah bab tersebut.
     *
     * @param array<int,array{0:string,1:string}> $babs nomor bab => [naskah_type, status bab]
     */
    private function buku(array $babs, string $bukuStatus = 'pembuatan'): Title
    {
        $book = Title::create([
            'title'       => 'Kolab ' . fake()->unique()->word(),
            'jenis'       => 'buku',
            'tipe_naskah' => 'kolaborasi',
            'status'      => 'disetujui',
        ]);

        foreach ($babs as $nomor => [$jenisNaskah, $statusBab]) {
            $order  = Order::factory()->create(['user_id' => $this->user('marketing')->id]);
            $detail = OrderDetail::factory()->create([
                'order_id'    => $order->id,
                'type'        => 'bk_kolab',
                'title'       => $book->title,
                'title_id'    => $book->id,
                'chapters'    => $nomor,
                'naskah_type' => $jenisNaskah,
            ]);
            $detail->authors()->attach(
                Author::create(['name' => 'Penulis ' . $nomor])->id,
                ['position' => 1]
            );
            TitleProgress::create([
                'order_detail_id' => $detail->id,
                'status'          => $bukuStatus,
                'assigned_role'   => TitleProgress::getHandlerForStatus($bukuStatus),
                'bidang'          => 'buku',
                'started_at'      => now(),
            ]);

            $bab = $book->chapters()->create(['judul' => 'Bab ' . $nomor, 'urutan' => $nomor]);
            $bab->progress()->create(['status' => $statusBab, 'started_at' => now()]);
        }

        app(ChapterAuthorService::class)->seedFromOrders($book);

        return $book->fresh();
    }

    private function bab(Title $book, int $urutan): ChapterProgress
    {
        return $book->chapters()->where('urutan', $urutan)->first()->progress;
    }

    private function detailBab(Title $book, int $urutan): OrderDetail
    {
        return $book->orderDetails()->where('chapters', $urutan)->first();
    }

    /** @test */
    public function bab_mandiri_melompati_tahap_pembuatan(): void
    {
        $book = $this->buku([1 => ['mandiri', 'menunggu'], 2 => ['dibuatkan', 'menunggu']]);

        $this->assertSame('editing', $this->bab($book, 1)->nextStage());
        $this->assertSame('pembuatan', $this->bab($book, 2)->nextStage(), 'Bab dibuatkan tidak boleh ikut melompat.');
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=bab_mandiri_melompati_tahap_pembuatan`
Expected: FAIL — `Failed asserting that two strings are equal` (`'pembuatan'` diterima, `'editing'` diharapkan).

- [ ] **Step 3: Implementasi**

Ganti `nextStage()` di `app/Models/ChapterProgress.php`:

```php
    public function nextStage(): ?string
    {
        // Bab bernaskah mandiri melewati tahap Pembuatan: naskahnya sudah dikirim
        // authornya, jadi tak ada yang perlu "dibuat" dan tak akan pernah ada
        // pelaksana. Aturan ini menyalin cabang 'menunggu_proses' pada
        // TitleProgressService::autoAdvanceOnUpload() di tingkat judul.
        if ($this->status === 'menunggu' && $this->naskahDariAuthor()) {
            return 'editing';
        }

        $idx = array_search($this->status, self::CHAPTER_STAGES, true);
        if ($idx === false || ! isset(self::CHAPTER_STAGES[$idx + 1])) {
            return null;
        }

        return self::CHAPTER_STAGES[$idx + 1];
    }
```

- [ ] **Step 4: Jalankan test, pastikan lolos**

Run: `php artisan test --filter=bab_mandiri_melompati_tahap_pembuatan`
Expected: PASS (1 test, 2 assertions).

- [ ] **Step 5: Commit**

```bash
git add app/Models/ChapterProgress.php tests/Feature/NaskahBabMandiriTest.php
git commit -m "naskah: bab bernaskah mandiri melompati tahap Pembuatan"
```

---

## Task 2: Unggahan naskah author memajukan bab ke Editing

**Files:**
- Modify: `app/Services/TitleProgressService.php:130-144`
- Test: `tests/Feature/NaskahBabMandiriTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Tambahkan ke `tests/Feature/NaskahBabMandiriTest.php`:

```php
    /** @test */
    public function unggah_naskah_author_memajukan_bab_mandiri_ke_editing(): void
    {
        $book = $this->buku([1 => ['mandiri', 'menunggu'], 2 => ['dibuatkan', 'menunggu']]);
        $cp   = $this->bab($book, 1);

        // Admin bukan pelaksana bab ini — dan memang tak akan pernah ada pelaksananya.
        $this->actingAs($this->user('admin', 'buku'))
            ->post(route('naskah.bab.file', $cp->id), [
                'slot' => 'masuk',
                'file' => UploadedFile::fake()->create('bab1.pdf', 100, 'application/pdf'),
            ])->assertRedirect();

        $this->assertSame('editing', $cp->fresh()->status);
        $this->assertSame('menunggu', $this->bab($book, 2)->fresh()->status,
            'Bab dibuatkan tetap butuh pelaksana; tidak boleh ikut maju.');
    }
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=unggah_naskah_author_memajukan_bab_mandiri_ke_editing`
Expected: FAIL — `'menunggu'` diterima, `'editing'` diharapkan.

- [ ] **Step 3: Implementasi**

Ganti `autoAdvanceChapterOnUpload()` di `app/Services/TitleProgressService.php`:

```php
    /**
     * Bab maju otomatis saat naskahnya masuk. Dua pemicu:
     *  - tahap `pembuatan` + diunggah pelaksananya → `editing` (bukti kerja selesai);
     *  - tahap `menunggu` + bab bernaskah mandiri → `editing`, pengunggah siapa pun,
     *    karena naskahnya datang dari author dan bab itu tak akan pernah punya
     *    pelaksana. Sejalan dengan cabang `menunggu_proses` di tingkat judul.
     */
    public function autoAdvanceChapterOnUpload(ChapterProgress $chapter, User $uploader, string $slot): bool
    {
        if ($slot !== 'masuk') {
            return false;
        }

        if ($chapter->status === 'menunggu' && $chapter->naskahDariAuthor()) {
            $this->applyChapterStatus($chapter, 'editing', $uploader, null, 'auto_advance_upload');

            return true;
        }

        if ($chapter->status !== 'pembuatan') {
            return false;
        }

        if ((int) $chapter->pelaksana_user_id !== (int) $uploader->id) {
            return false;
        }

        $this->applyChapterStatus($chapter, 'editing', $uploader, null, 'auto_advance_upload');

        return true;
    }
```

- [ ] **Step 4: Jalankan test, pastikan lolos**

Run: `php artisan test --filter=NaskahBabMandiriTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/TitleProgressService.php tests/Feature/NaskahBabMandiriTest.php
git commit -m "naskah: unggahan naskah author memajukan bab mandiri ke Editing"
```

---

## Task 3: Baris bab mandiri menawarkan unggahan DAN tombol maju

**Files:**
- Modify: `resources/views/naskah/partials/bab-table.blade.php:113-120` (blok `@php`) dan `:172-186` (dua cabang mandiri)
- Test: `tests/Feature/NaskahBabMandiriTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Tambahkan dua test ke `tests/Feature/NaskahBabMandiriTest.php`:

```php
    /** @test */
    public function baris_bab_mandiri_menawarkan_unggahan_dan_tombol_maju(): void
    {
        $book = $this->buku([1 => ['mandiri', 'editing'], 2 => ['dibuatkan', 'menunggu']], bukuStatus: 'editing');

        $isi = $this->actingAs($this->user('admin', 'buku'))
            ->get(route('naskah.show', $this->detailBab($book, 1)->id))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Naskah dari Author', $isi);
        $this->assertStringContainsString('Selesaikan Bab', $isi);
    }

    /** @test */
    public function bab_mandiri_selesai_membuka_gerbang_layout(): void
    {
        $book   = $this->buku([1 => ['mandiri', 'editing'], 2 => ['dibuatkan', 'selesai']], bukuStatus: 'editing');
        $admin  = $this->user('admin', 'buku');
        $cp     = $this->bab($book, 1);
        $detail = $this->detailBab($book, 1);

        $this->actingAs($admin)->post(route('naskah.bab.selesaikan', $cp->id))->assertRedirect();
        $this->assertSame('selesai', $cp->fresh()->status);

        // Semua bab selesai → gerbang assertLayoutUnlocked() terbuka.
        $this->actingAs($admin)->post(route('naskah.selesaikan', $detail->id))->assertRedirect();
        $this->assertSame('layout', $detail->titleProgress->fresh()->status);
    }
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=baris_bab_mandiri_menawarkan_unggahan_dan_tombol_maju`
Expected: FAIL — `Failed asserting that '…' contains "Selesaikan Bab"`. Tombolnya memang belum pernah dirender untuk bab mandiri.

- [ ] **Step 3: Implementasi — hitung tahap tujuan sekali per baris**

Di `resources/views/naskah/partials/bab-table.blade.php`, ganti blok `@php` di dalam `@foreach ($bab as $b)`:

```blade
                    @php
                        $cp        = $b->progress;
                        $adaAuthor = $b->authors->isNotEmpty();
                        // Asal naskah bab ini, dari order yang memesannya (kolom
                        // order_details.chapters = nomor bab pada buku kolaborasi).
                        $sumber    = $cp?->sumberNaskah();
                        // Tahap tujuan tombol maju — dihitung sekali supaya labelnya
                        // jujur (bab mandiri di 'menunggu' menuju Editing, bukan Selesai).
                        $majuKe    = $cp?->nextStage();
                    @endphp
```

- [ ] **Step 4: Implementasi — gabungkan dua cabang mandiri jadi satu**

Ganti dua cabang mandiri (yang sekarang berbunyi `@elseif ($sumber === 'mandiri' && $cp->status !== 'selesai' && $izin['upload'])` sampai `@elseif ($sumber === 'mandiri')` beserta isinya) dengan satu cabang berikut:

```blade
                            @elseif ($sumber === 'mandiri' && $cp->status !== 'selesai')
                                {{-- Bab bernaskah mandiri: naskahnya datang dari author, jadi
                                     yang dibutuhkan unggahan — bukan pelaksana. Unggahan dan
                                     tombol maju TIDAK saling meniadakan: rantai @elseif yang
                                     lama menelan tombol maju, sehingga bab mandiri tak punya
                                     jalan ke Selesai sama sekali. --}}
                                @if ($izin['upload'])
                                    <form method="POST" action="{{ route('naskah.bab.file', $cp->id) }}"
                                          enctype="multipart/form-data" class="d-flex gap-1">
                                        @csrf
                                        <input type="hidden" name="slot" value="masuk">
                                        <input type="file" name="file" class="form-control form-control-sm"
                                               accept=".pdf,.doc,.docx,.zip" required>
                                        <button class="btn btn-sm btn-primary text-nowrap">⬆ Naskah dari Author</button>
                                    </form>
                                @endif
                                @if ($izin['advance'] && $majuKe)
                                    <form method="POST" action="{{ route('naskah.bab.selesaikan', $cp->id) }}" class="mt-1">
                                        @csrf
                                        <button class="btn btn-sm {{ $majuKe === 'selesai' ? 'btn-primary' : 'btn-outline-primary' }} text-nowrap">
                                            {{ $majuKe === 'selesai' ? '✓ Selesaikan Bab' : '→ Naskah sudah ada, mulai Editing' }}
                                        </button>
                                    </form>
                                @endif
                                @if (! $izin['upload'] && ! $izin['advance'])
                                    <span class="text-muted small">Menunggu naskah dari author</span>
                                @endif
```

Bab mandiri yang sudah `selesai` kini jatuh ke cabang-cabang umum di bawahnya, sehingga baris itu menawarkan unggahan berkas bab seperti bab lain — bukan lagi teks mati "Menunggu naskah dari author" yang salah untuk bab yang sudah rampung.

- [ ] **Step 5: Jalankan test, pastikan lolos**

Run: `php artisan test --filter=NaskahBabMandiriTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add resources/views/naskah/partials/bab-table.blade.php tests/Feature/NaskahBabMandiriTest.php
git commit -m "naskah: baris bab mandiri menawarkan unggahan dan tombol maju sekaligus"
```

---

## Task 4: Antrian produksi berhenti menawarkan bab mandiri

**Files:**
- Modify: `app/Http/Controllers/Pages/Naskah/MejaKerjaController.php:86-103`
- Test: `tests/Feature/NaskahBabMandiriTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Tambahkan ke `tests/Feature/NaskahBabMandiriTest.php`:

```php
    /**
     * Antrian Meja Kerja menawarkan pekerjaan yang bisa DIAMBIL. Bab bernaskah mandiri
     * selalu ditolak AssignmentService::assertChapterButuhPelaksana(), jadi menampilkannya
     * hanya membuat produksi mengklik lalu menerima pesan merah.
     *
     * @test
     */
    public function antrian_produksi_tidak_menawarkan_bab_mandiri(): void
    {
        $book = $this->buku([1 => ['mandiri', 'menunggu'], 2 => ['dibuatkan', 'menunggu']]);

        $isi = $this->actingAs($this->user('production'))
            ->get(route('naskah.workdesk'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Bab 1', $isi, 'Bab mandiri tak boleh ditawarkan.');
        $this->assertStringContainsString('Bab 2', $isi, 'Bab dibuatkan tetap harus muncul.');
    }
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=antrian_produksi_tidak_menawarkan_bab_mandiri`
Expected: FAIL — `Failed asserting that '…' does not contain "Bab 1"`.

- [ ] **Step 3: Implementasi**

Ganti method `antrian()` di `app/Http/Controllers/Pages/Naskah/MejaKerjaController.php`:

```php
    /**
     * Antrian belum ditugaskan — model campuran: admin boleh assign, produksi boleh
     * ambil sendiri. Dua jenis bab sengaja TIDAK muncul di sini karena keduanya memang
     * tak boleh didistribusikan: bab yang author-nya belum dipetakan, dan bab bernaskah
     * mandiri (naskahnya dikirim authornya, jadi tak butuh pelaksana).
     */
    private function antrian(): Collection
    {
        $judul = TitleProgress::with(['orderDetail.order', 'orderDetail.authors', 'pj'])
            ->active()
            ->whereNull('pelaksana_user_id')
            ->whereIn('status', TitleProgress::QUEUE_STAGES)
            ->get()
            ->map(fn (TitleProgress $p) => ['jenis' => 'judul', 'model' => $p]);

        // orderDetails ikut dimuat karena naskahDariAuthor() menelusuri bab → judul →
        // order pemesan bab. Tanpa eager load ini, tiap bab di antrian = query baru.
        $bab = ChapterProgress::with([
                'chapter.title.orderDetails.order',
                'chapter.title.orderDetails',
                'chapter.authors',
            ])
            ->whereNull('pelaksana_user_id')
            ->whereIn('status', ['menunggu', 'pembuatan'])
            ->get()
            ->filter(fn (ChapterProgress $c) => $c->chapter?->authors->isNotEmpty())
            ->filter(fn (ChapterProgress $c) => ! $c->naskahDariAuthor())
            ->map(fn (ChapterProgress $c) => ['jenis' => 'bab', 'model' => $c]);

        return $judul->merge($bab)->values();
    }
```

- [ ] **Step 4: Jalankan test, pastikan lolos**

Run: `php artisan test --filter=NaskahBabMandiriTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Jalankan seluruh test modul naskah — penjaga regresi**

Run: `php artisan test --filter=Naskah`
Expected: PASS. Jika `NaskahDetailTest::tabel_bab_menyebut_asal_naskah_sesuai_ordernya` gagal pada assertion `Naskah dari Author`, periksa bahwa cabang mandiri baru tetap merender teks itu untuk role admin.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Pages/Naskah/MejaKerjaController.php tests/Feature/NaskahBabMandiriTest.php
git commit -m "naskah: antrian produksi berhenti menawarkan bab bernaskah mandiri"
```

---

## Task 5: Fondasi data ISBN — kolom link_terbit & slot berkas

**Files:**
- Create: `database/migrations/2026_08_16_000001_add_link_terbit_to_tb_book_isbns.php`
- Modify: `app/Models/ManuscriptFile.php:18-43`
- Modify: `app/Models/BookIsbn.php:11-14`
- Test: `tests/Feature/BookIsbnBerkasTest.php` (buat baru)

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/BookIsbnBerkasTest.php`:

```php
<?php
// tests/Feature/BookIsbnBerkasTest.php

namespace Tests\Feature;

use App\Models\BookIsbn;
use App\Models\ManuscriptFile;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Berkas ISBN (e-book & sertifikat) menumpang tb_manuscript_files lewat slot khusus,
 * supaya versi, pengunggah, dan tautan Drive datang gratis. Yang dijaga di sini:
 * slot itu TIDAK bocor ke kartu berkas halaman Detail Naskah, dan tidak menggerakkan
 * tahap naskah.
 */
class BookIsbnBerkasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        $this->mock(GoogleDriveService::class, function ($m) {
            $m->shouldReceive('uploadFile')->andReturn(['id' => 'drive-9', 'url' => 'https://drive/9']);
        });
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u->fresh();
    }

    /** Buku yang manuskripnya sudah di tahap ISBN, jadi isbnEligible() true. */
    private function buku(string $status = 'isbn'): Title
    {
        $book = Title::create([
            'title'       => 'Buku ISBN ' . fake()->unique()->word(),
            'jenis'       => 'buku',
            'tipe_naskah' => 'mandiri',
            'status'      => 'disetujui',
        ]);
        $detail = OrderDetail::factory()->create([
            'type' => 'bk_mandiri', 'title' => $book->title, 'title_id' => $book->id, 'chapters' => 1,
        ]);
        TitleProgress::create([
            'order_detail_id' => $detail->id,
            'status'          => $status,
            'assigned_role'   => TitleProgress::getHandlerForStatus($status),
            'bidang'          => 'buku',
            'started_at'      => now(),
        ]);

        return $book->fresh();
    }

    /** @test */
    public function slot_isbn_terdaftar_tapi_tidak_bocor_ke_berkas_naskah(): void
    {
        $this->assertSame(
            ['ebook', 'sertifikat_isbn'],
            ManuscriptFile::SLOTS_ISBN
        );
        $this->assertArrayHasKey('ebook', ManuscriptFile::SLOTS);
        $this->assertArrayHasKey('sertifikat_isbn', ManuscriptFile::SLOTS);

        // Kartu berkas Detail Naskah hanya menampilkan slotsFor(); slot ISBN tak boleh ikut.
        $this->assertArrayNotHasKey('ebook', ManuscriptFile::slotsFor(true));
        $this->assertArrayNotHasKey('sertifikat_isbn', ManuscriptFile::slotsFor(true));
        $this->assertArrayNotHasKey('ebook', ManuscriptFile::slotsFor(false));
    }

    /** @test */
    public function berkas_isbn_mengambil_versi_terbaru_per_slot(): void
    {
        $book = $this->buku();
        $isbn = BookIsbn::create([
            'title_id' => $book->id, 'status' => 'ber_isbn', 'no_isbn' => '978-1',
            'link_terbit' => 'https://avidpedia.com/buku-1',
        ]);

        foreach ([1, 2] as $versi) {
            ManuscriptFile::create([
                'title_id' => $book->id, 'title_chapter_id' => null, 'slot' => 'ebook',
                'version' => $versi, 'original_name' => "ebook-v{$versi}.pdf",
                'drive_url' => "https://drive/e{$versi}", 'uploaded_by' => $this->user('admin')->id,
            ]);
        }

        $this->assertSame('ebook-v2.pdf', $isbn->berkas('ebook')?->original_name);
        $this->assertNull($isbn->berkas('sertifikat_isbn'));
        $this->assertSame('https://avidpedia.com/buku-1', $isbn->fresh()->link_terbit);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=BookIsbnBerkasTest`
Expected: FAIL — `Undefined constant App\Models\ManuscriptFile::SLOTS_ISBN`.

- [ ] **Step 3: Buat migrasi**

Buat `database/migrations/2026_08_16_000001_add_link_terbit_to_tb_book_isbns.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link terbit di web avidpedia — dipakai marketing untuk mengabari klien dan
 * ditampilkan langsung di Direktori ISBN. E-book & sertifikat TIDAK butuh kolom:
 * keduanya menumpang tb_manuscript_files lewat slot `ebook` / `sertifikat_isbn`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_book_isbns', function (Blueprint $table) {
            $table->string('link_terbit', 500)->nullable()->after('catatan');
        });
    }

    public function down(): void
    {
        Schema::table('tb_book_isbns', function (Blueprint $table) {
            $table->dropColumn('link_terbit');
        });
    }
};
```

- [ ] **Step 4: Tambah slot ISBN pada ManuscriptFile**

Di `app/Models/ManuscriptFile.php`, tambahkan dua entri ke `SLOTS` dan satu konstanta baru:

```php
    public const SLOTS = [
        'masuk'           => 'Naskah Masuk',
        'hasil_editing'   => 'Hasil Editing',
        'hasil_layout'    => 'Hasil Layout',
        'hasil_proofread' => 'Hasil Proofread',
        'cover'           => 'Cover',
        'loa'             => 'LoA (Letter of Acceptance)',
        'final'           => 'Naskah Final',
        'ebook'           => 'E-book',
        'sertifikat_isbn' => 'Sertifikat ISBN',
    ];
```

Lalu tepat di bawah `SLOTS_BUKU`:

```php
    /**
     * Slot milik registrasi ISBN, bukan tahap naskah. Sengaja TIDAK masuk SLOTS_BUKU
     * maupun SLOTS_ARTIKEL supaya tidak muncul di kartu berkas Detail Naskah — tempatnya
     * di formulir Kelola ISBN dan Direktori ISBN.
     */
    public const SLOTS_ISBN = ['ebook', 'sertifikat_isbn'];
```

- [ ] **Step 5: Tambah link_terbit & helper berkas() pada BookIsbn**

Di `app/Models/BookIsbn.php`, tambahkan `link_terbit` ke `$fillable`:

```php
    protected $fillable = [
        'title_id', 'status', 'no_pendaftaran', 'no_isbn', 'no_buku_cetak',
        'penerbit', 'tgl_daftar', 'tgl_isbn', 'tgl_terbit', 'catatan', 'link_terbit', 'created_by',
    ];
```

dan method berikut sebelum `title()`:

```php
    /**
     * Berkas ISBN versi terbaru untuk satu slot (`ebook` | `sertifikat_isbn`).
     *
     * Satu query per panggilan — pakai ini di halaman detail saja. Direktori ISBN
     * memuat berkas seluruh baris dalam SATU query di controller; jangan panggil
     * method ini di dalam perulangan.
     */
    public function berkas(string $slot): ?ManuscriptFile
    {
        return ManuscriptFile::where('title_id', $this->title_id)
            ->whereNull('title_chapter_id')
            ->where('slot', $slot)
            ->orderByDesc('version')
            ->first();
    }
```

- [ ] **Step 6: Jalankan test, pastikan lolos**

Run: `php artisan test --filter=BookIsbnBerkasTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_16_000001_add_link_terbit_to_tb_book_isbns.php app/Models/ManuscriptFile.php app/Models/BookIsbn.php tests/Feature/BookIsbnBerkasTest.php
git commit -m "isbn: kolom link_terbit + slot berkas ebook & sertifikat"
```

---

## Task 6: Unggah e-book, sertifikat & link terbit di formulir Kelola

**Files:**
- Modify: `app/Http/Controllers/Pages/BookIsbnController.php`
- Modify: `resources/views/titles/show.blade.php:298-326` (formulir) dan `:547-564` (skrip)
- Test: `tests/Feature/BookIsbnBerkasTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Tambahkan ke `tests/Feature/BookIsbnBerkasTest.php`:

```php
    /** @test */
    public function unggahan_tersimpan_sebagai_berkas_isbn_dan_tak_menggerakkan_tahap(): void
    {
        $book   = $this->buku();
        $detail = $book->orderDetails()->first();

        $this->actingAs($this->user('admin'))
            ->post(route('isbn.store'), [
                'title_id'        => $book->id,
                'status'          => 'ber_isbn',
                'no_isbn'         => '978-602-1234-56-7',
                'link_terbit'     => 'https://avidpedia.com/buku-uji',
                'ebook'           => UploadedFile::fake()->create('buku.pdf', 200, 'application/pdf'),
                'sertifikat_isbn' => UploadedFile::fake()->create('sertifikat.pdf', 50, 'application/pdf'),
            ])->assertRedirect();

        $isbn = BookIsbn::where('title_id', $book->id)->firstOrFail();
        $this->assertSame('buku.pdf', $isbn->berkas('ebook')?->original_name);
        $this->assertSame('sertifikat.pdf', $isbn->berkas('sertifikat_isbn')?->original_name);
        $this->assertSame('https://avidpedia.com/buku-uji', $isbn->link_terbit);
    }

    /**
     * Berkas ISBN bukan slot `masuk`, jadi tak boleh memicu maju tahap. Diuji lewat
     * service langsung, BUKAN lewat controller: menyimpan registrasi ISBN memang
     * memajukan tahap naskah lewat syncManuscript(), dan itu perilaku lama yang
     * tidak sedang diubah — mencampur keduanya akan menguji hal yang salah.
     *
     * @test
     */
    public function berkas_isbn_tidak_menggerakkan_tahap_naskah(): void
    {
        $book   = $this->buku('editing');
        $detail = $book->orderDetails()->first();

        app(\App\Services\ManuscriptFileService::class)->upload(
            $book,
            null,
            'ebook',
            UploadedFile::fake()->create('ebook.pdf', 10, 'application/pdf'),
            $this->user('admin')
        );

        $this->assertSame('editing', $detail->titleProgress->fresh()->status);
    }

    /** @test */
    public function unggah_ulang_menaikkan_versi_bukan_menimpa(): void
    {
        $book = $this->buku();
        $admin = $this->user('admin');

        $this->actingAs($admin)->post(route('isbn.store'), [
            'title_id' => $book->id, 'status' => 'ber_isbn', 'no_isbn' => '978-1',
            'ebook' => UploadedFile::fake()->create('v1.pdf', 10, 'application/pdf'),
        ])->assertRedirect();

        $isbn = BookIsbn::where('title_id', $book->id)->firstOrFail();

        $this->actingAs($admin)->put(route('isbn.update', $isbn->id), [
            'status' => 'ber_isbn', 'no_isbn' => '978-1',
            'ebook' => UploadedFile::fake()->create('v2.pdf', 10, 'application/pdf'),
        ])->assertRedirect();

        $this->assertSame(2, $isbn->berkas('ebook')?->version);
        $this->assertSame('v2.pdf', $isbn->berkas('ebook')?->original_name);
        $this->assertSame(2, ManuscriptFile::where('title_id', $book->id)->where('slot', 'ebook')->count());
    }
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=unggahan_tersimpan_sebagai_berkas_isbn_dan_tak_menggerakkan_tahap`
Expected: FAIL — `Failed asserting that null is identical to 'buku.pdf'` (berkas belum pernah disimpan).

- [ ] **Step 3: Implementasi controller**

Di `app/Http/Controllers/Pages/BookIsbnController.php`, tambahkan import:

```php
use App\Models\ManuscriptFile;
use App\Services\ManuscriptFileService;
```

Tambahkan konstanta aturan berkas tepat di bawah deklarasi class:

```php
    /**
     * Aturan berkas ISBN. Kunci array = nama field form = slot ManuscriptFile,
     * supaya tak ada pemetaan nama yang perlu dijaga di dua tempat.
     */
    private const BERKAS_RULES = [
        'ebook'           => 'nullable|file|mimes:pdf,epub,zip|max:20480',
        'sertifikat_isbn' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:20480',
    ];
```

Tambahkan `link_terbit` dan aturan berkas ke `validated()` — ganti isi array validasi menjadi:

```php
        $data = $request->validate(array_merge([
            'status'         => 'required|in:pendaftaran,ber_isbn,cetak',
            // Tiap status mewajibkan nomor yang sesuai (pendaftaran→no_pendaftaran, ber_isbn→no_isbn, cetak→no_buku_cetak).
            'no_pendaftaran' => 'nullable|required_if:status,pendaftaran|string|max:100',
            'no_isbn'        => 'nullable|required_if:status,ber_isbn|string|max:100',
            'no_buku_cetak'  => 'nullable|required_if:status,cetak|string|max:100',
            'penerbit'       => 'nullable|string|max:150',
            'tgl_daftar'     => 'nullable|date',
            'tgl_isbn'       => 'nullable|date',
            'tgl_terbit'     => 'nullable|date',
            'link_terbit'    => 'nullable|url|max:500',
            'catatan'        => 'nullable|string',
        ], self::BERKAS_RULES), [
            'no_pendaftaran.required_if' => 'No. Pendaftaran wajib diisi untuk status Pendaftaran.',
            'no_isbn.required_if'        => 'No. ISBN wajib diisi untuk status Ber-ISBN.',
            'no_buku_cetak.required_if'  => 'No. Buku Cetak wajib diisi untuk status Cetak/Terbit.',
            'link_terbit.url'            => 'Link terbit harus berupa alamat web lengkap (diawali https://).',
        ]);

        // Berkas ditangani terpisah lewat ManuscriptFileService — jangan sampai ikut
        // masuk ke BookIsbn::create()/update() sebagai kolom.
        foreach (array_keys(self::BERKAS_RULES) as $slot) {
            unset($data[$slot]);
        }

        foreach (['tgl_daftar', 'tgl_isbn', 'tgl_terbit'] as $d) {
            $data[$d] = ($data[$d] ?? '') ?: null;
        }

        return $data;
```

Tambahkan method penyimpan berkas sebelum `destroy()`:

```php
    /**
     * Simpan berkas ISBN yang ikut terkirim bersama formulir. Slot yang tidak diisi
     * dibiarkan apa adanya — versi lama tetap berlaku, tidak terhapus.
     */
    private function simpanBerkas(Request $request, BookIsbn $isbn): void
    {
        $title = $isbn->title()->first();
        if (! $title) {
            return;
        }

        $svc = app(ManuscriptFileService::class);
        foreach (array_keys(self::BERKAS_RULES) as $slot) {
            if ($request->hasFile($slot)) {
                $svc->upload($title, null, $slot, $request->file($slot), Auth::user());
            }
        }
    }
```

Panggil dari `store()` dan `update()` tepat sebelum `syncManuscript()`:

```php
        $isbn = BookIsbn::create($data);
        $this->simpanBerkas($request, $isbn);
        $this->syncManuscript($isbn);
```

```php
        $isbn = BookIsbn::findOrFail($id);
        $isbn->update($this->validated($request));
        $this->simpanBerkas($request, $isbn);
        $this->syncManuscript($isbn);
```

- [ ] **Step 4: Jalankan test, pastikan lolos**

Run: `php artisan test --filter=BookIsbnBerkasTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Implementasi formulir**

Di `resources/views/titles/show.blade.php`, ganti seluruh blok formulir (dari `<div class="collapse" id="isbnForm">` sampai `</form>` penutupnya) dengan:

```blade
            <div class="collapse {{ $errors->any() ? 'show' : '' }}" id="isbnForm">
                @php
                    $ebook      = $isbn?->berkas('ebook');
                    $sertifikat = $isbn?->berkas('sertifikat_isbn');
                    $statusKini = old('status', optional($isbn)->status);
                @endphp
                <form method="POST" enctype="multipart/form-data"
                      action="{{ $isbn ? route('isbn.update', $isbn->id) : route('isbn.store') }}">
                    @csrf
                    @if($isbn) @method('PUT') @else <input type="hidden" name="title_id" value="{{ $title->id }}"> @endif
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label small mb-1">Status</label>
                            <select name="status" id="isbnStatus" class="form-select form-select-sm">
                                @foreach(\App\Models\BookIsbn::STATUSES as $val => $lbl)
                                    <option value="{{ $val }}" {{ $statusKini === $val ? 'selected' : '' }}>{{ $lbl }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4"><label class="form-label small mb-1">No. Pendaftaran <span class="text-danger d-none" data-isbn-req="pendaftaran">*</span></label><input name="no_pendaftaran" id="isbnNoPendaftaran" value="{{ old('no_pendaftaran', optional($isbn)->no_pendaftaran) }}" class="form-control form-control-sm"></div>
                        <div class="col-md-4"><label class="form-label small mb-1">No. ISBN <span class="text-danger d-none" data-isbn-req="ber_isbn">*</span></label><input name="no_isbn" id="isbnNoIsbn" value="{{ old('no_isbn', optional($isbn)->no_isbn) }}" class="form-control form-control-sm"></div>
                        <div class="col-md-4"><label class="form-label small mb-1">No. Buku Cetak <span class="text-danger d-none" data-isbn-req="cetak">*</span></label><input name="no_buku_cetak" id="isbnNoCetak" value="{{ old('no_buku_cetak', optional($isbn)->no_buku_cetak) }}" class="form-control form-control-sm"></div>
                        <div class="col-md-4"><label class="form-label small mb-1">Penerbit <span class="text-danger d-none" data-isbn-cetak>*</span></label><input name="penerbit" value="{{ old('penerbit', optional($isbn)->penerbit) }}" class="form-control form-control-sm"></div>
                        <div class="col-md-4"><label class="form-label small mb-1">Tgl Daftar <span class="text-danger d-none" data-isbn-cetak>*</span></label><input type="text" name="tgl_daftar" value="{{ old('tgl_daftar', optional(optional($isbn)->tgl_daftar)->format('Y-m-d')) }}" class="form-control form-control-sm flatpickr-date" placeholder="YYYY-MM-DD"></div>
                        <div class="col-md-4"><label class="form-label small mb-1">Tgl ISBN <span class="text-danger d-none" data-isbn-cetak>*</span></label><input type="text" name="tgl_isbn" value="{{ old('tgl_isbn', optional(optional($isbn)->tgl_isbn)->format('Y-m-d')) }}" class="form-control form-control-sm flatpickr-date" placeholder="YYYY-MM-DD"></div>
                        <div class="col-md-4"><label class="form-label small mb-1">Tgl Terbit <span class="text-danger d-none" data-isbn-cetak>*</span></label><input type="text" name="tgl_terbit" value="{{ old('tgl_terbit', optional(optional($isbn)->tgl_terbit)->format('Y-m-d')) }}" class="form-control form-control-sm flatpickr-date" placeholder="YYYY-MM-DD"></div>
                        <div class="col-12"><label class="form-label small mb-1">Catatan</label><textarea name="catatan" rows="2" class="form-control form-control-sm">{{ old('catatan', optional($isbn)->catatan) }}</textarea></div>
                    </div>

                    {{-- Berkas & publikasi: baru bermakna setelah nomor ISBN keluar, jadi
                         blok ini disembunyikan selama status masih Pendaftaran. --}}
                    <div class="border-top mt-3 pt-3 {{ in_array($statusKini, ['ber_isbn', 'cetak'], true) ? '' : 'd-none' }}" id="isbnBerkas">
                        <div class="fw-bold small mb-2">Berkas &amp; Publikasi</div>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label small mb-1">E-book <span class="text-danger d-none" data-isbn-cetak>*</span></label>
                                <input type="file" name="ebook" class="form-control form-control-sm" accept=".pdf,.epub,.zip">
                                @if($ebook)
                                    <div class="form-text"><a href="{{ $ebook->drive_url }}" target="_blank" rel="noopener">{{ $ebook->original_name }}</a> · v{{ $ebook->version }} · {{ $ebook->uploader?->name ?? '—' }}</div>
                                @else
                                    <div class="form-text">Belum ada berkas.</div>
                                @endif
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small mb-1">Sertifikat ISBN <span class="text-danger d-none" data-isbn-cetak>*</span></label>
                                <input type="file" name="sertifikat_isbn" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png">
                                @if($sertifikat)
                                    <div class="form-text"><a href="{{ $sertifikat->drive_url }}" target="_blank" rel="noopener">{{ $sertifikat->original_name }}</a> · v{{ $sertifikat->version }} · {{ $sertifikat->uploader?->name ?? '—' }}</div>
                                @else
                                    <div class="form-text">Belum ada berkas.</div>
                                @endif
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small mb-1">Link Terbit di web avidpedia <span class="text-danger d-none" data-isbn-cetak>*</span></label>
                                <input type="url" name="link_terbit" value="{{ old('link_terbit', optional($isbn)->link_terbit) }}" class="form-control form-control-sm" placeholder="https://avidpedia.com/...">
                            </div>
                        </div>
                        <div class="form-text mt-2">Mengunggah ulang menambah versi baru — berkas lama tetap tersimpan.</div>
                    </div>

                    <button type="submit" class="btn btn-sm btn-primary mt-2">Simpan Registrasi ISBN</button>
                </form>
            </div>
```

Tambahkan juga baris tampilan `Link Terbit` ke daftar `<dl>` di atasnya, tepat setelah baris Catatan:

```blade
            <dt class="col-sm-4 text-muted small">Link Terbit</dt><dd class="col-sm-8">@if($isbn?->link_terbit)<a href="{{ $isbn->link_terbit }}" target="_blank" rel="noopener">{{ $isbn->link_terbit }}</a>@else—@endif</dd>
```

- [ ] **Step 6: Implementasi skrip toggle**

Di `resources/views/titles/show.blade.php`, ganti isi blok `if (isbnStatus) { … }` menjadi:

```javascript
    var isbnStatus = document.getElementById('isbnStatus');
    if (isbnStatus) {
        var isbnFields = { pendaftaran: 'isbnNoPendaftaran', ber_isbn: 'isbnNoIsbn', cetak: 'isbnNoCetak' };
        var isbnBerkas = document.getElementById('isbnBerkas');
        var applyIsbnRequired = function () {
            Object.keys(isbnFields).forEach(function (st) {
                var el = document.getElementById(isbnFields[st]);
                if (el) el.required = false;
            });
            document.querySelectorAll('[data-isbn-req]').forEach(function (s) { s.classList.add('d-none'); });
            var target = document.getElementById(isbnFields[isbnStatus.value]);
            if (target) target.required = true;
            var star = document.querySelector('[data-isbn-req="' + isbnStatus.value + '"]');
            if (star) star.classList.remove('d-none');

            // Berkas & link baru bermakna sejak Ber-ISBN; wajibnya baru di Cetak/Terbit.
            if (isbnBerkas) {
                isbnBerkas.classList.toggle('d-none', ['ber_isbn', 'cetak'].indexOf(isbnStatus.value) === -1);
            }
            document.querySelectorAll('[data-isbn-cetak]').forEach(function (s) {
                s.classList.toggle('d-none', isbnStatus.value !== 'cetak');
            });
        };
        isbnStatus.addEventListener('change', applyIsbnRequired);
        applyIsbnRequired();
    }
```

- [ ] **Step 7: Jalankan test, pastikan lolos**

Run: `php artisan test --filter=BookIsbn`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Pages/BookIsbnController.php resources/views/titles/show.blade.php tests/Feature/BookIsbnBerkasTest.php
git commit -m "isbn: unggah e-book, sertifikat, dan link terbit di formulir Kelola"
```

---

## Task 7: Kolom berkas & link di Direktori ISBN

**Files:**
- Modify: `app/Http/Controllers/Pages/BookIsbnController.php:13-25`
- Modify: `resources/views/isbn/index.blade.php:16-33`
- Test: `tests/Feature/BookIsbnBerkasTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Tambahkan ke `tests/Feature/BookIsbnBerkasTest.php`:

```php
    /** @test */
    public function marketing_bisa_mengunduh_dari_direktori_tanpa_tombol_kelola(): void
    {
        $book = $this->buku();
        BookIsbn::create([
            'title_id' => $book->id, 'status' => 'cetak', 'no_isbn' => '978-602-1',
            'link_terbit' => 'https://avidpedia.com/terbit-1',
        ]);
        ManuscriptFile::create([
            'title_id' => $book->id, 'title_chapter_id' => null, 'slot' => 'ebook',
            'version' => 1, 'original_name' => 'ebook.pdf',
            'drive_url' => 'https://drive/ebook-1', 'uploaded_by' => $this->user('admin')->id,
        ]);

        $isi = $this->actingAs($this->user('marketing'))
            ->get(route('isbn.index'))->assertOk()->getContent();

        $this->assertStringContainsString('https://drive/ebook-1', $isi);
        $this->assertStringContainsString('https://avidpedia.com/terbit-1', $isi);
        $this->assertStringContainsString('978-602-1', $isi);
        // Diperiksa lewat tautannya, bukan kata "Kelola" — kata itu bisa muncul di
        // menu samping dan membuat assertion lolos/gagal karena alasan yang salah.
        $this->assertStringNotContainsString(route('title.show', $book->id), $isi,
            'Marketing tak berhak mengubah registrasi.');
    }

    /** @test */
    public function pengelola_tetap_melihat_tombol_kelola(): void
    {
        $book = $this->buku();
        BookIsbn::create(['title_id' => $book->id, 'status' => 'ber_isbn', 'no_isbn' => '978-9']);

        $this->actingAs($this->user('admin'))
            ->get(route('isbn.index'))->assertOk()->assertSee('Kelola');
    }
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=marketing_bisa_mengunduh_dari_direktori_tanpa_tombol_kelola`
Expected: FAIL — `Failed asserting that '…' contains "https://drive/ebook-1"`.

- [ ] **Step 3: Implementasi controller**

Ganti `index()` di `app/Http/Controllers/Pages/BookIsbnController.php`:

```php
    public function index()
    {
        $books = Title::where('jenis', 'buku')
            ->with(['orderDetails.titleProgress', 'bookIsbn'])
            ->latest()->get()
            ->filter->isbnEligible()
            ->values();

        // Berkas seluruh baris diambil dalam SATU query, lalu dipetakan per judul+slot.
        // orderBy('version') menaik membuat keyBy() menyisakan versi tertinggi.
        $berkas = ManuscriptFile::whereIn('title_id', $books->pluck('id'))
            ->whereNull('title_chapter_id')
            ->whereIn('slot', ManuscriptFile::SLOTS_ISBN)
            ->orderBy('version')
            ->get()
            ->keyBy(fn (ManuscriptFile $f) => $f->title_id . ':' . $f->slot);

        return view('isbn.index', [
            'books'     => $books,
            'berkas'    => $berkas,
            'canManage' => Auth::user()->hasAnyRole(['superadmin', 'manager', 'admin', 'production']),
        ]);
    }
```

- [ ] **Step 4: Implementasi tabel direktori**

Di `resources/views/isbn/index.blade.php`, ganti blok `<table>` (dari `<thead>` sampai `</tbody>`) dengan:

```blade
            <thead><tr><th>Kode</th><th>Judul</th><th>No. ISBN</th><th>E-book</th><th>Sertifikat</th><th>Link Terbit</th><th>Status</th><th>Penerbit</th><th>Tgl ISBN</th><th>Aksi</th></tr></thead>
            <tbody>
                @foreach($books as $b)
                    @php
                        $ebook      = $berkas[$b->id . ':ebook'] ?? null;
                        $sertifikat = $berkas[$b->id . ':sertifikat_isbn'] ?? null;
                    @endphp
                    <tr>
                        <td>{{ $b->code ?? '—' }}</td>
                        <td class="dt-judul">{{ $b->title }}</td>
                        <td>{{ $b->bookIsbn?->no_isbn ?: '—' }}</td>
                        <td>@if($ebook && $ebook->drive_url)<a href="{{ $ebook->drive_url }}" target="_blank" rel="noopener">Unduh</a>@else—@endif</td>
                        <td>@if($sertifikat && $sertifikat->drive_url)<a href="{{ $sertifikat->drive_url }}" target="_blank" rel="noopener">Unduh</a>@else—@endif</td>
                        <td>@if($b->bookIsbn?->link_terbit)<a href="{{ $b->bookIsbn->link_terbit }}" target="_blank" rel="noopener">Buka</a>@else—@endif</td>
                        <td>@if($b->bookIsbn)<span class="badge bg-info">{{ $b->bookIsbn->statusLabel() }}</span>@else<span class="badge bg-light text-dark border">Belum didaftarkan</span>@endif</td>
                        <td>{{ $b->bookIsbn?->penerbit ?: '—' }}</td>
                        <td>{{ optional($b->bookIsbn?->tgl_isbn)->format('d M Y') ?? '—' }}</td>
                        <td>@if($canManage)<a href="{{ route('title.show', $b->id) }}" class="btn btn-xs btn-outline-primary">Kelola</a>@else<span class="text-muted">—</span>@endif</td>
                    </tr>
                @endforeach
            </tbody>
```

Ganti juga kalimat pengantar di atas tabel supaya jujur untuk marketing:

```blade
    <p class="text-muted small mb-3">Buku yang manuskripnya telah mencapai tahap ISBN. E-book dan sertifikat bisa diunduh langsung dari baris masing-masing.</p>
```

- [ ] **Step 5: Jalankan test, pastikan lolos**

Run: `php artisan test --filter=BookIsbnBerkasTest`
Expected: PASS (6 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Pages/BookIsbnController.php resources/views/isbn/index.blade.php tests/Feature/BookIsbnBerkasTest.php
git commit -m "isbn: kolom e-book, sertifikat, dan link terbit di Direktori ISBN"
```

---

## Task 8: Kelengkapan data wajib saat status Cetak/Terbit

**Files:**
- Modify: `app/Http/Controllers/Pages/BookIsbnController.php` (`validated()`, `store()`, `update()`)
- Test: `tests/Feature/BookIsbnValidasiTest.php` (buat baru)

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/BookIsbnValidasiTest.php`:

```php
<?php
// tests/Feature/BookIsbnValidasiTest.php

namespace Tests\Feature;

use App\Models\BookIsbn;
use App\Models\ManuscriptFile;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Status Cetak/Terbit berarti buku sudah terbit dan datanya dipakai marketing untuk
 * melayani klien — jadi seluruh kolom wajib, kecuali Catatan yang sifatnya keterangan
 * bebas. Berkas yang sudah pernah diunggah dihitung terisi.
 */
class BookIsbnValidasiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        $this->mock(GoogleDriveService::class, function ($m) {
            $m->shouldReceive('uploadFile')->andReturn(['id' => 'drive-3', 'url' => 'https://drive/3']);
        });
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('admin');

        return $u->fresh();
    }

    private function buku(): Title
    {
        $book = Title::create([
            'title' => 'Buku Validasi ' . fake()->unique()->word(), 'jenis' => 'buku',
            'tipe_naskah' => 'mandiri', 'status' => 'disetujui',
        ]);
        $detail = OrderDetail::factory()->create([
            'type' => 'bk_mandiri', 'title' => $book->title, 'title_id' => $book->id, 'chapters' => 1,
        ]);
        TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => 'isbn',
            'assigned_role' => TitleProgress::getHandlerForStatus('isbn'),
            'bidang' => 'buku', 'started_at' => now(),
        ]);

        return $book->fresh();
    }

    /** Payload lengkap untuk status cetak, minus kunci yang sengaja dihilangkan. */
    private function lengkap(Title $book, array $tanpa = []): array
    {
        $data = [
            'title_id'        => $book->id,
            'status'          => 'cetak',
            'no_pendaftaran'  => 'REG-001',
            'no_isbn'         => '978-602-1234-56-7',
            'no_buku_cetak'   => 'CTK-001',
            'penerbit'        => 'Avidpedia Press',
            'tgl_daftar'      => '2026-01-10',
            'tgl_isbn'        => '2026-02-10',
            'tgl_terbit'      => '2026-03-10',
            'link_terbit'     => 'https://avidpedia.com/buku-terbit',
            'ebook'           => UploadedFile::fake()->create('ebook.pdf', 20, 'application/pdf'),
            'sertifikat_isbn' => UploadedFile::fake()->create('sertifikat.pdf', 20, 'application/pdf'),
        ];

        foreach ($tanpa as $k) {
            unset($data[$k]);
        }

        return $data;
    }

    /** @test */
    public function status_cetak_menolak_data_yang_belum_lengkap(): void
    {
        $kolomWajib = ['no_pendaftaran', 'no_isbn', 'no_buku_cetak', 'penerbit',
                       'tgl_daftar', 'tgl_isbn', 'tgl_terbit', 'link_terbit'];

        foreach ($kolomWajib as $kolom) {
            $book = $this->buku();

            $this->actingAs($this->admin())
                ->post(route('isbn.store'), $this->lengkap($book, [$kolom]))
                ->assertSessionHasErrors($kolom);

            $this->assertDatabaseMissing('tb_book_isbns', ['title_id' => $book->id]);
        }
    }

    /** @test */
    public function status_cetak_menolak_bila_berkas_belum_ada(): void
    {
        $book = $this->buku();

        $this->actingAs($this->admin())
            ->post(route('isbn.store'), $this->lengkap($book, ['ebook']))
            ->assertSessionHasErrors('ebook');

        $book2 = $this->buku();
        $this->actingAs($this->admin())
            ->post(route('isbn.store'), $this->lengkap($book2, ['sertifikat_isbn']))
            ->assertSessionHasErrors('sertifikat_isbn');
    }

    /** @test */
    public function berkas_yang_sudah_pernah_diunggah_dihitung_terisi(): void
    {
        $book  = $this->buku();
        $admin = $this->admin();

        // Simpan dulu di Ber-ISBN lengkap dengan berkasnya.
        $this->actingAs($admin)->post(route('isbn.store'), [
            'title_id' => $book->id, 'status' => 'ber_isbn', 'no_isbn' => '978-602-1234-56-7',
            'ebook' => UploadedFile::fake()->create('ebook.pdf', 20, 'application/pdf'),
            'sertifikat_isbn' => UploadedFile::fake()->create('sertifikat.pdf', 20, 'application/pdf'),
        ])->assertRedirect();

        $isbn = BookIsbn::where('title_id', $book->id)->firstOrFail();

        // Naik ke Cetak/Terbit TANPA memilih berkas lagi — harus lolos.
        $this->actingAs($admin)->put(route('isbn.update', $isbn->id), [
            'status' => 'cetak', 'no_pendaftaran' => 'REG-001', 'no_isbn' => '978-602-1234-56-7',
            'no_buku_cetak' => 'CTK-001', 'penerbit' => 'Avidpedia Press',
            'tgl_daftar' => '2026-01-10', 'tgl_isbn' => '2026-02-10', 'tgl_terbit' => '2026-03-10',
            'link_terbit' => 'https://avidpedia.com/buku-terbit',
        ])->assertSessionHasNoErrors();

        $this->assertSame('cetak', $isbn->fresh()->status);
        $this->assertSame(1, ManuscriptFile::where('title_id', $book->id)->where('slot', 'ebook')->count());
    }

    /** @test */
    public function catatan_kosong_tidak_pernah_menghalangi(): void
    {
        $book = $this->buku();

        $this->actingAs($this->admin())
            ->post(route('isbn.store'), $this->lengkap($book))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tb_book_isbns', ['title_id' => $book->id, 'status' => 'cetak']);
    }

    /** @test */
    public function status_selain_cetak_tetap_memakai_aturan_lama(): void
    {
        $book = $this->buku();

        // Ber-ISBN cukup dengan no_isbn — penerbit, tanggal, berkas boleh menyusul.
        $this->actingAs($this->admin())
            ->post(route('isbn.store'), [
                'title_id' => $book->id, 'status' => 'ber_isbn', 'no_isbn' => '978-602-1234-56-7',
            ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tb_book_isbns', ['title_id' => $book->id, 'status' => 'ber_isbn']);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=BookIsbnValidasiTest`
Expected: FAIL — `Session is missing expected key 'no_pendaftaran'`, karena aturan wajib belum ada.

- [ ] **Step 3: Implementasi validasi**

Ganti method `validated()` di `app/Http/Controllers/Pages/BookIsbnController.php`:

```php
    private function validated(Request $request): array
    {
        // Status Cetak/Terbit = buku sudah terbit dan datanya dipakai marketing untuk
        // melayani klien, jadi seluruh kolom wajib kecuali Catatan. Status lain tetap
        // memakai aturan lama: masing-masing hanya mewajibkan nomornya sendiri.
        $cetak = $request->input('status') === 'cetak';

        $data = $request->validate(array_merge([
            'status'         => 'required|in:pendaftaran,ber_isbn,cetak',
            'no_pendaftaran' => $cetak ? 'required|string|max:100' : 'nullable|required_if:status,pendaftaran|string|max:100',
            'no_isbn'        => $cetak ? 'required|string|max:100' : 'nullable|required_if:status,ber_isbn|string|max:100',
            'no_buku_cetak'  => $cetak ? 'required|string|max:100' : 'nullable|string|max:100',
            'penerbit'       => $cetak ? 'required|string|max:150' : 'nullable|string|max:150',
            'tgl_daftar'     => $cetak ? 'required|date' : 'nullable|date',
            'tgl_isbn'       => $cetak ? 'required|date' : 'nullable|date',
            'tgl_terbit'     => $cetak ? 'required|date' : 'nullable|date',
            'link_terbit'    => $cetak ? 'required|url|max:500' : 'nullable|url|max:500',
            'catatan'        => 'nullable|string',
        ], self::BERKAS_RULES), [
            'no_pendaftaran.required_if' => 'No. Pendaftaran wajib diisi untuk status Pendaftaran.',
            'no_isbn.required_if'        => 'No. ISBN wajib diisi untuk status Ber-ISBN.',
            'no_pendaftaran.required'    => 'No. Pendaftaran wajib diisi untuk status Cetak/Terbit.',
            'no_isbn.required'           => 'No. ISBN wajib diisi untuk status Cetak/Terbit.',
            'no_buku_cetak.required'     => 'No. Buku Cetak wajib diisi untuk status Cetak/Terbit.',
            'penerbit.required'          => 'Penerbit wajib diisi untuk status Cetak/Terbit.',
            'tgl_daftar.required'        => 'Tgl Daftar wajib diisi untuk status Cetak/Terbit.',
            'tgl_isbn.required'          => 'Tgl ISBN wajib diisi untuk status Cetak/Terbit.',
            'tgl_terbit.required'        => 'Tgl Terbit wajib diisi untuk status Cetak/Terbit.',
            'link_terbit.required'       => 'Link terbit wajib diisi untuk status Cetak/Terbit.',
            'link_terbit.url'            => 'Link terbit harus berupa alamat web lengkap (diawali https://).',
        ]);

        // Berkas ditangani terpisah lewat ManuscriptFileService — jangan sampai ikut
        // masuk ke BookIsbn::create()/update() sebagai kolom.
        foreach (array_keys(self::BERKAS_RULES) as $slot) {
            unset($data[$slot]);
        }

        foreach (['tgl_daftar', 'tgl_isbn', 'tgl_terbit'] as $d) {
            $data[$d] = ($data[$d] ?? '') ?: null;
        }

        return $data;
    }

    /**
     * Berkas wajib saat Cetak/Terbit, TAPI yang sudah pernah diunggah dihitung terisi —
     * menyimpan ulang tak boleh memaksa memilih berkas yang sama sekali lagi.
     */
    private function assertBerkasLengkap(Request $request, ?BookIsbn $isbn): void
    {
        if ($request->input('status') !== 'cetak') {
            return;
        }

        $nama  = ['ebook' => 'E-book', 'sertifikat_isbn' => 'Sertifikat ISBN'];
        $galat = [];

        foreach ($nama as $slot => $label) {
            if ($request->hasFile($slot) || ($isbn && $isbn->berkas($slot))) {
                continue;
            }
            $galat[$slot] = "{$label} wajib diunggah untuk status Cetak/Terbit.";
        }

        if ($galat !== []) {
            throw ValidationException::withMessages($galat);
        }
    }
```

Tambahkan import di bagian atas berkas:

```php
use Illuminate\Validation\ValidationException;
```

- [ ] **Step 4: Panggil pemeriksa berkas dari store() dan update()**

Di `store()`, tepat setelah `$data = $this->validated($request);`:

```php
        $data = $this->validated($request);
        $this->assertBerkasLengkap($request, null);
```

Di `update()`, ganti tiga baris pertama badan method menjadi:

```php
        $isbn = BookIsbn::findOrFail($id);
        $data = $this->validated($request);
        $this->assertBerkasLengkap($request, $isbn);
        $isbn->update($data);
```

- [ ] **Step 5: Jalankan test, pastikan lolos**

Run: `php artisan test --filter=BookIsbnValidasiTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Pages/BookIsbnController.php tests/Feature/BookIsbnValidasiTest.php
git commit -m "isbn: seluruh kolom wajib saat status Cetak/Terbit kecuali catatan"
```

---

## Task 9: Verifikasi akhir & migrasi basis data dev

**Files:** tidak ada perubahan kode.

- [ ] **Step 1: Jalankan seluruh suite**

Run: `php artisan test`
Expected: PASS seluruhnya. Sebelum pekerjaan ini baseline-nya 744 lolos + 1 dilewati (per catatan 2026-07-24) ditambah test modul-modul sesudahnya; angka pastinya boleh bergeser, yang tidak boleh adalah adanya kegagalan.

Bila `mimes:epub` menolak berkas `.epub` yang sah (mime aslinya `application/epub+zip`), longgarkan aturan `ebook` jadi `'nullable|file|mimes:pdf,zip|max:20480'`, tambahkan catatan singkat di atas `BERKAS_RULES` yang menyebutkan alasannya, lalu jalankan ulang suite.

- [ ] **Step 2: Migrasikan basis data dev**

Tanpa langkah ini, halaman Detail Judul dan Direktori ISBN akan 500 di aplikasi dev karena kolom `link_terbit` belum ada — test hijau memakai basis data test yang terpisah.

Run: `php artisan migrate`
Expected: `2026_08_16_000001_add_link_terbit_to_tb_book_isbns ... DONE`

- [ ] **Step 3: Verifikasi kolom benar-benar ada**

Run:
```bash
"C:/xampp/mysql/bin/mysql.exe" -u root -N -e "select column_name from information_schema.columns where table_schema='avidpedi_simapa' and table_name='tb_book_isbns' and column_name='link_terbit';"
```
Expected: `link_terbit`

- [ ] **Step 4: Periksa naskah 64 di dev**

Run:
```bash
php artisan tinker --execute="\$b = App\Models\Title::find(34); foreach (\$b->chapters()->with('progress')->orderBy('urutan')->get() as \$c) { echo \$c->urutan . ' ' . \$c->progress->status . ' ' . (\$c->progress->sumberNaskah() ?? 'null') . ' next=' . (\$c->progress->nextStage() ?? '-') . PHP_EOL; }"
```
Expected: bab bernaskah `mandiri` yang berstatus `menunggu` menunjukkan `next=editing`, bukan `next=pembuatan`. Inilah bukti kebuntuan T1 sudah terbuka.

- [ ] **Step 5: Commit catatan bila ada penyimpangan**

Bila ada langkah yang menyimpang dari rencana (misalnya `mimes:epub` dilonggarkan), catat di bagian bawah rencana ini lalu:

```bash
git add docs/superpowers/plans/2026-08-16-isbn-berkas-dan-bab-mandiri.md
git commit -m "plan: catat penyimpangan implementasi berkas ISBN"
```

---

## Catatan penyimpangan

*(diisi selama implementasi bila ada langkah yang berbeda dari rencana)*
