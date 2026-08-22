# Revisi Sesudah Submit — Rencana Implementasi

> **Untuk pekerja agentik:** SUB-SKILL WAJIB: pakai superpowers:subagent-driven-development
> (disarankan) atau superpowers:executing-plans untuk mengerjakan rencana ini tugas demi
> tugas. Langkah memakai checkbox (`- [ ]`) untuk penandaan.

**Tujuan:** Memindahkan tahap `revisi` ke belakang `submit` dan mengubahnya dari label
kosong jadi putaran perbaikan berberkas yang ditujukan ke Pelaksana, lengkap dengan jalan
mundur dari LoA yang tak perlu superadmin.

**Rancangan:** Urutan tahap adalah konstanta PHP, jadi pemindahannya murah — yang mahal
adalah empat jebakan yang sudah terpetakan di spec (T1–T4). Putaran perbaikan disimpan di
tabel sendiri (`tb_manuscript_revisions`) karena putaran bisa ada sebelum berkasnya ada;
berkas menyambung lewat FK dan dua slot baru di `ManuscriptFile::SLOTS` yang tak butuh
migrasi. Jalan mundur memakai `applyGroup()` yang sudah ada, setelah penjaganya dipecah.

**Tumpukan:** Laravel 10.50, MariaDB 10.4, Blade + Bootstrap 5, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-08-22-revisi-setelah-submit-design.md`

**Branch:** `feat/revisi-setelah-submit` (sudah ada, spec ter-commit di `ba8222e`)

---

## Aturan yang berlaku sepanjang rencana ini

- Tes memakai `avidpedi_simapa_test` lewat `.env.testing`. **Jangan** jalankan suite penuh
  tiap tugas — pakai `--filter`. Pastikan tak ada sesi lain yang sedang menjalankan tes
  terhadap DB uji yang sama; kegagalan palsu dari dua proses berbagi satu DB sudah pernah
  menghabiskan waktu di repo ini.
- Mock `GoogleDriveService` **lewat container** (`$this->mock(GoogleDriveService::class)`),
  bukan konstruktor — pernah membuat tes mengunggah ke Drive sungguhan.
- Kolom waktu di model baru dideklarasikan di `$casts`, **tak pernah** `protected $dates`
  (mati sejak Laravel 10, sudah tiga kali menjebak repo ini).
- `git add` selalu dengan path eksplisit. **Jangan** `git add .` atau `-A`.
  Jangan pernah meng-commit `.gitignore`, `avidpedi_simapa.sql`, `template-web/`,
  `data-excel/`, atau `public/error_log`.
- Commit: penulis `WellkitDev <rahmatpurnomo808@gmail.com>`, co-author
  `Mira <admin@avidpedia.com>`. **Jangan** menyebut Claude/Anthropic.
- Setelah migrasi apa pun: jalankan `php artisan migrate` pada DB dev `avidpedi_simapa`
  juga, atau aplikasi hidup akan 500 atas tabel yang belum ada.

---

## Struktur berkas

**Dibuat:**

| Berkas | Tanggung jawab |
|---|---|
| `database/migrations/2026_08_22_000001_create_manuscript_revisions_table.php` | Tabel putaran |
| `database/migrations/2026_08_22_000002_add_revision_id_to_manuscript_files.php` | FK berkas → putaran |
| `database/migrations/2026_08_22_000003_pindahkan_tahap_revisi_lama.php` | Backfill baris `revisi` lama |
| `app/Models/ManuscriptRevision.php` | Model putaran + predikat "terjawab" |
| `app/Services/ManuscriptRevisionService.php` | Buka, jawab, tutup putaran |
| `resources/views/naskah/partials/revisi.blade.php` | Kartu Revisi di layar naskah |
| `tests/Feature/RevisiSetelahSubmitTest.php` | Urutan tahap + gerbang + mundur |
| `tests/Feature/PutaranRevisiTest.php` | Putaran, berkas, izin |

**Diubah:**

| Berkas | Perubahan |
|---|---|
| `app/Models/TitleProgress.php` | `ARTICLE_STAGES` |
| `app/Models/ManuscriptFile.php` | Dua slot baru + relasi `revision()` |
| `app/Services/TitleProgressService.php` | `$bolehMundur` di `applyGroup()`, `kembalikan()`, gerbang maju |
| `app/Services/Notifier.php` | Kata "dikembalikan", penerima pelaksana, `naskahRevisiDiminta()` |
| `app/Http/Controllers/Pages/Naskah/DetailNaskahController.php` | `revisi()` → `kembalikan()`, tiga aksi putaran |
| `app/Http/Controllers/Pages/Naskah/PelacakanNaskahController.php` | `ZONA` berhenti menyalin urutan |
| `routes/web.php` | Empat route |
| `config/permissions.php` | Peta izin route baru |
| `resources/views/naskah/partials/aksi.blade.php` | Tombol mundur |
| `resources/views/naskah/detail.blade.php` | `@include` kartu Revisi |

---

## Task 1: `revisi` pindah ke belakang `submit`

Ini satu-satunya perubahan yang memutus tes lama. Dikerjakan lebih dulu dan fallout-nya
dibereskan di tugas yang sama supaya suite tak pernah ditinggalkan merah.

**Files:**
- Create: `tests/Feature/RevisiSetelahSubmitTest.php`
- Modify: `app/Models/TitleProgress.php:45-48`
- Modify: `tests/Unit/TitleProgressServiceTest.php:182`, `:235`
- Modify: `tests/Feature/NaskahDetailTest.php:80`, `:96`
- Modify: `tests/Feature/LinkTerbitGateTest.php:308`
- Modify: `tests/Feature/NaskahJurnalTest.php:129-150`

- [ ] **Step 1: Tulis tes yang gagal**

Buat `tests/Feature/RevisiSetelahSubmitTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\OrderDetail;
use App\Models\TitleProgress;
use App\Models\User;
use App\Services\GoogleDriveService;
use App\Services\TitleProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RevisiSetelahSubmitTest extends TestCase
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

    private function naskah(string $status, string $type = 'at_mandiri'): TitleProgress
    {
        $detail = OrderDetail::factory()->create(['type' => $type]);

        return TitleProgress::create([
            'order_detail_id' => $detail->id,
            'status'          => $status,
            'assigned_role'   => TitleProgress::getHandlerForStatus($status),
            'started_at'      => now(),
        ]);
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    /** @test */
    public function urutan_tahap_artikel_menaruh_revisi_sesudah_submit(): void
    {
        $urut = TitleProgress::ARTICLE_STAGES;

        $this->assertLessThan(
            array_search('revisi', $urut, true),
            array_search('submit', $urut, true),
            'Reviewer meminta revisi SESUDAH naskah disubmit, bukan sebelumnya.'
        );
        $this->assertLessThan(
            array_search('loa', $urut, true),
            array_search('revisi', $urut, true),
            'Revisi datang sebelum LoA.'
        );
    }

    /** @test */
    public function editing_kini_maju_ke_submit_bukan_revisi(): void
    {
        $p = $this->naskah('editing');

        app(TitleProgressService::class)->advance($p, $this->admin());

        $this->assertSame('submit', $p->fresh()->status);
    }

    /** @test */
    public function submit_maju_ke_revisi_dan_revisi_maju_ke_loa(): void
    {
        $svc = app(TitleProgressService::class);
        $p   = $this->naskah('submit');

        $svc->advance($p, $this->admin());
        $this->assertSame('revisi', $p->fresh()->status);

        $svc->advance($p->fresh(), $this->admin());
        $this->assertSame('loa', $p->fresh()->status);
    }

    /** @test */
    public function urutan_tahap_buku_tidak_ikut_berubah(): void
    {
        $this->assertSame(
            ['menunggu_proses', 'pembuatan', 'editing', 'layout',
             'proofreading', 'isbn', 'cetak', 'terbit'],
            TitleProgress::BOOK_STAGES,
            'Buku tak punya tahap revisi jurnal — urutannya tak boleh tersenggol.'
        );
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal**

```
php artisan test --filter=RevisiSetelahSubmitTest
```

Harapan: `urutan_tahap_artikel_menaruh_revisi_sesudah_submit`,
`editing_kini_maju_ke_submit_bukan_revisi`, dan
`submit_maju_ke_revisi_dan_revisi_maju_ke_loa` **GAGAL**.
`urutan_tahap_buku_tidak_ikut_berubah` **LULUS** sejak awal (pagar, bukan target).

- [ ] **Step 3: Pindahkan tahapnya**

Di `app/Models/TitleProgress.php`, ganti blok `ARTICLE_STAGES`:

```php
    /**
     * Urutan tahap artikel. `revisi` duduk SESUDAH `submit` karena reviewer baru bisa
     * meminta revisi setelah naskahnya masuk — sampai 2026-08-22 urutannya terbalik dan
     * setiap artikel "melewati" revisi dalam perjalanan normalnya.
     *
     * Tahap ini boleh dilewati: tanpa permintaan revisi, tekan maju dan naskah lanjut
     * ke LoA. Lihat docs/superpowers/specs/2026-08-22-revisi-setelah-submit-design.md.
     *
     * JANGAN salin urutan ini ke tempat lain — turunkan dari konstanta ini. Papan
     * Pelacakan dulu menyimpan salinannya sendiri dan langsung basi begitu urutan berubah.
     */
    const ARTICLE_STAGES = [
        'menunggu_proses', 'pembuatan', 'editing',
        'submit', 'revisi', 'loa', 'publish',
    ];
```

- [ ] **Step 4: Jalankan lagi, pastikan lulus**

```
php artisan test --filter=RevisiSetelahSubmitTest
```

Harapan: 4 lulus, 0 gagal.

- [ ] **Step 5: Benahi tes lama yang memakai urutan lama**

Enam suntingan tepat.

`tests/Unit/TitleProgressServiceTest.php:182` — ganti:

```php
        $this->assertEquals('revisi', $p->fresh()->status); // ARTICLE_STAGES: editing → revisi
```

jadi:

```php
        $this->assertEquals('submit', $p->fresh()->status); // ARTICLE_STAGES: editing → submit
```

(Helper `naskah()` di berkas itu berparameter `string $bidang = 'artikel'`, jadi naskahnya
memang artikel dan targetnya memang `submit` — bukan `layout`.)

`tests/Unit/TitleProgressServiceTest.php:235` — di dalam perulangan grup, ganti
`'revisi'` jadi `'submit'`. Helper dan bidangnya sama.

`tests/Feature/NaskahDetailTest.php:80` — ganti:

```php
        $this->assertSame(1, substr_count($isi, 'Selesaikan Editing → lanjut ke Revisi'));
```

jadi:

```php
        $this->assertSame(1, substr_count($isi, 'Selesaikan Editing → lanjut ke Submit'));
```

`tests/Feature/NaskahDetailTest.php:96` — ganti `'revisi'` jadi `'submit'`.

`tests/Feature/LinkTerbitGateTest.php:308` — ganti:

```php
        $this->assertSame('loa', $progress->fresh()->status);
```

jadi:

```php
        $this->assertSame('revisi', $progress->fresh()->status);
```

`tests/Feature/NaskahJurnalTest.php` — di
`menyelesaikan_loa_mengisi_link_terbit_di_submission_yang_sama`, naskah kini harus
melewati Revisi lebih dulu. Sisipkan satu panggilan di antara dua panggilan yang ada,
tepat sebelum komentar "Publish adalah tahap FINAL":

```php
        // Revisi kini duduk di antara Submit dan LoA. Tak ada permintaan revisi di
        // alur ini, jadi tahapnya cukup dilewati — persis yang dilakukan orang saat
        // reviewer tak meminta apa-apa.
        $p->refresh();
        $this->actingAs($super)->post(route('naskah.selesaikan', $p->order_detail_id))
            ->assertRedirect();
```

- [ ] **Step 6: Jalankan seluruh tes naskah**

```
php artisan test --filter="Naskah|TitleProgress|LinkTerbit|Revisi|OrderFulfillment"
```

Harapan: 0 gagal. Bila ada yang merah dan bukan salah satu dari enam di atas, tes itu
menyimpan asumsi urutan yang belum terpetakan — perbaiki dengan cara yang sama, jangan
melunakkan assertion-nya.

- [ ] **Step 7: Commit**

```bash
git add app/Models/TitleProgress.php tests/Feature/RevisiSetelahSubmitTest.php \
        tests/Unit/TitleProgressServiceTest.php tests/Feature/NaskahDetailTest.php \
        tests/Feature/LinkTerbitGateTest.php tests/Feature/NaskahJurnalTest.php
git commit -m "naskah: revisi pindah ke belakang submit, sesuai alur jurnal sebenarnya"
```

---

## Task 2: Papan Pelacakan berhenti menyalin urutan tahap (T4)

**Files:**
- Modify: `app/Http/Controllers/Pages/Naskah/PelacakanNaskahController.php:25-37`
- Test: `tests/Feature/RevisiSetelahSubmitTest.php`

- [ ] **Step 1: Tulis tes yang gagal**

Tambahkan ke `tests/Feature/RevisiSetelahSubmitTest.php`:

```php
    /**
     * ZONA dulu menyalin urutan tahap dengan tangan, jadi memindahkan `revisi` di
     * ARTICLE_STAGES meninggalkan papan menampilkan urutan lama. Tes ini mengunci
     * keduanya tetap sejalan tanpa peduli isi urutannya.
     *
     * @test
     */
    public function kolom_papan_pelacakan_mengikuti_urutan_tahap(): void
    {
        $this->actingAs($this->admin());

        $isi = $this->get(route('naskah.pelacakan', ['tipe' => 'artikel']))
            ->assertOk()->getContent();

        $posSubmit = strpos($isi, 'Submit');
        $posRevisi = strpos($isi, 'Revisi');

        $this->assertNotFalse($posSubmit, 'Kolom Submit harus ada di papan.');
        $this->assertNotFalse($posRevisi, 'Kolom Revisi harus ada di papan.');
        $this->assertLessThan($posRevisi, $posSubmit,
            'Kolom papan harus mengikuti ARTICLE_STAGES, bukan salinan yang ditulis tangan.');
    }
```

- [ ] **Step 2: Jalankan, pastikan gagal**

```
php artisan test --filter=kolom_papan_pelacakan_mengikuti_urutan_tahap
```

Harapan: GAGAL — Revisi masih tampil sebelum Submit.

- [ ] **Step 3: Turunkan kolom dari konstanta**

Di `PelacakanNaskahController`, ganti `'kolom' => [...]` zona Produksi artikel jadi
penanda, lalu urutkan lewat helper. Ganti konstanta `ZONA` bagian artikel:

```php
        'artikel' => [
            ['label' => 'Antrian',    'catatan' => 'menunggu distribusi', 'warna' => 'z1', 'kolom' => ['menunggu_proses']],
            ['label' => 'Produksi',   'catatan' => 'admin & pelaksana',   'warna' => 'z2', 'kolom' => ['pembuatan', 'editing', 'submit', 'revisi']],
            ['label' => 'Finalisasi', 'catatan' => 'admin & superadmin',  'warna' => 'z3', 'kolom' => ['loa', 'publish']],
        ],
```

lalu tambahkan method yang mengurutkan ulang tiap zona menurut konstanta tahap, dan
panggil di tempat `ZONA` dibaca:

```php
    /**
     * Kolom tiap zona diurutkan ulang menurut ARTICLE_STAGES/BOOK_STAGES, sehingga
     * ZONA cukup menyatakan tahap mana milik zona mana — bukan urutannya. Sebelum ini
     * urutan tahap punya dua salinan, dan yang satu langsung basi saat yang lain diubah.
     */
    private function zona(string $tipe): array
    {
        $urut = $tipe === 'buku'
            ? TitleProgress::BOOK_STAGES
            : TitleProgress::ARTICLE_STAGES;

        return array_map(function (array $z) use ($urut) {
            $z['kolom'] = array_values(array_intersect($urut, $z['kolom']));

            return $z;
        }, self::ZONA[$tipe]);
    }
```

`array_intersect` mempertahankan urutan argumen **pertama**, jadi `$urut` yang menentukan.
Ganti setiap pembacaan `self::ZONA[$tipe]` di dalam controller ini jadi `$this->zona($tipe)`.
Pastikan `use App\Models\TitleProgress;` ada di kepala berkas.

- [ ] **Step 4: Jalankan, pastikan lulus**

```
php artisan test --filter="RevisiSetelahSubmitTest|NaskahPelacakan"
```

Harapan: 0 gagal.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Pages/Naskah/PelacakanNaskahController.php \
        tests/Feature/RevisiSetelahSubmitTest.php
git commit -m "pelacakan: kolom papan mengikuti urutan tahap, bukan salinannya sendiri"
```

---

## Task 3: Backfill baris `revisi` lama

**Files:**
- Create: `database/migrations/2026_08_22_000003_pindahkan_tahap_revisi_lama.php`

- [ ] **Step 1: Periksa keadaan data lebih dulu**

```
php artisan tinker --execute="echo App\Models\TitleProgress::where('status','revisi')->count();"
```

Di DB dev hasilnya **0**. Migrasinya tetap ditulis: produksi belum diperiksa, dan
migrasi yang tak berbuat apa-apa atas data kosong itu aman.

- [ ] **Step 2: Tulis migrasinya**

Buat `database/migrations/2026_08_22_000003_pindahkan_tahap_revisi_lama.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `revisi` pindah ke belakang `submit`, jadi artinya berubah: dulu "belum disubmit",
 * kini "sudah disubmit dan diminta diperbaiki". Baris yang duduk di sana harus dinilai
 * ulang, bukan dibiarkan — membiarkannya memajukan naskah satu tahap secara palsu.
 *
 * Aturannya dibaca dari riwayat: baris yang pernah punya jejak `submit` memang sudah
 * disubmit dan tetap di `revisi`; yang tak punya dikembalikan ke `editing`.
 *
 * Memakai DB::table(), BUKAN model: migrasi yang meng-query model pecah saat
 * migrate:fresh dan gejalanya menyesatkan. Sudah terjadi tiga kali di repo ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        $kandidat = DB::table('tb_title_progress')
            ->where('status', 'revisi')
            ->pluck('id');

        if ($kandidat->isEmpty()) {
            return;
        }

        $pernahSubmit = DB::table('tb_title_progress_logs')
            ->whereIn('title_progress_id', $kandidat)
            ->where('to_value', 'submit')
            ->distinct()
            ->pluck('title_progress_id')
            ->all();

        $dimundurkan = $kandidat->reject(
            fn ($id) => in_array($id, $pernahSubmit, true)
        )->values();

        if ($dimundurkan->isEmpty()) {
            return;
        }

        DB::table('tb_title_progress')
            ->whereIn('id', $dimundurkan)
            ->update(['status' => 'editing', 'assigned_role' => 'admin']);

        // Perpindahan diam-diam tanpa jejak tak bisa ditelusuri enam bulan kemudian.
        DB::table('tb_title_progress_logs')->insert(
            $dimundurkan->map(fn ($id) => [
                'title_progress_id' => $id,
                'event'             => 'status_corrected',
                'from_value'        => 'revisi',
                'to_value'          => 'editing',
                'changed_by'        => null,
                'note'              => 'Migrasi 2026-08-22: revisi pindah ke belakang submit. '
                                       . 'Baris ini belum pernah disubmit, jadi dikembalikan ke Editing.',
                'is_correction'     => 1,
                'created_at'        => now(),
            ])->all()
        );
    }

    /**
     * Sengaja tanpa down(): baris yang dikembalikan ke `editing` tak bisa dibedakan
     * lagi dari baris yang memang selalu di `editing`, jadi memutar balik akan menebak.
     * Jejaknya ada di tb_title_progress_logs bila benar-benar perlu dipulihkan tangan.
     */
    public function down(): void
    {
        //
    }
};
```

- [ ] **Step 3: Tulis tes untuk aturannya**

Tambahkan ke `tests/Feature/RevisiSetelahSubmitTest.php`:

```php
    /**
     * Migrasi backfill diuji lewat logikanya, bukan dengan menjalankan ulang migrasi:
     * RefreshDatabase sudah menjalankan semuanya sebelum tes dimulai, jadi datanya
     * belum ada saat migrasi lewat. Yang dikunci di sini adalah aturannya.
     *
     * @test
     */
    public function aturan_backfill_membedakan_yang_pernah_submit(): void
    {
        $sudah = $this->naskah('revisi');
        $belum = $this->naskah('revisi');

        \DB::table('tb_title_progress_logs')->insert([
            'title_progress_id' => $sudah->id,
            'event'             => 'status_advanced',
            'from_value'        => 'editing',
            'to_value'          => 'submit',
            'is_correction'     => 0,
            'created_at'        => now(),
        ]);

        require_once database_path('migrations/2026_08_22_000003_pindahkan_tahap_revisi_lama.php');
        (include database_path('migrations/2026_08_22_000003_pindahkan_tahap_revisi_lama.php'))->up();

        $this->assertSame('revisi', $sudah->fresh()->status,
            'Sudah pernah submit — posisinya di urutan baru sudah benar.');
        $this->assertSame('editing', $belum->fresh()->status,
            'Belum pernah submit — di urutan baru `revisi` berarti sudah submit, jadi harus mundur.');
    }
