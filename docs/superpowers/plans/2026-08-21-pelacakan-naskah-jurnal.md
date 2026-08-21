# Pelacakan Naskah Jurnal & Sinkronisasi Artefak — Rencana Implementasi

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Naskah tidak bisa dinyatakan terbit/publish tanpa link artikel terbit, Informasi Publikasi bisa diperbarui langsung dari layar naskah, kolom status order berhenti macet di "Diproses", dan Artefak Penyelesaian menampilkan data yang sudah diisi di modul lain.

**Architecture:** Kolom baru `tb_titles.link_terbit` jadi sumber kanonik link terbit untuk kedua jenis, dengan `Title::linkTerbit()` sebagai satu-satunya pembaca (fallback ke `BookIsbn.link_terbit` / `JournalSubmission.link_publish` supaya data lama tak terkunci). Gerbangnya dipasang di dua penulis tahap — `TitleProgressService::advance()` dan `ChapterManuscriptService::advanceBookToStage()`. Sisanya murni tampilan: satu panel baru di layar naskah, satu label turunan di daftar order, dan perluasan prefill artefak arsip.

**Tech Stack:** Laravel 10, MariaDB 10.4 (XAMPP), Blade + Bootstrap 4, PHPUnit lewat `php artisan test`.

**Todo sumber:** [2026-08-21-todo-pelacakan-naskah-jurnal.md](2026-08-21-todo-pelacakan-naskah-jurnal.md) — kelima keputusan di §6 sudah diambil.

**Branch:** lanjutkan di `feat/sinkronisasi-status-order-naskah` (sudah berisi 24 commit batch sebelumnya).

---

## Keputusan yang mempersempit rencana ini

**Layar naskah TIDAK membuat baris `JournalSubmission` baru.** Todo §6.A bilang link terbit
"mencerminkan ke direktori jurnal bila bisa". Rencana ini menafsirkannya sebagai: cermin
ditulis **hanya bila baris submission-nya sudah ada**. Membuat baris baru menuntut
`journal_id` (NOT NULL, FK ke `tb_journals`), yang berarti memasang pemilih jurnal di layar
naskah — pekerjaan tersendiri, dan Direktori Jurnal memang tempatnya. Konsekuensinya:
artikel yang belum punya baris submission tetap bisa publish, linknya tersimpan di judul,
dan direktori menyusul belakangan. Kalau ini keliru, ia mudah dibalik — lihat Task 3.

**Kolom `status` di `tb_orders` tidak disentuh sama sekali.** Poin 3 murni tampilan: label
diturunkan saat render. Menambah nilai ke kolom itu melanggar K3 dan merusak Laporan
Keuangan + Piutang.

---

## Struktur berkas

**Dibuat:**

| Berkas | Tanggung jawab |
|---|---|
| `database/migrations/2026_08_21_000001_add_link_terbit_to_tb_titles.php` | Kolom `link_terbit` |
| `resources/views/naskah/partials/informasi-publikasi.blade.php` | Panel Informasi Publikasi di layar naskah |
| `tests/Feature/LinkTerbitGateTest.php` | Task 1–2 |
| `tests/Feature/NaskahInfoPublikasiTest.php` | Task 3–4 |
| `tests/Feature/OrderLabelPembayaranTest.php` | Task 5 |
| `tests/Feature/ArtefakPrefillTest.php` | Task 6–7 |

**Diubah:**

| Berkas | Perubahan |
|---|---|
| `app/Models/Title.php` | `fillable`, `linkTerbit()`, `butuhLinkTerbit()` |
| `app/Models/Order.php` | `labelPembayaran()` |
| `app/Services/TitleProgressService.php` | `assertLinkTerbit()` dipanggil dari `advance()` |
| `app/Services/ChapterManuscriptService.php` | `advanceBookToStage()` melewati tahap final tanpa link |
| `app/Services/TitleService.php` | `updateInfo()` menulis `link_terbit` + cermin ke submission |
| `app/Http/Controllers/Pages/TitleController.php` | validasi `link_terbit`, redirect sadar-asal |
| `app/Http/Controllers/Pages/Naskah/DetailNaskahController.php` | kirim `$title` + `$canEditInfo` ke view |
| `app/Services/TitleArchivalService.php` | `defaultArtifacts()` membaca ManuscriptFile + BookIsbn |
| `resources/views/naskah/detail.blade.php` | sisipkan partial baru |
| `resources/views/orders/book/index.blade.php` | kolom Pembayaran |
| `resources/views/archive/show.blade.php` | artefak terisi tampil sebagai info + sumber |

---

## Task 1: Kolom `link_terbit` + resolver di `Title`

**Files:**
- Create: `database/migrations/2026_08_21_000001_add_link_terbit_to_tb_titles.php`
- Modify: `app/Models/Title.php`
- Test: `tests/Feature/LinkTerbitGateTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/LinkTerbitGateTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\BookIsbn;
use App\Models\Journal;
use App\Models\JournalSubmission;
use App\Models\Title;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LinkTerbitGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    /** @test */
    public function link_di_judul_menang_atas_sumber_lain(): void
    {
        $title = Title::create(['title' => 'Artikel A', 'jenis' => 'artikel',
                                'tipe_naskah' => 'mandiri', 'status' => 'disetujui',
                                'link_terbit' => 'https://judul.test/a']);

        $journal = Journal::create(['nama' => 'Jurnal Uji']);
        JournalSubmission::create(['journal_id' => $journal->id, 'title_id' => $title->id,
                                   'link_publish' => 'https://submission.test/a']);

        $this->assertSame('https://judul.test/a', $title->fresh()->linkTerbit());
    }

    /** @test */
    public function artikel_tanpa_link_judul_jatuh_ke_direktori_jurnal(): void
    {
        $title   = Title::create(['title' => 'Artikel B', 'jenis' => 'artikel',
                                  'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $journal = Journal::create(['nama' => 'Jurnal Uji']);
        JournalSubmission::create(['journal_id' => $journal->id, 'title_id' => $title->id,
                                   'link_publish' => 'https://submission.test/b']);

        $this->assertSame('https://submission.test/b', $title->fresh()->linkTerbit());
    }

    /** @test */
    public function buku_tanpa_link_judul_jatuh_ke_direktori_isbn(): void
    {
        $title = Title::create(['title' => 'Buku C', 'jenis' => 'buku',
                                'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        BookIsbn::create(['title_id' => $title->id, 'status' => 'cetak',
                          'link_terbit' => 'https://isbn.test/c']);

        $this->assertSame('https://isbn.test/c', $title->fresh()->linkTerbit());
    }

    /** @test */
    public function tanpa_sumber_mana_pun_bernilai_null(): void
    {
        $title = Title::create(['title' => 'Artikel D', 'jenis' => 'artikel',
                                'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);

        $this->assertNull($title->linkTerbit());
    }

    /** @test */
    public function string_kosong_diperlakukan_sebagai_tidak_ada(): void
    {
        $title = Title::create(['title' => 'Artikel E', 'jenis' => 'artikel',
                                'tipe_naskah' => 'mandiri', 'status' => 'disetujui',
                                'link_terbit' => '   ']);

        $this->assertNull($title->fresh()->linkTerbit());
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=LinkTerbitGateTest`
Expected: FAIL — `Unknown column 'link_terbit'`.

- [ ] **Step 3: Buat migrasi**

Buat `database/migrations/2026_08_21_000001_add_link_terbit_to_tb_titles.php`:

```php
<?php
// database/migrations/2026_08_21_000001_add_link_terbit_to_tb_titles.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link artikel/buku terbit — sumber kanonik untuk KEDUA jenis.
 *
 * Sebelumnya link terbit tersebar di dua modul: `tb_book_isbns.link_terbit` (buku) dan
 * `tb_journal_submissions.link_publish` (artikel). Keduanya butuh baris induk yang
 * mungkin belum dibuat — dan untuk artikel, baris submission menuntut `journal_id` yang
 * NOT NULL, sehingga jurnal yang belum terdaftar di direktori akan MENGUNCI naskahnya
 * dari publish. Menaruhnya di judul membuat linknya selalu bisa diisi; Title::linkTerbit()
 * tetap membaca kedua sumber lama sebagai cadangan supaya data lama tak terkunci.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_titles', function (Blueprint $table) {
            $table->string('link_terbit', 500)->nullable()->after('catatan_publikasi');
        });
    }

    public function down(): void
    {
        Schema::table('tb_titles', function (Blueprint $table) {
            $table->dropColumn('link_terbit');
        });
    }
};
```

- [ ] **Step 4: Tambah resolver di `Title`**

Di `app/Models/Title.php`, tambahkan `'link_terbit',` ke `$fillable` (setelah
`'catatan_publikasi',`), lalu tambahkan dua method tepat di bawah `isbnEligible()`:

```php
    /**
     * Link karya terbit — satu jawaban untuk kedua jenis.
     *
     * Urutan: kolom judul menang, lalu cadangan dari modul asalnya. Cadangan itu ADA
     * supaya judul yang linknya sudah diisi di Direktori ISBN / Direktori Jurnal tidak
     * ikut terkunci oleh gerbang baru — bukan supaya dua tempat boleh berbeda isi.
     */
    public function linkTerbit(): ?string
    {
        $sendiri = trim((string) $this->link_terbit);
        if ($sendiri !== '') {
            return $sendiri;
        }

        if ($this->jenis === 'buku') {
            $isbn = trim((string) optional($this->bookIsbn)->link_terbit);

            return $isbn !== '' ? $isbn : null;
        }

        $submission = \App\Models\JournalSubmission::where('title_id', $this->id)
            ->latest('id')->first();
        $link = trim((string) optional($submission)->link_publish);

        return $link !== '' ? $link : null;
    }

    /** Belum punya link terbit — dipakai gerbang tahap akhir dan peringatan di UI. */
    public function butuhLinkTerbit(): bool
    {
        return $this->linkTerbit() === null;
    }
```

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=LinkTerbitGateTest`
Expected: PASS (5 test).

- [ ] **Step 6: Jalankan migrasi di DB dev**

```bash
php artisan migrate
```

Tests memakai DB terpisah; tanpa langkah ini aplikasi live 500 di kolom yang belum ada.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_21_000001_add_link_terbit_to_tb_titles.php app/Models/Title.php tests/Feature/LinkTerbitGateTest.php
git commit -m "judul: link terbit punya satu rumah, dengan cadangan ke modul asalnya"
```

(Trailer `Co-authored-by: Mira <admin@avidpedia.com>`.)

---

## Task 2: Gerbang tahap akhir menuntut link terbit