```

- [ ] **Step 4: Jalankan**

```
php artisan test --filter=aturan_backfill_membedakan_yang_pernah_submit
```

Harapan: LULUS. Bila `require_once` bentrok dengan `include`, buang barisnya dan sisakan
`include` saja — migrasi anonim mengembalikan objeknya.

- [ ] **Step 5: Jalankan migrasi pada DB dev**

```
php artisan migrate
```

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_08_22_000003_pindahkan_tahap_revisi_lama.php \
        tests/Feature/RevisiSetelahSubmitTest.php
git commit -m "naskah: baris revisi lama dinilai ulang dari riwayat submitnya"
```

---

## Task 4: Tabel dan model `ManuscriptRevision`

**Files:**
- Create: `database/migrations/2026_08_22_000001_create_manuscript_revisions_table.php`
- Create: `app/Models/ManuscriptRevision.php`
- Test: `tests/Feature/PutaranRevisiTest.php`

> Catatan urutan nama berkas: migrasi ini bernomor `000001` sehingga jalan **sebelum**
> backfill `000003`. Tugas 3 dikerjakan lebih dulu hanya karena ia berdiri sendiri;
> urutan eksekusi migrasinya tetap ditentukan nama berkas, dan keduanya tak saling
> bergantung.

- [ ] **Step 1: Tulis migrasinya**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satu baris = satu putaran perbaikan.
 *
 * Kenapa tabel sendiri dan bukan kolom di tb_manuscript_files: putaran bisa ada
 * SEBELUM berkasnya ada — PJ menulis permintaan dulu, berkasnya menyusul, atau
 * permintaannya memang berupa catatan saja. Selain itu catatan dan tujuan yang
 * tersalin ke tiap berkas bisa saling bertentangan.
 *
 * Terikat ke JUDUL, bukan order — mengikuti pola tb_manuscript_files. Untuk artikel
 * kolaborasi satu putaran berlaku sejudul, sama seperti berkasnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_manuscript_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('title_id')->constrained('tb_titles')->cascadeOnDelete();
            $t->foreignId('title_chapter_id')->nullable()
              ->constrained('tb_title_chapters')->nullOnDelete();

            $t->unsignedInteger('round');            // urut per judul: 1, 2, 3
            $t->string('stage', 20);                 // 'revisi' | 'pembuatan'
            $t->string('from_stage', 20);            // 'submit' | 'loa' | 'editing'

            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->text('request_note');

            $t->timestamp('closed_at')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            // close_note terisi HANYA saat ditutup paksa lewat pintu darurat. Kosong =
            // ditutup wajar karena naskahnya maju. Bedanya perlu terbaca di riwayat.
            $t->text('close_note')->nullable();

            $t->timestamps();

            $t->index(['title_id', 'stage', 'closed_at'], 'mr_title_stage_open_idx');
            $t->index(['title_id', 'round'], 'mr_title_round_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_manuscript_revisions');
    }
};
```

- [ ] **Step 2: Tulis tes yang gagal**

Buat `tests/Feature/PutaranRevisiTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ManuscriptFile;
use App\Models\ManuscriptRevision;
use App\Models\Title;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PutaranRevisiTest extends TestCase
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

    private function judul(): Title
    {
        return Title::create(['title' => 'Artikel Putaran', 'jenis' => 'artikel',
                              'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
    }

    private function putaran(Title $t, array $atribut = []): ManuscriptRevision
    {
        return ManuscriptRevision::create(array_merge([
            'title_id'     => $t->id,
            'round'        => 1,
            'stage'        => 'revisi',
            'from_stage'   => 'submit',
            'requested_by' => User::factory()->create()->id,
            'assigned_to'  => User::factory()->create()->id,
            'request_note' => 'Metodologi bab 3 diminta diperjelas',
        ], $atribut));
    }

    /** @test */
    public function putaran_terbuka_sampai_ditutup(): void
    {
        $p = $this->putaran($this->judul());

        $this->assertTrue($p->terbuka());
        $this->assertFalse($p->terjawab(), 'Belum ada berkas hasil.');

        $p->update(['closed_at' => now()]);

        $this->assertFalse($p->fresh()->terbuka());
    }

    /** @test */
    public function putaran_terjawab_oleh_berkas_selesai_maupun_antre(): void
    {
        foreach (['selesai', 'antre'] as $status) {
            $judul = $this->judul();
            $p     = $this->putaran($judul);

            ManuscriptFile::create([
                'title_id'               => $judul->id,
                'manuscript_revision_id' => $p->id,
                'slot'                   => 'revisi_hasil',
                'status'                 => $status,
                'version'                => 1,
                'original_name'          => 'hasil.docx',
            ]);

            $this->assertTrue($p->fresh()->terjawab(),
                "Berkas berstatus {$status} harus dihitung sebagai jawaban.");
        }
    }

    /** @test */
    public function berkas_gagal_tidak_dihitung_sebagai_jawaban(): void
    {
        $judul = $this->judul();
        $p     = $this->putaran($judul);

        ManuscriptFile::create([
            'title_id'               => $judul->id,
            'manuscript_revision_id' => $p->id,
            'slot'                   => 'revisi_hasil',
            'status'                 => 'gagal',
            'version'                => 1,
            'original_name'          => 'hasil.docx',
        ]);

        $this->assertFalse($p->fresh()->terjawab());
    }

    /** @test */
    public function berkas_permintaan_bukan_jawaban(): void
    {
        $judul = $this->judul();
        $p     = $this->putaran($judul);

        ManuscriptFile::create([
            'title_id'               => $judul->id,
            'manuscript_revision_id' => $p->id,
            'slot'                   => 'revisi_minta',
            'status'                 => 'selesai',
            'version'                => 1,
            'original_name'          => 'reviewer.pdf',
        ]);

        $this->assertFalse($p->fresh()->terjawab(),
            'Permintaan bukan jawaban atas dirinya sendiri.');
    }
}
```

- [ ] **Step 3: Jalankan, pastikan gagal**

```
php artisan test --filter=PutaranRevisiTest
```

Harapan: GAGAL — kelas `ManuscriptRevision` belum ada.

- [ ] **Step 4: Tulis modelnya**

Buat `app/Models/ManuscriptRevision.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu putaran perbaikan naskah: permintaan dari PJ, jawaban dari Pelaksana.
 *
 * Dua bentuk yang dipakai:
 * - stage 'revisi'    — reviewer jurnal meminta perbaikan (artikel)
 * - stage 'pembuatan' — PJ mengembalikan naskah ke pelaksana dari Editing (buku & artikel)
 *
 * Bedanya bukan kosmetik: hanya putaran 'revisi' yang menggerbangi laju naskah.
 * Lihat spec §5.2.
 */
class ManuscriptRevision extends Model
{
    protected $table = 'tb_manuscript_revisions';

    protected $fillable = [
        'title_id', 'title_chapter_id', 'round', 'stage', 'from_stage',
        'requested_by', 'assigned_to', 'request_note',
        'closed_at', 'closed_by', 'close_note',
    ];

    // JANGAN pakai `protected $dates` — mati sejak Laravel 10, dan kolomnya akan
    // diam-diam kembali sebagai string sehingga ->format() menghasilkan null.
    protected $casts = [
        'closed_at' => 'datetime',
        'round'     => 'integer',
    ];

    public const STAGES = ['revisi', 'pembuatan'];

    public function title(): BelongsTo
    {
        return $this->belongsTo(Title::class, 'title_id');
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(TitleChapter::class, 'title_chapter_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function files(): HasMany
    {
        return $this->hasMany(ManuscriptFile::class, 'manuscript_revision_id');
    }

    public function berkasMinta(): HasMany
    {
        return $this->files()->where('slot', 'revisi_minta');
    }

    public function berkasHasil(): HasMany
    {
        return $this->files()->where('slot', 'revisi_hasil');
    }

    public function terbuka(): bool
    {
        return $this->closed_at === null;
    }

    /**
     * Berkas yang masih `antre` DIHITUNG terjawab: berkasnya sudah dikirim orangnya,
     * dan menahan naskah karena Google Drive sedang lambat berarti menghukum orang
     * atas hal yang bukan urusannya. `gagal` tidak dihitung — berkasnya memang tak sampai.
     */
    public function terjawab(): bool
    {
        return $this->berkasHasil()
            ->whereIn('status', ['selesai', 'antre'])
            ->exists();
    }

    /** Putaran yang menahan laju naskah: terbuka, ada permintaan, belum dijawab. */
    public function menahan(): bool
    {
        return $this->stage === 'revisi'
            && $this->terbuka()
            && $this->berkasMinta()->exists()
            && ! $this->terjawab();
    }

    /** Nomor putaran berikutnya untuk sebuah judul. */
    public static function nomorBerikutnya(int $titleId): int
    {
        return (int) self::where('title_id', $titleId)->max('round') + 1;
    }
}
```

- [ ] **Step 5: Jalankan**

```
php artisan test --filter=PutaranRevisiTest
```

Harapan: masih GAGAL — kolom `manuscript_revision_id` di `tb_manuscript_files` belum ada.
Itu Task 5. Selesaikan Task 5 lebih dulu, lalu kembali dan jalankan ulang.

- [ ] **Step 6: Commit (setelah Task 5 hijau)**

```bash
git add database/migrations/2026_08_22_000001_create_manuscript_revisions_table.php \
        app/Models/ManuscriptRevision.php tests/Feature/PutaranRevisiTest.php
git commit -m "naskah: putaran perbaikan jadi baris sendiri, bukan kolom di berkas"
```

---

## Task 5: Slot berkas baru dan FK ke putaran

**Files:**
- Create: `database/migrations/2026_08_22_000002_add_revision_id_to_manuscript_files.php`
- Modify: `app/Models/ManuscriptFile.php`

- [ ] **Step 1: Tulis migrasinya**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menyambungkan berkas ke putaran perbaikannya.
 *
 * Nullable karena sebagian besar berkas (naskah masuk, hasil layout, cover, berkas
 * ISBN) tak pernah milik putaran mana pun. Hanya slot revisi_minta/revisi_hasil yang
 * mengisinya.
 *
 * `version` yang sudah ada tetap mengurus berkas berulang di slot yang sama; putaran
 * diurus kolom ini. Tanpa pemisahan itu, "tiga berkas di putaran 1" tak bisa dibedakan
 * dari "tiga putaran berisi satu berkas".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_manuscript_files', function (Blueprint $t) {
            $t->foreignId('manuscript_revision_id')->nullable()->after('title_chapter_id')
              ->constrained('tb_manuscript_revisions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tb_manuscript_files', function (Blueprint $t) {
            $t->dropConstrainedForeignId('manuscript_revision_id');
        });
    }
};
```

- [ ] **Step 2: Tambahkan slot dan relasi ke model**

Di `app/Models/ManuscriptFile.php`, tambahkan dua baris ke `const SLOTS` (setelah `'loa'`):

```php
        'revisi_minta'    => 'Permintaan Revisi',
        'revisi_hasil'    => 'Hasil Revisi',
```

Ganti kedua konstanta daftar-per-jenis:

```php
    /**
     * revisi_minta/revisi_hasil ada di KEDUANYA meski buku tak punya tahap `revisi`:
     * pengembalian Editing→Pembuatan memakai slot yang sama, dan itu berlaku untuk buku.
     */
    public const SLOTS_ARTIKEL = ['masuk', 'hasil_editing', 'revisi_minta', 'revisi_hasil', 'loa', 'final'];

    public const SLOTS_BUKU = ['masuk', 'hasil_editing', 'revisi_minta', 'revisi_hasil',
                               'hasil_layout', 'hasil_proofread', 'cover', 'final'];
```

Pastikan `manuscript_revision_id` bisa diisi. Bila model itu punya `$fillable`,
tambahkan; bila memakai `$guarded = []`, tak perlu apa-apa. Tambahkan relasinya:

```php
    public function revision(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ManuscriptRevision::class, 'manuscript_revision_id');
    }
```

- [ ] **Step 3: Jalankan migrasi dan tes**

```
php artisan migrate
php artisan test --filter=PutaranRevisiTest
```

Harapan: 4 lulus, 0 gagal.

- [ ] **Step 4: Pastikan kartu berkas lama tak berubah rupa**

```
php artisan test --filter="NaskahDetail|BerkasIsbn|Unggahan"
```

Harapan: 0 gagal. Bila ada tes yang menghitung jumlah slot yang tampil, ia akan merah —
itu benar, dan angkanya diperbarui, bukan slotnya disembunyikan.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_08_22_000002_add_revision_id_to_manuscript_files.php \
        database/migrations/2026_08_22_000001_create_manuscript_revisions_table.php \
        app/Models/ManuscriptFile.php app/Models/ManuscriptRevision.php \
        tests/Feature/PutaranRevisiTest.php
git commit -m "naskah: berkas revisi punya slotnya sendiri dan tahu putarannya"
```

---

## Task 6: `applyGroup()` boleh mundur, dan `kembalikan()` (T3)

Jebakan paling mahal di seluruh rencana ini. Tanpa langkah ini, tombol mundur akan
melapor berhasil sambil tak menggerakkan apa pun.

**Files:**
- Modify: `app/Services/TitleProgressService.php`
- Test: `tests/Feature/RevisiSetelahSubmitTest.php`

- [ ] **Step 1: Tulis tes yang gagal**

Tambahkan ke `tests/Feature/RevisiSetelahSubmitTest.php`:

```php
    /**
     * applyGroup() punya penjaga `$idx > $targetIdx → continue` yang dimaksudkan untuk
     * "jangan tarik mundur anggota grup yang sudah lebih maju". Untuk perpindahan mundur
     * SETIAP anggota memenuhi syarat itu, jadi tanpa penanganan khusus fungsinya
     * mengembalikan 0 dan tak menggerakkan apa pun — sambil terlihat berhasil.
     *
     * Assertion-nya sengaja pada status tiap baris, bukan pada nilai kembalian.
     *
     * @test
     */
    public function mundur_dari_loa_benar_benar_memindahkan_seluruh_grup(): void
    {
        $anggota = $this->grupArtikel('loa', 3);

        app(TitleProgressService::class)
            ->kembalikan($anggota->first(), 'revisi', $this->admin(), 'Reviewer minta revisi minor');

        foreach ($anggota as $p) {
            $this->assertSame('revisi', $p->fresh()->status,
                'Seluruh order sejudul harus ikut mundur, bukan hanya yang ditekan.');
        }
    }

    /** @test */
    public function mundur_tercatat_sebagai_alur_normal_bukan_koreksi(): void
    {
        $p = $this->naskah('loa');

        app(TitleProgressService::class)
            ->kembalikan($p, 'revisi', $this->admin(), 'Reviewer minta revisi');

        $this->assertDatabaseHas('tb_title_progress_logs', [
            'title_progress_id' => $p->id,
            'from_value'        => 'loa',
            'to_value'          => 'revisi',
            'is_correction'     => 0,
        ]);
    }

    /** @test */
    public function maju_tetap_menolak_menarik_mundur_anggota_yang_lebih_depan(): void
    {
        $anggota = $this->grupArtikel('editing', 2);
        $depan   = $anggota->last();
        $depan->update(['status' => 'loa']);

        app(TitleProgressService::class)->advance($anggota->first(), $this->admin());

        $this->assertSame('loa', $depan->fresh()->status,
            'Penjaga lama harus tetap berlaku untuk perpindahan maju.');
    }

    /** @test */
    public function mundur_ditolak_bila_naskah_sudah_diarsipkan(): void
    {
        $p = $this->naskah('publish');
        $p->update(['archived_at' => now()]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(TitleProgressService::class)
            ->kembalikan($p, 'revisi', $this->admin(), 'coba tarik mundur');
    }

    /** @test */
    public function mundur_menolak_pasangan_tahap_yang_tidak_dibolehkan(): void
    {
        $p = $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(TitleProgressService::class)
            ->kembalikan($this->naskah('loa'), 'editing', $this->admin(), 'lompat jauh');
    }
```

Tambahkan helper grup ke berkas tes yang sama:

```php
    /** @return \Illuminate\Support\Collection<int,TitleProgress> */
    private function grupArtikel(string $status, int $jumlah): \Illuminate\Support\Collection
    {
        $ids = [];
        for ($i = 0; $i < $jumlah; $i++) {
            $detail = OrderDetail::factory()->create([
                'type' => 'at_mandiri', 'title' => 'Judul Grup Revisi',
            ]);
            $ids[] = TitleProgress::create([
                'order_detail_id' => $detail->id,
                'status'          => $status,
                'assigned_role'   => TitleProgress::getHandlerForStatus($status),
                'started_at'      => now(),
            ])->id;
        }

        return TitleProgress::with('orderDetail')->whereIn('id', $ids)->get();
    }
```

- [ ] **Step 2: Jalankan, pastikan gagal**

```
php artisan test --filter=RevisiSetelahSubmitTest
```

Harapan: lima tes baru GAGAL — `kembalikan()` belum ada.

- [ ] **Step 3: Pecah penjaganya**

Di `app/Services/TitleProgressService.php`, ubah tanda tangan dan penjaga `applyGroup()`:

```php
    private function applyGroup(
        TitleProgress $progress,
        string $target,
        User $actor,
        ?string $note,
        bool $isCorrection,
        string $event,
        bool $bolehMundur = false
    ): int {
```

dan di dalam perulangan:

```php
                // Penjaga ini dulu mencampur dua pertanyaan: "apakah ini koreksi" dan
                // "bolehkah bergerak mundur". Untuk perpindahan mundur SETIAP anggota
                // punya $idx > $targetIdx, jadi tanpa $bolehMundur seluruh grup dilewati
                // dan fungsinya mengembalikan 0 tanpa suara.
                if (! $isCorrection && ! $bolehMundur && $idx !== false && $idx > $targetIdx) {
                    continue; // sudah lebih maju dari target
                }
```

`advance()` dan `autoAdvanceOnUpload()` memanggil tanpa argumen baru, jadi perilakunya
tak berubah sedikit pun.

- [ ] **Step 4: Tambahkan `kembalikan()`**

Di kelas yang sama:

```php
    /**
     * Pasangan tahap yang boleh dimundurkan lewat alur normal (bukan Koreksi).
     * Sengaja daftar tertutup: "mundur satu tahap dari mana saja" butuh lantai pengaman
     * di banyak tempat dan tak ada yang memintanya.
     */
    public const MUNDUR_SAH = [
        'loa'     => 'revisi',
        'editing' => 'pembuatan',
    ];

    /**
     * Mengembalikan naskah satu tahap ke belakang sebagai ALUR NORMAL — dicatat dengan
     * is_correction = false supaya "Koreksi" tetap berarti "ada yang salah" dan tak
     * tercampur kerja harian PJ.
     *
     * @return int jumlah order sejudul yang ikut mundur
     */
    public function kembalikan(TitleProgress $progress, string $target, User $actor, string $note): int
    {
        $this->requirePermission($actor, 'naskah.advance');
        $this->requireBidang($actor, $progress->bidang);

        if (trim($note) === '') {
            throw ValidationException::withMessages([
                'note' => 'Alasan wajib diisi saat mengembalikan naskah.',
            ]);
        }

        if ((self::MUNDUR_SAH[$progress->status] ?? null) !== $target) {
            throw ValidationException::withMessages([
                'status' => 'Naskah hanya bisa dikembalikan dari LoA ke Revisi, atau dari Editing ke Pembuatan.',
            ]);
        }

        if ($progress->archived_at !== null || $progress->cancelled_at !== null
            || TitleProgress::isFinal($progress->status)) {
            throw ValidationException::withMessages([
                'status' => 'Naskah yang sudah final, diarsipkan, atau dibatalkan hanya bisa dibuka lewat Koreksi superadmin.',
            ]);
        }

        return $this->applyGroup($progress, $target, $actor, $note, false, 'status_returned', true);
    }
```

Argumen ketujuh `true` itulah `$bolehMundur`. Tanpa itu fungsinya mengembalikan 0 dan
tak menggerakkan apa pun — lihat Step 3.

- [ ] **Step 5: Jalankan**

```
php artisan test --filter="RevisiSetelahSubmitTest|TitleProgressServiceTest"
```

Harapan: 0 gagal. Bila `mundur_dari_loa_benar_benar_memindahkan_seluruh_grup` masih
merah, `$bolehMundur` belum sampai ke penjaganya — periksa bahwa `kembalikan()` benar
mengoper `true` sebagai argumen ketujuh.

- [ ] **Step 6: Commit**

```bash
git add app/Services/TitleProgressService.php tests/Feature/RevisiSetelahSubmitTest.php
git commit -m "naskah: perpindahan mundur berhenti ditolak diam-diam oleh penjaga grup"
```

---

## Task 7: Layanan putaran dan gerbang maju

**Files:**
- Create: `app/Services/ManuscriptRevisionService.php`
- Modify: `app/Services/TitleProgressService.php` (`advance()`)
- Test: `tests/Feature/PutaranRevisiTest.php`

- [ ] **Step 1: Tulis tes yang gagal**

Tambahkan ke `tests/Feature/PutaranRevisiTest.php`:

```php
    /** @test */
    public function putaran_dengan_permintaan_belum_dijawab_menahan_laju(): void
    {
        [$judul, $progress] = $this->naskahBerjudul('revisi');
        $p = $this->putaran($judul);

        ManuscriptFile::create([
            'title_id' => $judul->id, 'manuscript_revision_id' => $p->id,
            'slot' => 'revisi_minta', 'status' => 'selesai',
            'version' => 1, 'original_name' => 'reviewer.pdf',
        ]);

        try {
            app(\App\Services\TitleProgressService::class)->advance($progress, $this->superadmin());
            $this->fail('Naskah dengan putaran belum terjawab mestinya tertahan.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('ke-1', $e->getMessage(),
                'Pesannya harus menyebut nomor putarannya, bukan sekadar "tidak bisa maju".');
        }

        $this->assertSame('revisi', $progress->fresh()->status);
    }

    /** @test */
    public function menutup_putaran_membebaskan_naskah_untuk_maju(): void
    {
        [$judul, $progress] = $this->naskahBerjudul('revisi');
        $p = $this->putaran($judul);

        ManuscriptFile::create([
            'title_id' => $judul->id, 'manuscript_revision_id' => $p->id,
            'slot' => 'revisi_minta', 'status' => 'selesai',
            'version' => 1, 'original_name' => 'reviewer.pdf',
        ]);

        app(\App\Services\ManuscriptRevisionService::class)
            ->tutup($p, $this->superadmin(), 'Reviewer menarik permintaannya');

        app(\App\Services\TitleProgressService::class)->advance($progress, $this->superadmin());

        $this->assertSame('loa', $progress->fresh()->status);
        $this->assertNotNull($p->fresh()->closed_at);
        $this->assertSame('Reviewer menarik permintaannya', $p->fresh()->close_note);
    }

    /** @test */
    public function putaran_pembuatan_tidak_menahan_laju(): void
    {
        [$judul, $progress] = $this->naskahBerjudul('pembuatan');
        $p = $this->putaran($judul, ['stage' => 'pembuatan', 'from_stage' => 'editing']);

        ManuscriptFile::create([
            'title_id' => $judul->id, 'manuscript_revision_id' => $p->id,
            'slot' => 'revisi_minta', 'status' => 'selesai',
            'version' => 1, 'original_name' => 'catatan-editor.pdf',
        ]);

        app(\App\Services\TitleProgressService::class)->advance($progress, $this->superadmin());

        $this->assertSame('editing', $progress->fresh()->status,
            'Pengembalian ke Pembuatan dijawab dengan naskahnya, bukan berkas balasan.');
    }

    /** @test */
    public function maju_wajar_menutup_putaran_tanpa_catatan(): void
    {
        [$judul, $progress] = $this->naskahBerjudul('revisi');
        $p = $this->putaran($judul);

        ManuscriptFile::create([
            'title_id' => $judul->id, 'manuscript_revision_id' => $p->id,
            'slot' => 'revisi_hasil', 'status' => 'selesai',
            'version' => 1, 'original_name' => 'hasil.docx',
        ]);

        app(\App\Services\TitleProgressService::class)->advance($progress, $this->superadmin());

        $segar = $p->fresh();
        $this->assertNotNull($segar->closed_at);
        $this->assertNull($segar->close_note,
            'Catatan kosong itulah yang membedakan penutupan wajar dari pintu darurat.');
    }

    /** @test */
    public function nomor_putaran_naik_per_judul(): void
    {
        $judul = $this->judul();
        $this->putaran($judul, ['round' => 1]);
        $this->putaran($judul, ['round' => 2]);

        $this->assertSame(3, ManuscriptRevision::nomorBerikutnya($judul->id));
    }
```

Tambahkan dua helper ke berkas tes yang sama:

```php
    private function superadmin(): User
    {
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    /** @return array{0: Title, 1: \App\Models\TitleProgress} */
    private function naskahBerjudul(string $status): array
    {
        $judul  = $this->judul();
        $detail = \App\Models\OrderDetail::factory()->create([
            'type' => 'at_mandiri', 'title' => $judul->title, 'title_id' => $judul->id,
        ]);
        $progress = \App\Models\TitleProgress::create([
            'order_detail_id' => $detail->id,
            'status'          => $status,
            'assigned_role'   => \App\Models\TitleProgress::getHandlerForStatus($status),
            'started_at'      => now(),
        ]);

        return [$judul, $progress];
    }
```

- [ ] **Step 2: Jalankan, pastikan gagal**

```
php artisan test --filter=PutaranRevisiTest
```

Harapan: lima tes baru GAGAL.

- [ ] **Step 3: Tulis layanannya**

Buat `app/Services/ManuscriptRevisionService.php`:

```php
<?php

namespace App\Services;

use App\Models\ManuscriptFile;
use App\Models\ManuscriptRevision;
use App\Models\Title;
use App\Models\TitleProgress;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Membuka, menjawab, dan menutup putaran perbaikan.
 *
 * Putaran dibuat MALAS — hanya saat permintaan benar-benar dikirim atau tombol mundur
 * ditekan. Naskah yang lewat tahap Revisi tanpa ada revisian tak meninggalkan putaran
 * kosong yang harus dibaca orang lain nanti.
 */
class ManuscriptRevisionService
{
    public function __construct(private ManuscriptFileService $berkas) {}

    /**
     * @param  UploadedFile[]  $lampiran
     */
    public function buka(
        Title $title,
        string $stage,
        string $fromStage,
        User $actor,
        string $catatan,
        ?User $untuk = null,
        array $lampiran = []
    ): ManuscriptRevision {
        if (trim($catatan) === '') {
            throw ValidationException::withMessages([
                'request_note' => 'Catatan permintaan wajib diisi.',
            ]);
        }

        if (! in_array($stage, ManuscriptRevision::STAGES, true)) {
            throw ValidationException::withMessages(['stage' => 'Tahap putaran tidak sah.']);
        }

        return DB::transaction(function () use ($title, $stage, $fromStage, $actor, $catatan, $untuk, $lampiran) {
            $putaran = ManuscriptRevision::create([
                'title_id'     => $title->id,
                'round'        => ManuscriptRevision::nomorBerikutnya($title->id),
                'stage'        => $stage,
                'from_stage'   => $fromStage,
                'requested_by' => $actor->id,
                'assigned_to'  => $untuk?->id,
                'request_note' => $catatan,
            ]);

            foreach ($lampiran as $file) {
                $this->berkas->upload($title, null, 'revisi_minta', $file, $actor)
                    ->update(['manuscript_revision_id' => $putaran->id]);
            }

            return $putaran;
        });
    }

    /**
     * @param  UploadedFile[]  $lampiran
     */
    public function jawab(ManuscriptRevision $putaran, User $actor, array $lampiran): ManuscriptRevision
    {
        if (! $putaran->terbuka()) {
            throw ValidationException::withMessages([
                'putaran' => 'Putaran ini sudah ditutup.',
            ]);
        }

        if ($lampiran === []) {
            throw ValidationException::withMessages([
                'berkas' => 'Pilih minimal satu berkas hasil revisi.',
            ]);
        }

        DB::transaction(function () use ($putaran, $actor, $lampiran) {
            foreach ($lampiran as $file) {
                $this->berkas->upload($putaran->title, null, 'revisi_hasil', $file, $actor)
                    ->update(['manuscript_revision_id' => $putaran->id]);
            }
        });

        return $putaran->fresh();
    }

    /**
     * Pintu darurat. Tanpa ini, satu putaran yang salah buka mengunci naskah selamanya
     * dan hanya superadmin yang bisa membebaskannya — gerbang tanpa pintu darurat
     * membuat orang berhenti memakai sistemnya.
     */
    public function tutup(ManuscriptRevision $putaran, User $actor, string $catatan): ManuscriptRevision
    {
        if (trim($catatan) === '') {
            throw ValidationException::withMessages([
                'close_note' => 'Catatan wajib diisi saat menutup putaran tanpa berkas.',
            ]);
        }

        $putaran->update([
            'closed_at'  => now(),
            'closed_by'  => $actor->id,
            'close_note' => $catatan,
        ]);

        return $putaran->fresh();
    }

    /**
     * Penutupan WAJAR: naskah maju melewati tahapnya. `close_note` sengaja dibiarkan
     * null — kosong-atau-tidak itulah yang membedakannya dari penutupan paksa.
     */
    public function tutupOtomatis(Title $title, string $stage, User $actor): int
    {
        return ManuscriptRevision::where('title_id', $title->id)
            ->where('stage', $stage)
            ->whereNull('closed_at')
            ->update(['closed_at' => now(), 'closed_by' => $actor->id]);
    }

    /** Putaran yang menahan laju naskah di tahap ini, bila ada. */
    public function penahan(Title $title, string $stage): ?ManuscriptRevision
    {
        if ($stage !== 'revisi') {
            return null;   // hanya putaran revisi yang menggerbangi laju (spec §5.2)
        }

        return ManuscriptRevision::where('title_id', $title->id)
            ->where('stage', 'revisi')
            ->whereNull('closed_at')
            ->get()
            ->first(fn (ManuscriptRevision $r) => $r->menahan());
    }
}
```

- [ ] **Step 4: Pasang gerbangnya di `advance()`**

Di `app/Services/TitleProgressService.php`, di dalam `advance()`, tepat sesudah
`$this->assertLinkTerbit($progress, $next);`:

```php
        $this->assertPutaranTerjawab($progress);
```

dan tambahkan methodnya:

```php
    /**
     * Naskah tak boleh meninggalkan tahap Revisi selama masih ada putaran terbuka yang
     * sudah punya permintaan tapi belum ada jawabannya. Pesannya menyebut nomor putaran
     * supaya orang tahu yang mana — "tidak bisa maju" saja membuat orang menebak.
     */
    private function assertPutaranTerjawab(TitleProgress $progress): void
    {
        $title = $progress->orderDetail?->titleRef;
        if ($title === null) {
            return;
        }

        $penahan = app(ManuscriptRevisionService::class)->penahan($title, $progress->status);
        if ($penahan === null) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => "Putaran revisi ke-{$penahan->round} belum dijawab. "
                        . 'Unggah hasil revisi, atau tutup putarannya dengan catatan.',
        ]);
    }
```

Dan di `applyGroup()`, sesudah transaksi berhasil, tutup putaran tahap yang ditinggalkan.
Sisipkan tepat sebelum `return count($changed);`:

```php
        // Putaran tahap yang baru saja ditinggalkan ditutup wajar (close_note null).
        if ($changed !== []) {
            [$pertama, $dari] = $changed[0];
            $title = $pertama->orderDetail?->titleRef;
            if ($title && in_array($dari, ManuscriptRevision::STAGES, true)) {
                app(ManuscriptRevisionService::class)->tutupOtomatis($title, $dari, $actor);
            }
        }
```

Tambahkan `use App\Models\ManuscriptRevision;` dan `use App\Services\ManuscriptRevisionService;`
di kepala berkas bila belum ada.

- [ ] **Step 5: Jalankan**

```
php artisan test --filter="PutaranRevisiTest|RevisiSetelahSubmitTest"
```

Harapan: 0 gagal.

- [ ] **Step 6: Jalankan tes naskah lengkap**

```
php artisan test --filter="Naskah|TitleProgress|LinkTerbit|Putaran|Revisi"
```

Harapan: 0 gagal.

- [ ] **Step 7: Commit**

```bash
git add app/Services/ManuscriptRevisionService.php app/Services/TitleProgressService.php \
        tests/Feature/PutaranRevisiTest.php
git commit -m "naskah: putaran revisi menahan laju sampai dijawab, dengan pintu darurat"
```

---

## Task 8: Route, peta izin, controller

**Files:**
- Modify: `routes/web.php:127`
- Modify: `config/permissions.php:182,189`
- Modify: `app/Http/Controllers/Pages/Naskah/DetailNaskahController.php:102-112`
- Test: `tests/Feature/PutaranRevisiTest.php`

- [ ] **Step 1: Tulis tes yang gagal**

```php
    /** @test */
    public function pelaksana_boleh_mengunggah_hasil_revisi(): void
    {
        [$judul, $progress] = $this->naskahBerjudul('revisi');
        $p = $this->putaran($judul);

        $pelaksana = User::factory()->create();
        $pelaksana->assignRole('production');
        $progress->update(['pelaksana_user_id' => $pelaksana->id, 'bidang' => 'artikel']);

        $this->actingAs($pelaksana)
            ->post(route('naskah.revisi.hasil', $progress->order_detail_id), [
                'revision_id' => $p->id,
                'berkas'      => [\Illuminate\Http\UploadedFile::fake()->create('hasil.docx', 20)],
            ])->assertRedirect();

        $this->assertTrue($p->fresh()->terjawab());
    }

    /** @test */
    public function marketing_tidak_boleh_membuka_putaran(): void
    {
        [$judul, $progress] = $this->naskahBerjudul('revisi');

        $mkt = User::factory()->create();
        $mkt->assignRole('marketing');

        $this->actingAs($mkt)
            ->post(route('naskah.revisi.minta', $progress->order_detail_id), [
                'request_note' => 'coba buka',
            ])->assertForbidden();

        $this->assertSame(0, ManuscriptRevision::count());
    }
```

- [ ] **Step 2: Jalankan, pastikan gagal**

```
php artisan test --filter="pelaksana_boleh_mengunggah_hasil_revisi|marketing_tidak_boleh_membuka_putaran"
```

Harapan: GAGAL — route belum ada.

- [ ] **Step 3: Tambahkan route**

Di `routes/web.php`, **ganti** baris `naskah.revisi` yang lama:

```php
        Route::post('{id}/kembalikan',   [\App\Http\Controllers\Pages\Naskah\DetailNaskahController::class, 'kembalikan'])->name('kembalikan')->whereNumber('id');
        Route::post('{id}/revisi/minta', [\App\Http\Controllers\Pages\Naskah\DetailNaskahController::class, 'revisiMinta'])->name('revisi.minta')->whereNumber('id');
        Route::post('{id}/revisi/hasil', [\App\Http\Controllers\Pages\Naskah\DetailNaskahController::class, 'revisiHasil'])->name('revisi.hasil')->whereNumber('id');
        Route::post('{id}/revisi/tutup', [\App\Http\Controllers\Pages\Naskah\DetailNaskahController::class, 'revisiTutup'])->name('revisi.tutup')->whereNumber('id');
```

- [ ] **Step 4: Perbarui peta izin**

Di `config/permissions.php`, ganti kedua baris:

```php
                // Upload file naskah: semua role (marketing = naskah masuk dari klien;
                // production = hasil kerjanya; admin/superadmin = apa pun). Hasil revisi
                // ikut di sini justru supaya Pelaksana bisa menjawab — kelompok `advance`
                // tertutup untuknya.
                'upload'   => ['naskah.file', 'naskah.bab.file', 'naskah.revisi.hasil'],
```

```php
                'advance'  => ['naskah.selesaikan', 'naskah.kembalikan', 'naskah.bab.selesaikan',
                               'naskah.revisi.minta', 'naskah.revisi.tutup'],
```

`naskah.revisi` yang lama **dihapus** dari daftar — route-nya sudah tak ada, dan
meninggalkannya membuat peta berbohong.

- [ ] **Step 5: Ganti aksi controller**

Di `DetailNaskahController`, **ganti** method `revisi()` seluruhnya:

```php
    public function kembalikan(Request $request, int $id, TitleProgressService $stages)
    {
        $request->validate([
            'alasan'   => 'required|string|max:255',
            'berkas.*' => 'nullable|file|max:' . \App\Support\BatasUnggah::kb(20480),
        ]);
        $progress = $this->progress($id);

        return $this->run($request, function () use ($stages, $progress, $request) {
            $target = TitleProgressService::MUNDUR_SAH[$progress->status] ?? null;
            if ($target === null) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'status' => 'Naskah di tahap ini tak bisa dikembalikan lewat alur normal.',
                ]);
            }

            $title = $progress->orderDetail?->titleRef;
            if ($title) {
                app(ManuscriptRevisionService::class)->buka(
                    $title, $target, $progress->status, $request->user(),
                    $request->input('alasan'),
                    $progress->pelaksana,
                    $request->file('berkas', [])
                );
            }

            $stages->kembalikan($progress, $target, $request->user(), $request->input('alasan'));

            return 'Naskah dikembalikan ke ' . \App\Models\TitleProgress::labelFor($target) . '.';
        });
    }

    public function revisiMinta(Request $request, int $id)
    {
        $request->validate([
            'request_note' => 'required|string|max:2000',
            'assigned_to'  => 'nullable|integer|exists:users,id',
            'berkas.*'     => 'nullable|file|max:' . \App\Support\BatasUnggah::kb(20480),
        ]);
        $progress = $this->progress($id);

        return $this->run($request, function () use ($progress, $request) {
            $title = $progress->orderDetail?->titleRef;
            if ($title === null) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'title' => 'Naskah ini belum tersambung ke judul.',
                ]);
            }

            $untuk = $request->filled('assigned_to')
                ? \App\Models\User::find($request->input('assigned_to'))
                : $progress->pelaksana;

            $putaran = app(ManuscriptRevisionService::class)->buka(
                $title, $progress->status, $progress->status, $request->user(),
                $request->input('request_note'), $untuk, $request->file('berkas', [])
            );

            app(Notifier::class)->naskahRevisiDiminta($putaran, $request->user());

            return "Permintaan revisi putaran ke-{$putaran->round} dikirim.";
        });
    }

    public function revisiHasil(Request $request, int $id)
    {
        $request->validate([
            'revision_id' => 'required|integer|exists:tb_manuscript_revisions,id',
            'berkas'      => 'required|array|min:1',
            'berkas.*'    => 'file|max:' . \App\Support\BatasUnggah::kb(20480),
        ]);
        $progress = $this->progress($id);

        return $this->run($request, function () use ($request) {
            $putaran = \App\Models\ManuscriptRevision::findOrFail($request->input('revision_id'));

            app(ManuscriptRevisionService::class)
                ->jawab($putaran, $request->user(), $request->file('berkas'));

            return 'Hasil revisi diunggah.';
        });
    }

    public function revisiTutup(Request $request, int $id)
    {
        $request->validate([
            'revision_id' => 'required|integer|exists:tb_manuscript_revisions,id',
            'close_note'  => 'required|string|max:1000',
        ]);
        $this->progress($id);

        return $this->run($request, function () use ($request) {
            $putaran = \App\Models\ManuscriptRevision::findOrFail($request->input('revision_id'));

            app(ManuscriptRevisionService::class)
                ->tutup($putaran, $request->user(), $request->input('close_note'));

            return "Putaran ke-{$putaran->round} ditutup.";
        });
    }
```

Tambahkan `use App\Services\ManuscriptRevisionService;` di kepala berkas.

- [ ] **Step 6: Jalankan**

```
php artisan test --filter="PutaranRevisiTest|HakAkses|Permission"
```

Harapan: 0 gagal. Bila ada tes peta-izin yang merah, ia menegakkan bahwa **setiap** route
terpetakan — itu memang gunanya, jadi lengkapi petanya, jangan longgarkan tesnya.

- [ ] **Step 7: Commit**

```bash
git add routes/web.php config/permissions.php \
        app/Http/Controllers/Pages/Naskah/DetailNaskahController.php \
        tests/Feature/PutaranRevisiTest.php
git commit -m "naskah: aksi putaran revisi punya route dan izinnya sendiri"
```

---

## Task 9: Notifikasi

**Files:**
- Modify: `app/Services/Notifier.php:294-312`
- Test: `tests/Feature/PutaranRevisiTest.php`

- [ ] **Step 1: Tulis tes yang gagal**

```php
    /** @test */
    public function pelaksana_dikabari_saat_revisi_diminta(): void
    {
        [$judul, $progress] = $this->naskahBerjudul('revisi');

        $pelaksana = User::factory()->create();
        $pelaksana->assignRole('production');
        $progress->update(['pelaksana_user_id' => $pelaksana->id]);

        $putaran = $this->putaran($judul, ['assigned_to' => $pelaksana->id]);

        app(\App\Services\Notifier::class)->naskahRevisiDiminta($putaran, $this->superadmin());

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $pelaksana->id,
        ]);
    }

    /** @test */
    public function notifikasi_mundur_tidak_bilang_naskah_maju(): void
    {
        [$judul, $progress] = $this->naskahBerjudul('loa');
        $pj = User::factory()->create();
        $pj->assignRole('admin');
        $progress->update(['pj_user_id' => $pj->id]);

        app(\App\Services\Notifier::class)
            ->naskahTahapBerubah($progress, $this->superadmin(), 'loa', 'revisi');

        $judulNotif = \DB::table('notifications')
            ->where('notifiable_id', $pj->id)->value('data');

        $this->assertStringNotContainsString('maju', $judulNotif,
            'Perpindahan mundur tak boleh dikabarkan sebagai "maju".');
        $this->assertStringContainsString('dikembalikan', $judulNotif);
    }