**Files:**
- Modify: `app/Services/TitleProgressService.php`
- Modify: `app/Services/ChapterManuscriptService.php`
- Test: `tests/Feature/LinkTerbitGateTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Tambahkan ke `tests/Feature/LinkTerbitGateTest.php`. Tambahkan dulu import
`App\Models\Order`, `App\Models\OrderDetail`, `App\Models\TitleProgress`,
`App\Models\User`, `App\Services\TitleProgressService`,
`Illuminate\Validation\ValidationException`, lalu:

```php
    private function superadmin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u->fresh();
    }

    /** Naskah satu langkah sebelum tahap akhir. */
    private function naskah(Title $title, string $status, string $type = 'at_mandiri'): TitleProgress
    {
        $order  = Order::factory()->create();
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => $type,
            'title' => $title->title, 'title_id' => $title->id,
        ]);

        return TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => $status,
            'bidang' => $type === 'at_mandiri' ? 'artikel' : 'buku',
            'started_at' => now(),
        ]);
    }

    /** @test */
    public function artikel_tanpa_link_tidak_bisa_naik_ke_publish(): void
    {
        $title    = Title::create(['title' => 'Artikel F', 'jenis' => 'artikel',
                                   'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $progress = $this->naskah($title, 'loa');

        try {
            app(TitleProgressService::class)->advance($progress, $this->superadmin());
            $this->fail('Seharusnya ditolak karena link terbit kosong.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('link', strtolower($e->getMessage()));
        }

        $this->assertSame('loa', $progress->fresh()->status);
    }

    /** @test */
    public function artikel_dengan_link_boleh_naik_ke_publish(): void
    {
        $title    = Title::create(['title' => 'Artikel G', 'jenis' => 'artikel',
                                   'tipe_naskah' => 'mandiri', 'status' => 'disetujui',
                                   'link_terbit' => 'https://jurnal.test/g']);
        $progress = $this->naskah($title, 'loa');

        app(TitleProgressService::class)->advance($progress, $this->superadmin());

        $this->assertSame('publish', $progress->fresh()->status);
    }

    /** @test */
    public function buku_tanpa_link_tidak_bisa_naik_ke_terbit(): void
    {
        $title    = Title::create(['title' => 'Buku H', 'jenis' => 'buku',
                                   'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $progress = $this->naskah($title, 'cetak', 'bk_mandiri');

        try {
            app(TitleProgressService::class)->advance($progress, $this->superadmin());
            $this->fail('Seharusnya ditolak karena link terbit kosong.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('link', strtolower($e->getMessage()));
        }

        $this->assertSame('cetak', $progress->fresh()->status);
    }

    /** Gerbang HANYA di tahap akhir — tahap tengah tak boleh ikut terkunci. */
    /** @test */
    public function tahap_tengah_tidak_menuntut_link(): void
    {
        $title    = Title::create(['title' => 'Artikel I', 'jenis' => 'artikel',
                                   'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $progress = $this->naskah($title, 'submit');

        app(TitleProgressService::class)->advance($progress, $this->superadmin());

        $this->assertSame('loa', $progress->fresh()->status);
    }

    /**
     * Koreksi superadmin sengaja dikecualikan: ia justru wewenang membetulkan keadaan,
     * termasuk menandai naskah lama yang linknya memang tak pernah tercatat.
     *
     * @test
     */
    public function koreksi_superadmin_tidak_terhalang_gerbang(): void
    {
        $title    = Title::create(['title' => 'Artikel J', 'jenis' => 'artikel',
                                   'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $progress = $this->naskah($title, 'editing');

        app(TitleProgressService::class)
            ->correct($progress, 'publish', $this->superadmin(), 'Naskah lama, link menyusul');

        $this->assertSame('publish', $progress->fresh()->status);
    }
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=LinkTerbitGateTest`
Expected: FAIL pada `artikel_tanpa_link...` dan `buku_tanpa_link...` — tahapnya naik padahal
seharusnya ditolak.

- [ ] **Step 3: Pasang gerbang di `advance()`**

Di `app/Services/TitleProgressService.php`, method `advance()`, sisipkan tepat setelah
`$this->assertLayoutUnlocked($progress, $next);`:

```php
        $this->assertLinkTerbit($progress, $next);
```

Lalu tambahkan method ini tepat di bawah `assertLayoutUnlocked()`:

```php
    /**
     * Tahap akhir menuntut link karya terbit.
     *
     * Naskah yang "terbit" tanpa alamat terbitnya adalah klaim tanpa bukti: ia langsung
     * dihitung selesai, menutup ordernya, dan memenuhi syarat arsip. Gerbang ini menahan
     * ketiganya sekaligus — archiveEligible() sudah menuntut manuscriptIsFinal(), jadi
     * tak perlu gerbang kedua di sisi arsip yang bisa berbeda pendapat.
     *
     * correct() TIDAK melewati gerbang ini: koreksi adalah wewenang superadmin untuk
     * membetulkan keadaan, termasuk naskah lama yang linknya memang tak pernah tercatat.
     */
    private function assertLinkTerbit(TitleProgress $progress, string $next): void
    {
        if (! TitleProgress::isFinal($next)) {
            return;
        }

        $title = $progress->orderDetail?->titleRef;
        if ($title === null || ! $title->butuhLinkTerbit()) {
            return;
        }

        throw ValidationException::withMessages([
            'link_terbit' => $title->jenis === 'buku'
                ? 'Isi dulu Link Buku Terbit sebelum menandai naskah Terbit.'
                : 'Isi dulu Link Artikel Terbit sebelum menandai naskah Publish.',
        ]);
    }
```

- [ ] **Step 4: Tutup jalur ISBN**

Di `app/Services/ChapterManuscriptService.php`, method `advanceBookToStage()`, sisipkan
tepat setelah pemeriksaan `if ($targetIdx === false) { return; }`:

```php
        /*
         | Jalur ISBN menulis tahap secara langsung, jadi gerbang di
         | TitleProgressService::advance() tak berlaku di sini. Tanpa penjagaan ini
         | buku bisa mendarat di 'terbit' tanpa link — persis kebocoran yang sudah
         | menggigit sekali saat OrderFulfillmentService dipasang.
         |
         | MELEWATI, bukan melempar: ini sinkronisasi yang dipicu penyimpanan form ISBN,
         | bukan aksi "majukan tahap" milik pengguna. Melempar di sini akan menggagalkan
         | penyimpanan ISBN yang sah. BookIsbnController sudah mewajibkan link_terbit
         | untuk status Cetak/Terbit, jadi cabang ini praktis tak terpicu — ia ada supaya
         | invariannya benar tak peduli siapa pemanggilnya.
         */
        if (TitleProgress::isFinal($target) && $book->butuhLinkTerbit()) {
            return;
        }
```

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=LinkTerbitGateTest`
Expected: PASS (10 test).

- [ ] **Step 6: Pastikan modul naskah & ISBN tidak rusak**

Run: `php artisan test --filter="Naskah|TitleProgress|BookIsbn|ChapterManuscript|ChapterRollup|OrderFulfillment|WithdrawnExclusion|Archive"`
Expected: PASS semua. **Kalau ada yang merah**, kemungkinan besar test lama membangun
naskah sampai tahap akhir tanpa link — itu bukan test yang salah, tapi fixture-nya perlu
`'link_terbit' => 'https://...'`. Perbaiki fixture-nya, jangan melonggarkan gerbang.

- [ ] **Step 7: Commit**

```bash
git add app/Services/TitleProgressService.php app/Services/ChapterManuscriptService.php tests/Feature/LinkTerbitGateTest.php
git commit -m "naskah: tak bisa mengaku terbit tanpa alamat terbitnya"
```

---

## Task 3: `updateInfo` menerima link terbit + kembali ke asalnya

**Files:**
- Modify: `app/Http/Controllers/Pages/TitleController.php` (`updateInfo`, ~baris 267)
- Modify: `app/Services/TitleService.php` (`updateInfo`, ~baris 235)
- Test: `tests/Feature/NaskahInfoPublikasiTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/NaskahInfoPublikasiTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Journal;
use App\Models\JournalSubmission;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NaskahInfoPublikasiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
        $this->seed(\Database\Seeders\AccessMatrixSeeder::class);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u->fresh();
    }

    /** @return array{0: Title, 1: TitleProgress} */
    private function naskah(): array
    {
        $title  = Title::create(['title' => 'Artikel Info', 'jenis' => 'artikel',
                                 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $order  = Order::factory()->create();
        $detail = OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'at_mandiri',
            'title' => $title->title, 'title_id' => $title->id,
        ]);
        $progress = TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => 'loa',
            'bidang' => 'artikel', 'started_at' => now(),
        ]);

        return [$title, $progress];
    }

    /** @test */
    public function admin_menyimpan_link_terbit_lewat_update_info(): void
    {
        [$title] = $this->naskah();

        $this->actingAs($this->user('admin'))
            ->put(route('title.info.update', $title->id), [
                'link_terbit' => 'https://jurnal.test/artikel-info',
            ])->assertRedirect();

        $this->assertSame('https://jurnal.test/artikel-info', $title->fresh()->link_terbit);
    }

    /**
     * Formnya dipakai dari DUA layar. Tanpa redirect sadar-asal, menyimpan dari layar
     * naskah melempar orang ke halaman judul dan konteks kerjanya hilang.
     *
     * @test
     */
    public function menyimpan_dari_layar_naskah_kembali_ke_layar_naskah(): void
    {
        [$title, $progress] = $this->naskah();
        $kembali = route('naskah.show', $progress->order_detail_id);

        $this->actingAs($this->user('admin'))
            ->put(route('title.info.update', $title->id), [
                'link_terbit' => 'https://jurnal.test/x',
                '_redirect'   => $kembali,
            ])->assertRedirect($kembali);
    }

    /** Redirect hanya boleh ke dalam aplikasi sendiri. */
    /** @test */
    public function redirect_ke_luar_aplikasi_diabaikan(): void
    {
        [$title] = $this->naskah();

        $this->actingAs($this->user('admin'))
            ->put(route('title.info.update', $title->id), [
                'link_terbit' => 'https://jurnal.test/x',
                '_redirect'   => 'https://situs-jahat.test/panen',
            ])->assertRedirect(route('title.show', $title->id));
    }

    /** @test */
    public function link_dicerminkan_ke_direktori_jurnal_bila_barisnya_ada(): void
    {
        [$title] = $this->naskah();
        $journal = Journal::create(['nama' => 'Jurnal Uji']);
        $sub     = JournalSubmission::create(['journal_id' => $journal->id, 'title_id' => $title->id]);

        $this->actingAs($this->user('admin'))
            ->put(route('title.info.update', $title->id), [
                'link_terbit' => 'https://jurnal.test/cermin',
            ])->assertRedirect();

        $this->assertSame('https://jurnal.test/cermin', $sub->fresh()->link_publish);
    }

    /** Tanpa baris submission, penyimpanan tetap berhasil — direktori menyusul. */
    /** @test */
    public function tanpa_baris_submission_tetap_tersimpan(): void
    {
        [$title] = $this->naskah();

        $this->actingAs($this->user('admin'))
            ->put(route('title.info.update', $title->id), [
                'link_terbit' => 'https://jurnal.test/tanpa-submission',
            ])->assertRedirect();

        $this->assertSame('https://jurnal.test/tanpa-submission', $title->fresh()->link_terbit);
        $this->assertSame(0, JournalSubmission::count());
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=NaskahInfoPublikasiTest`
Expected: FAIL — `link_terbit` tidak tersimpan (belum divalidasi, jadi tak diteruskan).

- [ ] **Step 3: Terima `link_terbit` + redirect di controller**

Di `app/Http/Controllers/Pages/TitleController.php`, method `updateInfo()`:

Tambahkan ke array validasi, setelah baris `'catatan_publikasi' => 'nullable|string',`:

```php
            'link_terbit'                   => 'nullable|url|max:500',
            '_redirect'                     => 'nullable|string|max:255',
```

Ganti baris `return redirect()->route(...)` di akhir method dengan:

```php
        // Form ini dipakai dari halaman judul DAN dari layar naskah. Tanpa ini,
        // menyimpan dari layar naskah melempar orang ke halaman judul dan konteks
        // kerjanya hilang. Hanya menerima path internal — URL absolut dari luar
        // ditolak supaya form ini tak bisa dipakai sebagai batu loncatan.
        $kembali = (string) $request->input('_redirect', '');
        if ($kembali !== '' && str_starts_with($kembali, url('/'))) {
            return redirect()->to($kembali)->with('success', 'Informasi publikasi diperbarui.');
        }

        return redirect()->route('title.show', $title->id)->with('success', 'Informasi publikasi diperbarui.');
```

- [ ] **Step 4: Tulis kolom + cermin di service**

Di `app/Services/TitleService.php`, method `updateInfo()`:

Tambahkan ke array `$labels`, setelah `'catatan_publikasi' => 'Catatan',`:

```php
            'link_terbit' => 'Link terbit',
```

Tambahkan ke array `$next`, setelah `'catatan_publikasi' => ...`:

```php
            'link_terbit'       => ($data['link_terbit'] ?? '') ?: null,
```

Lalu, tepat sebelum `return;` di akhir method (atau setelah `$title->update($next)` —
cari baris yang menyimpan `$next`), tambahkan:

```php
        $this->cerminkanLinkKeDirektori($title->fresh());
```

Dan tambahkan method ini ke kelas yang sama:

```php
    /**
     * Salin link terbit judul ke Direktori Jurnal, bila barisnya SUDAH ada.
     *
     * Sengaja tidak membuat baris baru: `tb_journal_submissions.journal_id` NOT NULL dan
     * ber-FK ke direktori, jadi membuatnya menuntut pemilihan jurnal terdaftar — dan
     * jurnal yang belum terdaftar akan mengunci naskahnya dari publish. Judul tetap jadi
     * sumber kanonik; direktori menyusul lewat modulnya sendiri.
     */
    private function cerminkanLinkKeDirektori(Title $title): void
    {
        $link = trim((string) $title->link_terbit);
        if ($link === '' || $title->jenis === 'buku') {
            return;
        }

        \App\Models\JournalSubmission::where('title_id', $title->id)
            ->latest('id')->first()?->update(['link_publish' => $link]);
    }
```

- [ ] **Step 4b: Munculkan field di halaman judul juga**

Task 3 sampai di sini baru membuat backend-nya menerima `link_terbit`; belum ada satu pun
layar yang bisa mengisinya. Task 4 memberi input itu di `/naskah/{id}`, tapi keputusan
§6.A berbunyi "diisi di `/titles/{id}`, **dan kalau belum**, bisa diisi di `/naskah/{id}`"
— jadi halaman judul harus punya inputnya lebih dulu, bukan hanya layar naskah.

Di `resources/views/titles/show.blade.php`, di dalam `<dl>` kartu **Informasi Publikasi**,
tambahkan sebuah baris tepat setelah baris `Catatan`:

```blade
        <dt class="col-sm-4 text-muted small">{{ $title->jenis === 'buku' ? 'Link Buku Terbit' : 'Link Artikel Terbit' }}</dt>
        <dd class="col-sm-8">
            @php $lt = $title->linkTerbit(); @endphp
            @if($lt)<a href="{{ $lt }}" target="_blank" rel="noopener">{{ $lt }}</a>@else <span class="text-danger">belum diisi</span> @endif
        </dd>
```

Label sengaja menyebut jenisnya. Kartu ISBN di halaman yang sama sudah punya baris
berjudul "Link Terbit" (`titles/show.blade.php:296`) yang membaca `$isbn->link_terbit`
langsung — dua baris berjudul sama persis di satu halaman akan membuat orang mengira
keduanya field yang sama.

Lalu di form edit (`<div class="collapse mt-3" id="infoForm">`), tambahkan field tepat
sebelum blok `Catatan`:

```blade
            <div class="mb-2">
                <label class="form-label">{{ $title->jenis === 'buku' ? 'Link Buku Terbit' : 'Link Artikel Terbit' }}</label>
                <input type="url" name="link_terbit" class="form-control @error('link_terbit') is-invalid @enderror"
                       value="{{ old('link_terbit', $title->link_terbit) }}" placeholder="https://...">
                @error('link_terbit')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <small class="text-muted">Wajib diisi sebelum naskah bisa ditandai terbit/publish.</small>
            </div>
```

Perhatikan `value` memakai `$title->link_terbit` (kolom mentah), **bukan** `linkTerbit()`:
form ini menulis kolom judul, jadi ia harus menampilkan isi kolom itu apa adanya. Memuat
nilai cadangan ke dalam input akan diam-diam menyalin data dari modul lain ke judul begitu
form disimpan.

Tambahkan satu test ke `tests/Feature/NaskahInfoPublikasiTest.php`:

```php
    /** @test */
    public function halaman_judul_juga_punya_field_link_terbit(): void
    {
        [$title] = $this->naskah();

        $this->actingAs($this->user('admin'))->get(route('title.show', $title->id))
            ->assertOk()
            ->assertSee('Link Artikel Terbit');
    }
```

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=NaskahInfoPublikasiTest`
Expected: PASS (6 test).

- [ ] **Step 6: Pastikan halaman judul tidak rusak**

Run: `php artisan test --filter="Title|JournalSubmission|Journal"`
Expected: PASS semua.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Pages/TitleController.php app/Services/TitleService.php tests/Feature/NaskahInfoPublikasiTest.php
git commit -m "judul: link terbit bisa disimpan dan mencerminkan ke direktori jurnal"
```

---

## Task 4: Panel Informasi Publikasi di layar naskah

**Files:**
- Create: `resources/views/naskah/partials/informasi-publikasi.blade.php`
- Modify: `app/Http/Controllers/Pages/Naskah/DetailNaskahController.php` (`show`, ~baris 45)
- Modify: `resources/views/naskah/detail.blade.php`
- Test: `tests/Feature/NaskahInfoPublikasiTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Tambahkan ke `tests/Feature/NaskahInfoPublikasiTest.php`:

```php
    /** @test */
    public function layar_naskah_menampilkan_informasi_publikasi(): void
    {
        [$title, $progress] = $this->naskah();
        $title->update(['jurnal_target' => 'Jurnal Pendidikan Nusantara', 'apc_info' => 'Rp 1.500.000']);

        $this->actingAs($this->user('admin'))
            ->get(route('naskah.show', $progress->order_detail_id))
            ->assertOk()
            ->assertSee('Informasi Publikasi')
            ->assertSee('Jurnal Pendidikan Nusantara')
            ->assertSee('Rp 1.500.000');
    }

    /** @test */
    public function admin_melihat_form_edit_di_layar_naskah(): void
    {
        [, $progress] = $this->naskah();

        $this->actingAs($this->user('admin'))
            ->get(route('naskah.show', $progress->order_detail_id))
            ->assertOk()
            ->assertSee('Edit Informasi Publikasi');
    }

    /**
     * Pelaksana (production) tidak memegang `title.info` — panelnya harus terbaca,
     * tapi tanpa form. Kalau formnya bocor ke mereka, penyimpanannya toh akan 403 dan
     * yang muncul cuma layar error, bukan penolakan yang bisa dipahami.
     *
     * @test
     */
    public function production_melihat_panel_tanpa_form_edit(): void
    {
        [$title, $progress] = $this->naskah();
        $title->update(['jurnal_target' => 'Jurnal Pendidikan Nusantara']);

        $this->actingAs($this->user('production'))
            ->get(route('naskah.show', $progress->order_detail_id))
            ->assertOk()
            ->assertSee('Jurnal Pendidikan Nusantara')
            ->assertDontSee('Edit Informasi Publikasi');
    }

    /** Peringatan link kosong harus muncul saat tahap berikutnya adalah tahap akhir. */
    /** @test */
    public function peringatan_muncul_saat_satu_langkah_sebelum_publish(): void
    {
        [, $progress] = $this->naskah(); // status 'loa' → next 'publish'

        $this->actingAs($this->user('admin'))
            ->get(route('naskah.show', $progress->order_detail_id))
            ->assertOk()
            ->assertSee('Link Artikel Terbit belum diisi', false);
    }
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=NaskahInfoPublikasiTest`
Expected: FAIL pada empat test baru — panelnya belum ada.

- [ ] **Step 3: Kirim data ke view**

Di `app/Http/Controllers/Pages/Naskah/DetailNaskahController.php`, method `show()`,
tambahkan dua kunci ke array yang dikirim ke `view('naskah.detail', [...])`, setelah
`'izin' => $this->permissions($actor),`:

```php
            'title'       => $book,
            'canEditInfo' => $actor->can('title.info'),
```

`$book` sudah ada di method itu (`$progress->orderDetail->titleRef`), jadi tak ada query
baru. Ia bisa `null` untuk order lama yang belum tertaut judul — partial-nya harus
menangani itu.

- [ ] **Step 4: Buat partial**

Buat `resources/views/naskah/partials/informasi-publikasi.blade.php`:

```blade
{{--
    Informasi Publikasi — cermin dari kartu yang sama di halaman judul.

    Ada di sini supaya PJ tak perlu pindah halaman saat naskahnya sedang dikerjakan.
    Menulisnya lewat `title.info.update` yang SAMA — bukan jalur kedua — supaya aturan
    validasinya tak bercabang dua versi. `_redirect` yang membawa orang kembali ke sini.

    Untuk buku kolaborasi, isian ini milik JUDUL, bukan order yang sedang dibuka.
--}}
@if ($title)
<div class="card mb-3"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="text-uppercase text-muted small fw-bold mb-0">Informasi Publikasi</h6>
        @if ($canEditInfo)
            <button type="button" class="btn btn-sm btn-outline-primary py-0"
                    data-bs-toggle="collapse" data-bs-target="#infoPublikasiNaskah">
                Edit Informasi Publikasi
            </button>
        @endif
    </div>

    @if ($isKolab)
        <p class="text-muted small mb-2">Isian ini berlaku untuk seluruh judul, bukan hanya order ini.</p>
    @endif

    @php
        $linkTerbit = $title->linkTerbit();
        $labelLink  = $buku ? 'Link Buku Terbit' : 'Link Artikel Terbit';
        $baris = [
            'Target terbit'  => optional($title->target_terbit)->translatedFormat('j M Y') ?? '—',
            'Jurnal target'  => $title->jurnal_target ?: '—',
            'Template'       => $title->template_link ?: '—',
            'APC'            => $title->apc_info ?: '—',
            'Catatan'        => $title->catatan_publikasi ?: '—',
        ];
    @endphp

    @foreach ($baris as $label => $isi)
        <div class="d-flex justify-content-between border-bottom border-dashed py-2 small">
            <span class="text-muted">{{ $label }}</span>
            <strong class="text-end">{{ $isi }}</strong>
        </div>
    @endforeach

    <div class="d-flex justify-content-between border-bottom border-dashed py-2 small">
        <span class="text-muted">{{ $labelLink }}</span>
        <strong class="text-end">
            @if ($linkTerbit)
                <a href="{{ $linkTerbit }}" target="_blank" rel="noopener">buka</a>
            @else
                <span class="text-danger">belum diisi</span>
            @endif
        </strong>
    </div>

    @if (! $linkTerbit && $next && \App\Models\TitleProgress::isFinal($next))
        <div class="alert alert-warning small mt-3 mb-0">
            {{ $labelLink }} belum diisi — naskah belum bisa ditandai
            {{ \App\Models\TitleProgress::labelFor($next) }}.
        </div>
    @endif

    @if ($canEditInfo)
    <div class="collapse mt-3" id="infoPublikasiNaskah">
        <form method="POST" action="{{ route('title.info.update', $title->id) }}">
            @csrf @method('PUT')
            <input type="hidden" name="_redirect" value="{{ route('naskah.show', $progress->order_detail_id) }}">

            <div class="mb-2">
                <label class="form-label small">{{ $labelLink }}</label>
                <input type="url" name="link_terbit" class="form-control form-control-sm @error('link_terbit') is-invalid @enderror"
                       value="{{ old('link_terbit', $title->link_terbit) }}" placeholder="https://...">
                @error('link_terbit')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-2">
                <label class="form-label small">Jurnal Target</label>
                <input type="text" name="jurnal_target" class="form-control form-control-sm"
                       value="{{ old('jurnal_target', $title->jurnal_target) }}">
            </div>
            <div class="mb-2">
                <label class="form-label small">Link Jurnal</label>
                <input type="text" name="jurnal_link" class="form-control form-control-sm"
                       value="{{ old('jurnal_link', $title->jurnal_link) }}">
            </div>
            <div class="mb-2">
                <label class="form-label small">Link Template Artikel</label>
                <input type="text" name="template_link" class="form-control form-control-sm"
                       value="{{ old('template_link', $title->template_link) }}">
            </div>
            <div class="mb-2">
                <label class="form-label small">APC</label>
                <input type="text" name="apc_info" class="form-control form-control-sm"
                       value="{{ old('apc_info', $title->apc_info) }}">
            </div>
            <div class="mb-2">
                <label class="form-label small">Catatan</label>
                <textarea name="catatan_publikasi" class="form-control form-control-sm" rows="2">{{ old('catatan_publikasi', $title->catatan_publikasi) }}</textarea>
            </div>

            {{-- Kode & Opsi Jurnal sengaja TIDAK di sini: keduanya urusan tata kelola
                 judul, bukan pekerjaan harian naskah. Kosong berarti updateInfo()
                 meregenerasi kode dari judul — karena itu kode dikirim apa adanya. --}}
            <input type="hidden" name="code" value="{{ $title->code }}">

            <button class="btn btn-sm btn-primary">Simpan</button>
        </form>
    </div>
    @endif
</div></div>
@endif
```

- [ ] **Step 5: Sisipkan partial**

Di `resources/views/naskah/detail.blade.php`, tepat setelah baris
`@include('naskah.partials.file-naskah', ...)` (di dalam `<div class="col-lg-5">`),
tambahkan:

```blade
        @include('naskah.partials.informasi-publikasi', compact('title', 'canEditInfo', 'progress', 'next', 'buku', 'isKolab'))
```

- [ ] **Step 6: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=NaskahInfoPublikasiTest`
Expected: PASS (9 test).

- [ ] **Step 7: Pastikan layar naskah tidak rusak**

Run: `php artisan test --filter="Naskah|ProductionWorkspace|TombolKembali"`
Expected: PASS semua.

- [ ] **Step 8: Commit**

```bash
git add resources/views/naskah/partials/informasi-publikasi.blade.php resources/views/naskah/detail.blade.php app/Http/Controllers/Pages/Naskah/DetailNaskahController.php tests/Feature/NaskahInfoPublikasiTest.php
git commit -m "naskah: informasi publikasi bisa dibaca dan diperbarui tanpa pindah halaman"
```

---

## Task 5: Kolom Pembayaran lima nilai di daftar order

**Files:**
- Modify: `app/Models/Order.php`
- Modify: `resources/views/orders/book/index.blade.php`
- Test: `tests/Feature/OrderLabelPembayaranTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/OrderLabelPembayaranTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderLabelPembayaranTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function order(int $biaya = 500000): Order
    {
        $order = Order::factory()->create();
        OrderDetail::factory()->create([
            'order_id' => $order->id, 'type' => 'at_mandiri', 'cost_amount' => $biaya,
        ]);

        return $order->fresh();
    }

    private function bayar(Order $order, int $jumlah, string $tipe = 'dp'): void
    {
        Payment::create(['order_id' => $order->id, 'payment_type' => $tipe,
                         'amount' => $jumlah, 'status' => 'paid', 'paid_at' => now()]);
    }

    /** @test */
    public function tanpa_pembayaran_berarti_menunggu(): void
    {
        $this->assertSame('Menunggu', $this->order()->labelPembayaran());
    }

    /** @test */
    public function pembayaran_sebagian_berarti_dp(): void
    {
        $order = $this->order(500000);
        $this->bayar($order, 200000);

        $this->assertSame('DP', $order->fresh()->labelPembayaran());
    }

    /** @test */
    public function pembayaran_penuh_berarti_lunas(): void
    {
        $order = $this->order(500000);
        $this->bayar($order, 500000, 'lunas');

        $this->assertSame('Lunas', $order->fresh()->labelPembayaran());
    }

    /**
     * Jebakan yang harus ditahan: PaymentBookController::approve() mencap invoice 'lunas'
     * untuk SETIAP payment disetujui termasuk DP, dan Order::isLunas() mengambil jalan
     * pintas itu. Kalau label ini memakai isLunas(), nilai 'DP' tak akan pernah muncul.
     *
     * @test
     */
    public function dp_tetap_dp_walau_invoicenya_tercap_lunas(): void
    {
        $order = $this->order(500000);
        $this->bayar($order, 200000);
        \App\Models\Invoice::create([
            'order_id' => $order->id, 'invoice_no' => 'INV-' . uniqid(), 'status' => 'lunas',
        ]);

        $this->assertTrue($order->fresh()->isLunas(), 'prasyarat: jalan pintas memang aktif');
        $this->assertSame('DP', $order->fresh()->labelPembayaran());
    }

    /** @test */
    public function refund_menang_atas_lunas(): void
    {
        $order = $this->order(500000);
        $this->bayar($order, 500000, 'lunas');
        $this->bayar($order, 500000, 'refund');

        $this->assertSame('Refund', $order->fresh()->labelPembayaran());
    }

    /** @test */
    public function dibatalkan_menang_atas_semuanya(): void
    {
        $order = $this->order(500000);
        $this->bayar($order, 500000, 'lunas');
        $this->bayar($order, 500000, 'refund');
        $order->update(['status' => 'dibatalkan']);

        $this->assertSame('Dibatalkan', $order->fresh()->labelPembayaran());
    }

    /** @test */
    public function daftar_order_menampilkan_kolom_pembayaran(): void
    {
        $order = $this->order(500000);
        $this->bayar($order, 200000);

        $sa = User::factory()->create();
        $sa->assignRole('superadmin');

        $this->actingAs($sa->fresh())->get(route('order.book.index'))
            ->assertOk()
            ->assertSee('Pembayaran')
            ->assertSee('DP')
            ->assertDontSee('Diproses');
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=OrderLabelPembayaranTest`
Expected: FAIL — `Call to undefined method App\Models\Order::labelPembayaran()`.

- [ ] **Step 3: Tambah `labelPembayaran()` di `Order`**

Di `app/Models/Order.php`, tambahkan tepat di bawah `isLunas()`:

```php
    /**
     * Label keadaan UANG untuk daftar order — lima nilai turunan, bukan kolom.
     *
     * Menggantikan cabang lama "Diproses", yang sebenarnya hanya berarti
     * `status == 'lunas'` tapi berbunyi seperti status pekerjaan — order yang naskahnya
     * sudah terbit pun tetap tertulis "Diproses". Keadaan pekerjaan kini punya kolomnya
     * sendiri (`fulfillment_status`).
     *
     * SENGAJA TIDAK memakai isLunas(): jalan pintas invoice di sana membuat satu DP
     * terbaca lunas (PaymentBookController::approve() mencap invoice 'lunas' untuk
     * setiap payment disetujui), sehingga nilai 'DP' tak akan pernah muncul.
     *
     * Urutan menang: Dibatalkan > Refund > Lunas > DP > Menunggu.
     */
    public function labelPembayaran(): string
    {
        if ($this->isCancelled()) {
            return 'Dibatalkan';
        }

        // Pakai koleksi yang sudah di-eager load bila ada: daftar order memanggil ini
        // sekali per baris, dan query baru per baris akan mengembalikan N+1.
        $payments = $this->relationLoaded('payments') ? $this->payments : $this->payments()->get();
        $lunas    = $payments->where('status', 'paid');

        if ($lunas->where('payment_type', 'refund')->isNotEmpty()) {
            return 'Refund';
        }

        $masuk = (int) $lunas->where('payment_type', '!=', 'refund')->sum('amount');
        if ($masuk <= 0) {
            return 'Menunggu';
        }

        return $masuk >= (int) optional($this->details)->cost_amount ? 'Lunas' : 'DP';
    }
```

- [ ] **Step 4: Pakai di view**

Di `resources/views/orders/book/index.blade.php`, ganti judul kolom
`<th>Status Orderan</th>` menjadi:

```blade
                                        <th>Pembayaran</th>
```

Lalu ganti seluruh sel status lama:

```blade
                                            <td>
                                                @if ($order->isCancelled())
                                                    <span class="badge bg-secondary">Dibatalkan</span>
                                                @elseif ($order->status == 'pending')
                                                    <span class="badge bg-warning text-dark">Menunggu</span>
                                                @else
                                                    <span class="badge bg-success">Diproses</span>
                                                @endif
                                            </td>
```

dengan:

```blade
                                            <td>
                                                @php $bayar = $order->labelPembayaran(); @endphp
                                                <span class="badge {{ [
                                                    'Lunas'      => 'bg-success',
                                                    'DP'         => 'bg-warning text-dark',
                                                    'Menunggu'   => 'bg-light text-dark border',
                                                    'Refund'     => 'bg-info',
                                                    'Dibatalkan' => 'bg-secondary',
                                                ][$bayar] ?? 'bg-light text-dark' }}">{{ $bayar }}</span>
                                            </td>
```

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=OrderLabelPembayaranTest`
Expected: PASS (7 test).

- [ ] **Step 6: Pastikan daftar order tidak rusak**

Run: `php artisan test --filter="OrderCancel|OrderRestore|OrderPemilik|OrderEditGate|OrderWithdrawal|PaidNet|DetailOrderPaymentInvoice"`
Expected: PASS semua.

- [ ] **Step 7: Commit**

```bash
git add app/Models/Order.php resources/views/orders/book/index.blade.php tests/Feature/OrderLabelPembayaranTest.php
git commit -m "order: kolom uang berhenti mengaku 'Diproses', kini menyebut DP dan Refund"
```

---

## Task 6: Artefak membaca berkas yang sudah diunggah

**Files:**
- Modify: `app/Services/TitleArchivalService.php` (`defaultArtifacts`, ~baris 18)
- Test: `tests/Feature/ArtefakPrefillTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/ArtefakPrefillTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\BookIsbn;
use App\Models\ManuscriptFile;
use App\Models\Title;
use App\Services\GoogleDriveService;
use App\Services\TitleArchivalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ArtefakPrefillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleDriveService::class);
        foreach (['marketing', 'production', 'admin', 'manager', 'superadmin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function buku(): Title
    {
        return Title::create(['title' => 'Buku Artefak', 'jenis' => 'buku',
                              'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
    }

    private function berkas(Title $t, string $slot, string $url, string $status = 'selesai'): void
    {
        ManuscriptFile::create([
            'title_id' => $t->id, 'slot' => $slot, 'status' => $status, 'version' => 1,
            'original_name' => $slot . '.pdf', 'drive_url' => $url,
        ]);
    }

    /** @return array<string,array> artefak dikunci per `key` */
    private function artefak(Title $t): array
    {
        return collect(app(TitleArchivalService::class)->defaultArtifacts($t->fresh()))
            ->keyBy('key')->all();
    }

    /** @test */
    public function berkas_isbn_mengisi_artefak_buku(): void
    {
        $t = $this->buku();
        $this->berkas($t, 'barcode_isbn', 'https://drive.test/barcode');
        $this->berkas($t, 'sertifikat_hki', 'https://drive.test/hki');
        $this->berkas($t, 'ebook', 'https://drive.test/ebook');

        $a = $this->artefak($t);

        $this->assertSame('https://drive.test/barcode', $a['barcode_file']['value']);
        $this->assertSame('https://drive.test/hki', $a['hki_file']['value']);
        $this->assertSame('https://drive.test/ebook', $a['final_book_file']['value']);
    }

    /** @test */
    public function link_terbit_isbn_mengisi_artefak_publish_link(): void
    {
        $t = $this->buku();
        BookIsbn::create(['title_id' => $t->id, 'status' => 'cetak',
                          'no_isbn' => '978-000', 'link_terbit' => 'https://toko.test/buku']);

        $a = $this->artefak($t);

        $this->assertSame('978-000', $a['isbn']['value']);
        $this->assertSame('https://toko.test/buku', $a['publish_link']['value']);
    }

    /**
     * Berkas yang masih ANTRE belum punya URL Drive. Menghitungnya sebagai "sudah ada"
     * membuat arsip mengaku memegang berkas yang belum mendarat — kesalahan yang sudah
     * pernah ditambal di validasi ISBN.
     *
     * @test
     */
    public function berkas_antre_tidak_dihitung(): void
    {
        $t = $this->buku();
        $this->berkas($t, 'barcode_isbn', '', 'antre');

        $this->assertNull($this->artefak($t)['barcode_file']['value']);
    }

    /** @test */
    public function versi_terbaru_yang_dipakai(): void
    {
        $t = $this->buku();
        $this->berkas($t, 'ebook', 'https://drive.test/v1');
        ManuscriptFile::create([
            'title_id' => $t->id, 'slot' => 'ebook', 'status' => 'selesai', 'version' => 2,
            'original_name' => 'ebook.pdf', 'drive_url' => 'https://drive.test/v2',
        ]);

        $this->assertSame('https://drive.test/v2', $this->artefak($t)['final_book_file']['value']);
    }

    /** @test */
    public function artikel_mengambil_loa_dan_naskah_final_dari_berkas(): void
    {
        $t = Title::create(['title' => 'Artikel Artefak', 'jenis' => 'artikel',
                            'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $this->berkas($t, 'loa', 'https://drive.test/loa');
        $this->berkas($t, 'final', 'https://drive.test/final');

        $a = $this->artefak($t);

        $this->assertSame('https://drive.test/loa', $a['loa']['value']);
        $this->assertSame('https://drive.test/final', $a['final_naskah']['value']);
    }

    /** Nilai yang sudah disimpan manual tak boleh ditimpa prefill. */
    /** @test */
    public function nilai_tersimpan_menang_atas_prefill(): void
    {
        $t = $this->buku();
        $this->berkas($t, 'ebook', 'https://drive.test/otomatis');
        \App\Models\TitleArchiveArtifact::create([
            'title_id' => $t->id, 'key' => 'final_book_file', 'label' => 'File Buku Final (ber-ISBN)',
            'type' => 'file', 'value' => 'https://manual.test/pilihan', 'is_custom' => false,
        ]);

        $this->assertSame('https://manual.test/pilihan', $this->artefak($t)['final_book_file']['value']);
    }
```

Tutup kelasnya dengan `}`.

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=ArtefakPrefillTest`
Expected: FAIL — `barcode_file`, `hki_file`, `final_book_file`, `final_naskah`, dan
`publish_link` buku semuanya masih null.

- [ ] **Step 3: Perluas prefill**

Di `app/Services/TitleArchivalService.php`, ganti awal `defaultArtifacts()` sampai baris
`$prefill = [...]` dengan:

```php
    /** Daftar artefak baku (dengan prefill dari data existing) untuk render form. */
    public function defaultArtifacts(Title $title): array
    {
        $existing   = $title->archiveArtifacts->keyBy('key');
        $submission = JournalSubmission::where('title_id', $title->id)->latest()->first();
        $berkas     = $this->berkasTerbaru($title);

        /*
         | Artefak yang datanya sudah diisi di modul lain tidak perlu diketik ulang.
         | Sumbernya dicatat di $sumber supaya UI bisa menyebut "dari Direktori ISBN" —
         | tanpa itu orang tak tahu harus mengubahnya di mana.
         */
        $prefill = [
            'isbn'            => optional($title->bookIsbn)->no_isbn,
            // Lewat linkTerbit(), BUKAN cabang jenis buatan sendiri: kolom judul harus
            // ikut terbaca. Tanpa itu, link yang diisi lewat form Informasi Publikasi
            // tampil di layar naskah tapi arsip tetap berkata "belum diisi" — dua tempat
            // berbeda isi atas data yang sama.
            'publish_link'    => $title->linkTerbit(),
            'barcode_file'    => $berkas['barcode_isbn']   ?? null,
            'hki_file'        => $berkas['sertifikat_hki'] ?? null,
            'final_book_file' => $berkas['ebook']          ?? null,
            'loa'             => optional($submission)->loa_url ?: ($berkas['loa'] ?? null),
            'final_naskah'    => $berkas['final'] ?? null,
            'apc_bukti'       => optional($submission)->bukti_bayar_url,
        ];

        $sumber = [
            'isbn'            => 'Direktori ISBN',
            'publish_link'    => trim((string) $title->link_terbit) !== ''
                                    ? 'Informasi Publikasi'
                                    : ($title->jenis === 'buku' ? 'Direktori ISBN' : 'Direktori Jurnal'),
            'barcode_file'    => 'Berkas ISBN',
            'hki_file'        => 'Berkas ISBN',
            'final_book_file' => 'Berkas ISBN',
            'loa'             => optional($submission)->loa_url ? 'Direktori Jurnal' : 'Detail Naskah',
            'final_naskah'    => 'Detail Naskah',
            'apc_bukti'       => 'Direktori Jurnal',
        ];
```

Lalu di dalam `foreach` yang membangun `$out[]`, tambahkan dua kunci baru ke array yang
di-push (setelah `'note' => $row->note ?? null,`):

```php
                'dari_luar'   => $row === null && ($prefill[$key] ?? null) !== null,
                'sumber'      => $sumber[$key] ?? null,
```

Tambahkan method ini ke kelas yang sama:

```php
    /**
     * URL Drive versi terbaru per slot, untuk SELURUH slot sekaligus.
     *
     * Satu query — BookIsbn::berkas() sudah memperingatkan jangan dipanggil di dalam
     * perulangan, dan di sini ada sampai lima slot yang dicari.
     *
     * Hanya berkas berstatus 'selesai' yang dihitung: yang masih 'antre' belum punya
     * `drive_url`, dan menampilkannya sebagai artefak lengkap adalah klaim palsu.
     *
     * @return array<string,string> slot => drive_url
     */
    private function berkasTerbaru(Title $title): array
    {
        return \App\Models\ManuscriptFile::where('title_id', $title->id)
            ->whereNull('title_chapter_id')
            ->where('status', 'selesai')
            ->orderBy('slot')
            ->orderByDesc('version')
            ->get(['slot', 'drive_url'])
            ->groupBy('slot')
            ->map(fn ($rows) => (string) $rows->first()->drive_url)
            ->filter(fn (string $url) => $url !== '')
            ->all();
    }
```

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=ArtefakPrefillTest`
Expected: PASS (6 test).

- [ ] **Step 5: Pastikan arsip tidak rusak**

Run: `php artisan test --filter="Archive|TitleArchive|BookIsbn|Unggah"`
Expected: PASS semua.

- [ ] **Step 6: Commit**

```bash
git add app/Services/TitleArchivalService.php tests/Feature/ArtefakPrefillTest.php
git commit -m "arsip: artefak memungut data yang sudah diisi di modul lain"
```

---

## Task 7: Artefak terisi tampil sebagai informasi, bukan form kosong

**Files:**
- Modify: `resources/views/archive/show.blade.php`
- Test: `tests/Feature/ArtefakPrefillTest.php`

- [ ] **Step 1: Tulis test yang gagal**

Tambahkan ke `tests/Feature/ArtefakPrefillTest.php`. Tambahkan dulu import
`App\Models\User`, lalu:

```php
    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u->fresh();
    }

    /** @test */
    public function artefak_terisi_menyebut_sumbernya(): void
    {
        $t = $this->buku();
        BookIsbn::create(['title_id' => $t->id, 'status' => 'cetak', 'no_isbn' => '978-111']);

        $this->actingAs($this->user('admin'))->get(route('archive.show', $t->id))
            ->assertOk()
            ->assertSee('978-111')
            ->assertSee('Direktori ISBN');
    }

    /** @test */
    public function kartu_menyebut_berapa_artefak_yang_kurang(): void
    {
        $t = $this->buku();
        BookIsbn::create(['title_id' => $t->id, 'status' => 'cetak', 'no_isbn' => '978-222']);

        // Buku punya 6 artefak baku; baru 1 terisi.
        $this->actingAs($this->user('admin'))->get(route('archive.show', $t->id))
            ->assertOk()
            ->assertSee('kurang 5 dari 6');
    }

    /** @test */
    public function artefak_lengkap_tidak_menampilkan_peringatan_kurang(): void
    {
        $t = $this->buku();
        BookIsbn::create(['title_id' => $t->id, 'status' => 'cetak',
                          'no_isbn' => '978-333', 'link_terbit' => 'https://toko.test/b']);
        $this->berkas($t, 'barcode_isbn', 'https://drive.test/barcode');
        $this->berkas($t, 'sertifikat_hki', 'https://drive.test/hki');
        $this->berkas($t, 'ebook', 'https://drive.test/ebook');
        \App\Models\TitleArchiveArtifact::create([
            'title_id' => $t->id, 'key' => 'scholar_link', 'label' => 'Link Scholar',
            'type' => 'link', 'value' => 'https://scholar.test/b', 'is_custom' => false,
        ]);

        $this->actingAs($this->user('admin'))->get(route('archive.show', $t->id))
            ->assertOk()
            ->assertSee('Artefak lengkap');
    }
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=ArtefakPrefillTest`
Expected: FAIL pada tiga test baru — viewnya belum menyebut sumber maupun ringkasan.

- [ ] **Step 3: Ringkasan di kartu**

Di `resources/views/archive/show.blade.php`, tepat di bawah baris
`<h6 class="card-title">Artefak Penyelesaian</h6>`, tambahkan:

```blade
    @php
        $terisi = collect($artifacts)->filter(fn ($a) => ($a['value'] ?? null) !== null)->count();
        $total  = count($artifacts);
    @endphp
    @if ($terisi >= $total)
        <p class="small text-success mb-2">✓ Artefak lengkap ({{ $total }} dari {{ $total }}).</p>
    @else
        <p class="small text-warning mb-2">
            Masih kurang {{ $total - $terisi }} dari {{ $total }} artefak.
            Yang sudah terisi diambil otomatis dari modul asalnya.
        </p>
    @endif
```

- [ ] **Step 4: Sebutkan sumber pada artefak terisi**

Di berkas yang sama, di dalam `@foreach($artifacts as $a)`, ganti blok ini:

```blade
            <div class="border rounded p-2 mb-2">
                <div class="fw-semibold small mb-1">{{ $a['label'] }}</div>
                <div class="row g-2">
                    <div class="col-md-5">
                        @if($a['type'] === 'file')
                            @if($a['value'])<a href="{{ $a['value'] }}" target="_blank" rel="noopener" class="d-block text-truncate small">📎 {{ $a['file_name'] ?: 'file' }}</a>@endif
                            <input type="file" name="fixed[{{ $a['key'] }}][file]" class="form-control form-control-sm">
                        @else
                            <input type="text" name="fixed[{{ $a['key'] }}][value]" value="{{ $a['value'] }}" class="form-control form-control-sm" placeholder="{{ $a['type'] === 'link' ? 'https://…' : 'Nilai' }}">
                        @endif
                    </div>
```

dengan:

```blade
            <div class="border rounded p-2 mb-2 {{ $a['value'] ? 'border-success-subtle' : '' }}">
                <div class="fw-semibold small mb-1">
                    {{ $a['label'] }}
                    {{-- Sumbernya disebut supaya orang tahu harus mengubahnya DI MANA;
                         tanpa itu mereka akan mengetik ulang di sini dan dua tempat
                         diam-diam berbeda isi. --}}
                    @if(! empty($a['dari_luar']) && ! empty($a['sumber']))
                        <span class="badge bg-light text-dark border" style="font-size:9px">dari {{ $a['sumber'] }}</span>
                    @elseif(! $a['value'])
                        <span class="badge bg-warning text-dark" style="font-size:9px">belum ada</span>
                    @endif
                </div>
                <div class="row g-2">
                    <div class="col-md-5">
                        @if($a['type'] === 'file')
                            @if($a['value'])<a href="{{ $a['value'] }}" target="_blank" rel="noopener" class="d-block text-truncate small">📎 {{ $a['file_name'] ?: 'file' }}</a>@endif
                            <input type="file" name="fixed[{{ $a['key'] }}][file]" class="form-control form-control-sm">
                        @else
                            @if($a['value'])
                                <a href="{{ $a['value'] }}" target="_blank" rel="noopener" class="d-block text-truncate small">{{ $a['value'] }}</a>
                            @endif
                            <input type="text" name="fixed[{{ $a['key'] }}][value]" value="{{ $a['value'] }}" class="form-control form-control-sm"
                                   placeholder="{{ $a['value'] ? 'Ganti (opsional)' : ($a['type'] === 'link' ? 'https://…' : 'Nilai') }}">
                        @endif
                    </div>
```

Catatan penting soal artefak bertipe `text` (hanya `isbn`): nilainya bukan URL, jadi
`<a href>` di cabang itu akan menghasilkan tautan rusak. Bungkus pemasangan tautan dengan
pemeriksaan tipe:

```blade
                            @if($a['value'])
                                @if($a['type'] === 'link')
                                    <a href="{{ $a['value'] }}" target="_blank" rel="noopener" class="d-block text-truncate small">{{ $a['value'] }}</a>
                                @else
                                    <div class="small fw-semibold">{{ $a['value'] }}</div>
                                @endif
                            @endif
```

Pakai versi kedua ini — ia yang benar untuk kedua tipe.

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=ArtefakPrefillTest`
Expected: PASS (9 test).

- [ ] **Step 6: Pastikan arsip & PDF tidak rusak**

Run: `php artisan test --filter="Archive|TitleArchive|ArchivePdf"`
Expected: PASS semua.

- [ ] **Step 7: Commit**

```bash
git add resources/views/archive/show.blade.php tests/Feature/ArtefakPrefillTest.php
git commit -m "arsip: artefak yang sudah ada tampil apa adanya, input hanya untuk yang kurang"
```

---

## Pemeriksaan akhir

- [ ] **Perbarui data uji**

Data demo lama tidak punya link terbit, jadi setelah Task 2 skenario "terbit" di sana
menjadi keadaan yang **tak bisa dicapai lewat UI**. Tambahkan `'link_terbit' => 'https://...'`
pada judul demo yang tahapnya final di `app/Console/Commands/DemoStatusNaskah.php`
(method `artikelTerbitLunas`, `artikelTerbitMenunggak`, `bukuKolabSatuDitarik`,
`bukuKolabDitarikSetelahIsbn`), lalu jalankan ulang:

```bash
php artisan simapa:demo-status --force
```

- [ ] **Seluruh suite hijau**

Run: `php artisan test`
Expected: PASS semua. Baseline sebelum rencana ini: **1169 lulus, 1 dilewati, 0 gagal**.
Suite penuh ~10 menit — jalankan sekali di sini saja, bukan per tugas.

- [ ] **DB dev sudah dimigrasikan**

Run: `php artisan migrate:status | tail -3`
Expected: `2026_08_21_000001_add_link_terbit_to_tb_titles` berstatus `Ran`.

- [ ] **Periksa di browser**

1. `/naskah/{id}` artikel di tahap `loa` — panel Informasi Publikasi muncul, peringatan
   link kosong terlihat, tombol Selesaikan menolak dengan pesan yang menyebut linknya
2. Isi link lewat panel itu — halaman kembali ke `/naskah/{id}`, bukan ke halaman judul
3. `/management/order` — kolom Pembayaran menampilkan Menunggu / DP / Lunas / Refund /
   Dibatalkan, dan kata "Diproses" sudah hilang
4. `/management/archive/{id}` buku ber-ISBN — artefak menyebut "dari Direktori ISBN" dan
   ringkasan "kurang N dari M"
5. Masuk sebagai akun production — panel Informasi Publikasi terbaca, tombol Edit tak ada