```

> Bila tabel notifikasi di repo ini bukan `notifications`, sesuaikan nama tabel dan
> kolomnya dengan yang dipakai `Notifier::send()`. Periksa lebih dulu dengan
> `grep -n "function send" -A 20 app/Services/Notifier.php`.

- [ ] **Step 2: Jalankan, pastikan gagal**

```
php artisan test --filter="pelaksana_dikabari_saat_revisi_diminta|notifikasi_mundur_tidak_bilang_naskah_maju"
```

- [ ] **Step 3: Perbaiki `naskahTahapBerubah()`**

Ganti isi methodnya:

```php
    public function naskahTahapBerubah(TitleProgress $progress, User $actor, string $from, string $to, bool $isCorrection = false): void
    {
        $progress->loadMissing(['orderDetail', 'pj', 'pelaksana']);

        $stages  = $progress->getStages();
        $mundur  = array_search($to, $stages, true) < array_search($from, $stages, true);

        $recipients = $this->roleUsers(['superadmin'], $actor);
        if ($progress->pj && $progress->pj->id !== $actor->id) {
            $recipients = $recipients->push($progress->pj);
        }
        // Perpindahan mundur ditujukan kepada pelaksana — dialah yang harus mengerjakan
        // ulang. Sebelum ini ia tak pernah dikabari sama sekali.
        if ($mundur && $progress->pelaksana && $progress->pelaksana->id !== $actor->id) {
            $recipients = $recipients->push($progress->pelaksana);
        }
        $recipients = $recipients->unique('id')->values();

        $judul = match (true) {
            $isCorrection => 'Koreksi tahap: ',
            $mundur       => 'Naskah dikembalikan ke ',
            default       => 'Naskah maju ke ',
        };

        $this->send($recipients, [
            'category' => 'naskah',
            'title'    => $judul . TitleProgress::labelFor($to),
            'message'  => $this->naskahLabel($progress) . ' • dari '
                          . TitleProgress::labelFor($from) . ' oleh ' . $actor->name,
            'url'      => $this->naskahUrl($progress),
            'icon'     => ($isCorrection || $mundur) ? 'rotate-ccw' : 'arrow-right-circle',
        ]);
    }

    /** Permintaan revisi ditujukan ke satu orang: pelaksana yang harus mengerjakannya. */
    public function naskahRevisiDiminta(\App\Models\ManuscriptRevision $putaran, User $actor): void
    {
        $putaran->loadMissing(['assignedTo', 'title']);

        if (! $putaran->assignedTo) {
            return;
        }

        $this->send(collect([$putaran->assignedTo]), [
            'category' => 'naskah',
            'title'    => "Permintaan revisi putaran ke-{$putaran->round}",
            'message'  => ($putaran->title?->title ?? 'Naskah') . ' • ' . $putaran->request_note
                          . ' • diminta ' . $actor->name,
            'url'      => route('naskah.pelacakan'),
            'icon'     => 'edit-3',
        ]);
    }
```

- [ ] **Step 4: Jalankan**

```
php artisan test --filter="PutaranRevisiTest|Notifier|Notifikasi"
```

Harapan: 0 gagal.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Notifier.php tests/Feature/PutaranRevisiTest.php
git commit -m "naskah: pelaksana dikabari saat naskahnya dikembalikan atau diminta revisi"
```

---

## Task 10: Kartu Revisi di layar naskah

**Files:**
- Create: `resources/views/naskah/partials/revisi.blade.php`
- Modify: `resources/views/naskah/detail.blade.php`
- Modify: `app/Http/Controllers/Pages/Naskah/DetailNaskahController.php` (`show()`)

- [ ] **Step 1: Tulis tes yang gagal**

```php
    /** @test */
    public function kartu_revisi_menampilkan_putaran_lama_saat_mundur_dari_loa(): void
    {
        [$judul, $progress] = $this->naskahBerjudul('revisi');
        $lama = $this->putaran($judul, ['round' => 1, 'closed_at' => now()]);
        $baru = $this->putaran($judul, ['round' => 2, 'request_note' => 'Reviewer minta revisi minor']);

        ManuscriptFile::create([
            'title_id' => $judul->id, 'manuscript_revision_id' => $lama->id,
            'slot' => 'revisi_minta', 'status' => 'selesai',
            'version' => 1, 'original_name' => 'putaran-satu.pdf',
        ]);

        $isi = $this->actingAs($this->superadmin())
            ->get(route('naskah.show', $progress->order_detail_id))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Reviewer minta revisi minor', $isi);
        $this->assertStringContainsString('putaran-satu.pdf', $isi,
            'Berkas putaran lama harus tetap terlist setelah mundur dari LoA.');
    }

    /** @test */
    public function kartu_pada_buku_tidak_berjudul_revisi(): void
    {
        $judul = Title::create(['title' => 'Buku Kembali', 'jenis' => 'buku',
                                'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $detail = \App\Models\OrderDetail::factory()->create([
            'type' => 'bk_mandiri', 'title' => $judul->title, 'title_id' => $judul->id,
        ]);
        $progress = \App\Models\TitleProgress::create([
            'order_detail_id' => $detail->id, 'status' => 'pembuatan',
            'assigned_role' => 'production', 'started_at' => now(),
        ]);
        $this->putaran($judul, ['stage' => 'pembuatan', 'from_stage' => 'editing']);

        $isi = $this->actingAs($this->superadmin())
            ->get(route('naskah.show', $progress->order_detail_id))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Dikembalikan ke Pembuatan', $isi);
    }
```

- [ ] **Step 2: Jalankan, pastikan gagal**

```
php artisan test --filter="kartu_revisi_menampilkan_putaran_lama|kartu_pada_buku_tidak_berjudul_revisi"
```

- [ ] **Step 3: Kirim datanya dari controller**

Di `DetailNaskahController::show()`, tambahkan ke data view:

```php
            'putaran' => $progress->orderDetail?->titleRef
                ? \App\Models\ManuscriptRevision::with(['files', 'requestedBy', 'assignedTo', 'closedBy'])
                    ->where('title_id', $progress->orderDetail->title_id)
                    ->orderByDesc('round')
                    ->get()
                : collect(),
```

- [ ] **Step 4: Tulis partialnya**

Buat `resources/views/naskah/partials/revisi.blade.php`:

```blade
{{--
    Kartu Putaran Perbaikan.

    Judulnya mengikuti `stage` putaran, bukan dipatok "Revisi": buku tak punya tahap
    revisi sama sekali, dan kartu berjudul "Revisi" di layar buku membuat orang
    meragukan seluruh halamannya.

    Putaran terbuka tampil terbuka; yang sudah ditutup terlipat dan hanya-baca — itulah
    yang membuat "berkas revisi lama masih terlist" saat mundur dari LoA.
--}}
@if ($putaran->isNotEmpty())
@php
    $judulKartu = fn ($p) => $p->stage === 'pembuatan' ? 'Dikembalikan ke Pembuatan' : 'Revisi';
@endphp

<div class="card mb-3"><div class="card-body">
    <h6 class="text-uppercase text-muted small fw-bold mb-3">Putaran Perbaikan</h6>

    @foreach ($putaran as $p)
        @php $terbuka = $p->closed_at === null; @endphp

        <div class="border rounded p-2 mb-2 {{ $terbuka ? 'bg-light' : '' }}">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <strong class="small">{{ $judulKartu($p) }} · putaran {{ $p->round }}</strong>
                    <div class="text-muted small">
                        dibuka dari {{ \App\Models\TitleProgress::labelFor($p->from_stage) }}
                        · {{ $p->created_at?->translatedFormat('j M Y') }}
                    </div>
                </div>
                @if (! $terbuka)
                    <span class="badge bg-secondary">selesai</span>
                @endif
            </div>

            <div class="small mt-2">
                <span class="text-muted">Diminta</span> {{ $p->requestedBy?->name ?? '—' }}
                @if ($p->assignedTo)
                    → <span class="text-muted">ditujukan</span> <strong>{{ $p->assignedTo->name }}</strong>
                @endif
            </div>
            <div class="small fst-italic mt-1">"{{ $p->request_note }}"</div>

            @foreach (['revisi_minta' => 'Permintaan', 'revisi_hasil' => 'Hasil'] as $slot => $label)
                @php $berkas = $p->files->where('slot', $slot); @endphp
                <div class="mt-2">
                    <div class="text-muted small fw-bold">{{ $label }}</div>
                    @forelse ($berkas as $b)
                        <div class="small d-flex justify-content-between">
                            <span>{{ $b->original_name }}</span>
                            @if ($b->status === 'selesai' && $b->drive_url)
                                <a href="{{ $b->drive_url }}" target="_blank" rel="noopener">buka</a>
                            @elseif ($b->status === 'antre')
                                <span class="text-muted">antre …</span>
                            @else
                                <span class="text-danger">gagal</span>
                            @endif
                        </div>
                    @empty
                        <div class="small text-muted">— belum ada —</div>
                    @endforelse
                </div>
            @endforeach

            @if ($terbuka && ($izin['upload'] ?? false))
                <form method="POST" action="{{ route('naskah.revisi.hasil', $progress->order_detail_id) }}"
                      enctype="multipart/form-data" class="mt-2">
                    @csrf
                    <input type="hidden" name="revision_id" value="{{ $p->id }}">
                    <div class="d-flex gap-1">
                        <input type="file" name="berkas[]" multiple required
                               class="form-control form-control-sm">
                        <button class="btn btn-sm btn-primary text-nowrap">Unggah hasil</button>
                    </div>
                </form>
            @endif

            @if ($terbuka && ($izin['advance'] ?? false))
                <form method="POST" action="{{ route('naskah.revisi.tutup', $progress->order_detail_id) }}"
                      class="mt-2 d-flex gap-1">
                    @csrf
                    <input type="hidden" name="revision_id" value="{{ $p->id }}">
                    <input type="text" name="close_note" required class="form-control form-control-sm"
                           placeholder="Alasan menutup tanpa berkas (wajib)">
                    <button class="btn btn-sm btn-outline-secondary text-nowrap">Tutup putaran</button>
                </form>
            @endif

            @if (! $terbuka && $p->close_note)
                <div class="small text-muted mt-2">
                    Ditutup {{ $p->closedBy?->name ?? '—' }}: "{{ $p->close_note }}"
                </div>
            @endif
        </div>
    @endforeach

    @if ($progress->status === 'revisi' && ($izin['advance'] ?? false))
        <button class="btn btn-sm btn-outline-primary w-100" data-bs-toggle="collapse"
                data-bs-target="#formMintaRevisi">+ Minta revisi baru</button>

        <div class="collapse mt-2" id="formMintaRevisi">
            <form method="POST" action="{{ route('naskah.revisi.minta', $progress->order_detail_id) }}"
                  enctype="multipart/form-data" class="border rounded p-2">
                @csrf
                <textarea name="request_note" rows="2" required class="form-control form-control-sm mb-2"
                          placeholder="Apa yang diminta reviewer? (wajib)"></textarea>
                <input type="file" name="berkas[]" multiple class="form-control form-control-sm mb-2">
                <button class="btn btn-sm btn-primary">Kirim permintaan</button>
            </form>
        </div>
    @endif
</div></div>
@endif
```

- [ ] **Step 5: Sisipkan ke halaman detail**

Di `resources/views/naskah/detail.blade.php`, tambahkan tepat sesudah
`@include('naskah.partials.file-naskah', ...)`:

```blade
                @include('naskah.partials.revisi')
```

- [ ] **Step 6: Jalankan**

```
php artisan test --filter="PutaranRevisiTest|NaskahDetail|NaskahLayar"
```

Harapan: 0 gagal.

- [ ] **Step 7: Commit**

```bash
git add resources/views/naskah/partials/revisi.blade.php \
        resources/views/naskah/detail.blade.php \
        app/Http/Controllers/Pages/Naskah/DetailNaskahController.php \
        tests/Feature/PutaranRevisiTest.php
git commit -m "naskah: kartu putaran perbaikan, lengkap dengan riwayat putaran lama"
```

---

## Task 11: Tombol mundur di Kartu Aksi, dan bug buku (T2)

**Files:**
- Modify: `resources/views/naskah/partials/aksi.blade.php:105-140`
- Test: `tests/Feature/RevisiSetelahSubmitTest.php`

- [ ] **Step 1: Tulis tes yang gagal**

```php
    /**
     * Tombol "Perlu Revisi" lama muncul di tahap editing TANPA memeriksa jenis naskah,
     * dan BOOK_STAGES tak punya `revisi` — jadi pada buku ia memajukan naskah ke Layout.
     * Bug ini sudah ada sebelum pekerjaan ini; tes ini menguburnya.
     *
     * @test
     */
    public function buku_dikembalikan_ke_pembuatan_bukan_melompat_ke_layout(): void
    {
        $p = $this->naskah('editing', 'bk_mandiri');

        app(TitleProgressService::class)
            ->kembalikan($p, 'pembuatan', $this->admin(), 'Sitasi bab 2 belum lengkap');

        $this->assertSame('pembuatan', $p->fresh()->status);
    }

    /** @test */
    public function tombol_kembalikan_muncul_di_editing_dan_loa(): void
    {
        $admin = $this->admin();

        $editing = $this->naskah('editing');
        $isi = $this->actingAs($admin)->get(route('naskah.show', $editing->order_detail_id))
            ->assertOk()->getContent();
        $this->assertStringContainsString('Kembalikan ke Pembuatan', $isi);

        $loa = $this->naskah('loa');
        $isi = $this->actingAs($admin)->get(route('naskah.show', $loa->order_detail_id))
            ->assertOk()->getContent();
        $this->assertStringContainsString('Kembalikan ke Revisi', $isi);
    }

    /** @test */
    public function tombol_kembalikan_tidak_muncul_di_tahap_lain(): void
    {
        $isi = $this->actingAs($this->admin())
            ->get(route('naskah.show', $this->naskah('submit')->order_detail_id))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('Kembalikan ke', $isi);
    }
```

- [ ] **Step 2: Jalankan, pastikan gagal**

```
php artisan test --filter="buku_dikembalikan_ke_pembuatan|tombol_kembalikan"
```

- [ ] **Step 3: Ganti bloknya di `aksi.blade.php`**

**Hapus** blok tombol `Perlu Revisi` dan blok `collapse #formRevisi` yang lama, ganti
dengan yang berbasis `MUNDUR_SAH`:

```blade
        @php $targetMundur = \App\Services\TitleProgressService::MUNDUR_SAH[$progress->status] ?? null; @endphp
        @if ($izin['advance'] && $targetMundur)
            <button class="btn btn-outline-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#formKembalikan">
                ↩ Kembalikan ke {{ \App\Models\TitleProgress::labelFor($targetMundur) }}
            </button>
        @endif
```

dan blok collapse-nya:

```blade
    @if ($izin['advance'] && $targetMundur)
        <div class="collapse mt-3" id="formKembalikan">
            <form method="POST" action="{{ route('naskah.kembalikan', $progress->order_detail_id) }}"
                  enctype="multipart/form-data" class="border rounded p-3">
                @csrf
                <label class="form-label small fw-bold">
                    Alasan mengembalikan ke {{ \App\Models\TitleProgress::labelFor($targetMundur) }}
                </label>
                <textarea name="alasan" rows="2" required class="form-control form-control-sm mb-2"
                          placeholder="Apa yang perlu diperbaiki? (wajib — dibaca pelaksana)"></textarea>
                <input type="file" name="berkas[]" multiple class="form-control form-control-sm mb-2">
                <div class="form-text mb-2">
                    Berkas dan catatan ini ditujukan ke
                    {{ $progress->pelaksana?->name ?? 'pelaksana naskah' }}.
                </div>
                <button class="btn btn-sm btn-primary">Kembalikan naskah</button>
            </form>
        </div>
    @endif
```

Karena daftarnya diturunkan dari `MUNDUR_SAH`, tombolnya otomatis benar untuk buku
(`editing → pembuatan`) maupun artikel, dan tak pernah muncul di tahap yang tak
mendukungnya. Bug T2 tertutup karena strukturnya, bukan karena ditambal.

- [ ] **Step 4: Jalankan**

```
php artisan test --filter="RevisiSetelahSubmitTest|NaskahDetail|NaskahLayar"
```

Harapan: 0 gagal.

- [ ] **Step 5: Commit**

```bash
git add resources/views/naskah/partials/aksi.blade.php \
        tests/Feature/RevisiSetelahSubmitTest.php
git commit -m "naskah: tombol kembalikan menggantikan Perlu Revisi, buku tak lagi melompat ke Layout"
```

---

## Task 12: Regresi — unggah revisi tak memajukan tahap

`autoAdvanceOnUpload()` sudah pulang lebih awal untuk slot selain `masuk`, jadi
perilakunya **sudah benar** tanpa kode baru. Yang kurang hanya pagarnya.

**Files:**
- Test: `tests/Feature/PutaranRevisiTest.php`

- [ ] **Step 1: Tulis pagarnya**

```php
    /**
     * autoAdvanceOnUpload() hanya bereaksi pada slot `masuk`. Perilakunya sudah benar
     * hari ini — tes ini memagarinya, karena menambah slot ke daftar pemicu adalah
     * perubahan satu baris yang akan mendorong naskah ke LoA diam-diam.
     *
     * @test
     */
    public function mengunggah_hasil_revisi_tidak_memajukan_tahap(): void
    {
        [$judul, $progress] = $this->naskahBerjudul('revisi');
        $p = $this->putaran($judul);

        app(\App\Services\ManuscriptRevisionService::class)->jawab(
            $p, $this->superadmin(),
            [\Illuminate\Http\UploadedFile::fake()->create('hasil.docx', 20)]
        );

        $this->assertSame('revisi', $progress->fresh()->status,
            'Yang memajukan naskah tetap tombol PJ, bukan kedatangan berkas.');
    }
```

- [ ] **Step 2: Jalankan**

```
php artisan test --filter=mengunggah_hasil_revisi_tidak_memajukan_tahap
```

Harapan: LULUS langsung. Bila GAGAL, `ManuscriptFileService::majukanTahapSetelahUnggah()`
ternyata memicu untuk slot ini — tambahkan penjaga slot di sana dan jalankan ulang.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/PutaranRevisiTest.php
git commit -m "naskah: pagar agar unggahan revisi tak pernah memajukan tahap sendiri"
```

---

## Penutup

- [ ] **Jalankan suite penuh sekali di akhir**

```
php artisan test
```

Harapan: 0 gagal. Baseline sebelum pekerjaan ini: **1252 lulus, 1 dilewati, 0 gagal**.
Jumlah yang lulus akan naik sekitar 25.

Bila ada kegagalan yang tak berhubungan dengan naskah, periksa lebih dulu apakah ada
sesi lain yang sedang menjalankan tes terhadap `avidpedi_simapa_test` — dua proses
berbagi satu DB uji menghasilkan kegagalan palsu yang meyakinkan.

- [ ] **Jalankan migrasi pada DB dev**

```
php artisan migrate
```

- [ ] **Periksa DB produksi sebelum rilis**

```sql
SELECT COUNT(*) FROM tb_title_progress WHERE status = 'revisi';
```

Nol = tak ada yang perlu dipikirkan. Lebih dari nol = migrasi backfill akan menilai
ulang tiap barisnya menurut aturan §7.2 spec, dan hasilnya layak dilihat sekali
sebelum dianggap selesai.

- [ ] **Selesaikan branch**

Pakai superpowers:finishing-a-development-branch.

---

## Yang sengaja tak ada di rencana ini

- **Status `ditolak`** — butuh jawaban soal uang lebih dulu (order dari naskah yang
  ditolak masuk keadaan apa). Tetap di backlog.
- **Perapian folder Google Drive** — spec terpisah. Berkas `revisi_minta`/`revisi_hasil`
  akan mendarat di folder root yang sama berantakannya dengan berkas naskah lain sampai
  pekerjaan itu dikerjakan. Diketahui, bukan terlewat.
- **Putaran per bab** — `title_chapter_id` ada di skema supaya tak perlu migrasi kedua,
  tapi tak ada UI yang mengisinya.
