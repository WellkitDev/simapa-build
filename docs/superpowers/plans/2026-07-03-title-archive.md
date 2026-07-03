# Arsip Judul Selesai (Fase 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ubah menu "Arsip Judul" jadi arsip judul selesai: record penyelesaian + artefak (nilai/PIC/catatan), cek kelayakan (lunas + manuskrip final), alur Ajukan→notif→Approve/Tolak, halaman Arsip + detail konsolidasi.

**Architecture:** 2 tabel (`tb_title_archives` header, `tb_title_archive_artifacts` baris). `TitleArchivalService` memusatkan defaultArtifacts(prefill)/saveArtifacts(upload)/submit(eligibility+notify)/approve/reject. Kelayakan via `Order::isLunas` + `Title::archiveEligible`. `TitleArchiveController` + rute `archive.*`; view `archive/index` & `archive/show`.

**Tech Stack:** Laravel 11, Eloquent, Blade + Bootstrap 5 + DataTables, GoogleDriveService (upload), Notifier, Spatie roles. Test: PHPUnit via `.env.testing`, Drive di-mock, `UploadedFile::fake()`.

---

## File Structure

- `database/migrations/2026_07_03_000007_create_tb_title_archives_table.php` (**create**)
- `database/migrations/2026_07_03_000008_create_tb_title_archive_artifacts_table.php` (**create**)
- `app/Models/TitleArchive.php`, `app/Models/TitleArchiveArtifact.php` (**create**)
- `app/Models/Title.php` (**modify**) — relations + eligibility helpers
- `app/Models/Order.php` (**modify**) — `isLunas()`
- `app/Services/TitleArchivalService.php` (**create**)
- `app/Services/Notifier.php` (**modify**) — `titleArchiveSubmitted`
- `app/Http/Controllers/Pages/TitleArchiveController.php` (**create**)
- `routes/web.php` (**modify**)
- `resources/views/archive/index.blade.php`, `resources/views/archive/show.blade.php` (**create**)
- `resources/views/layouts/sidebar.blade.php` (**modify**) — repurpose menu
- `tests/Unit/TitleArchiveEligibleTest.php`, `tests/Feature/TitleArchiveTest.php` (**create**)

---

## Konteks untuk implementer

- `Order`: `hasOne orderDetail` (cost di `orderDetail->cost_amount`), `hasMany invoices` (status `lunas`), `hasMany payments` (status `paid`, kolom `amount`). Fixture Invoice wajib: `order_id`,`invoice_no`(unik),`issued_at`,`status`. Payment wajib: `order_id`,`payment_type`,`amount`,`status`.
- `Title::manuscriptStatus()` → tahap bottleneck (null bila tak ada order). `TitleProgress::isFinal($s)` = terbit/publish. `Title->bookIsbn` (hasOne), `JournalSubmission::where('title_id',id)->latest()->first()` (loa_url/link_publish/bukti_bayar_url).
- `GoogleDriveService::uploadFile($file,null,false)['url']`. Pola prefill/upload seperti DocChecklistService.
- `Notifier::send($this->roleUsers([...],$actor), [...])` — lihat `titleInfoUpdated`.
- Migrasi terakhir di main: `2026_07_03_000006`. Nomor baru 000007/000008.
- Fixture role: `foreach (['marketing','manager','superadmin','production','admin'] as $r) Role::create(['name'=>$r,'guard_name'=>'web']);`.

---

### Task 1: Migrasi + Model + helper kelayakan + unit test

**Files:** 2 migrasi; `TitleArchive.php`; `TitleArchiveArtifact.php`; `Title.php`; `Order.php`; `tests/Unit/TitleArchiveEligibleTest.php`

- [ ] **Step 1: Unit test kelayakan (gagal dulu)** — `tests/Unit/TitleArchiveEligibleTest.php`:

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\Invoice;
use App\Models\TitleProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TitleArchiveEligibleTest extends TestCase
{
    use RefreshDatabase;

    private function orderFor(Title $book, int $cost, string $stage): Order
    {
        $owner = User::factory()->create();
        $order = Order::create(['code_order' => 'ORD-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
        OrderDetail::create(['order_id' => $order->id, 'title_id' => $book->id, 'type' => 'bk_mandiri', 'title' => $book->title, 'slug' => 'b-' . uniqid(), 'chapters' => 1, 'cost_amount' => $cost, 'naskah_type' => 'mandiri', 'publication_type' => 'regular']);
        TitleProgress::create(['order_detail_id' => $order->orderDetail->id, 'status' => $stage, 'assigned_role' => TitleProgress::getHandlerForStatus($stage), 'started_at' => now()]);
        return $order;
    }

    private function book(string $stage = 'terbit'): Title
    {
        $b = Title::create(['title' => 'Buku ' . uniqid(), 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $this->orderFor($b, 100000, $stage);
        return $b->fresh();
    }

    /** @test */
    public function order_is_lunas_by_payment_or_invoice(): void
    {
        $book = $this->book();
        $order = $book->orderDetails->first()->order;
        $this->assertFalse($order->isLunas());

        Payment::create(['order_id' => $order->id, 'payment_type' => 'pelunasan', 'amount' => 100000, 'status' => 'paid', 'paid_at' => now()]);
        $this->assertTrue($order->fresh()->isLunas());

        $book2 = $this->book();
        $order2 = $book2->orderDetails->first()->order;
        Invoice::create(['order_id' => $order2->id, 'invoice_no' => 'INV-' . uniqid(), 'issued_at' => now(), 'status' => 'lunas']);
        $this->assertTrue($order2->fresh()->isLunas());
    }

    /** @test */
    public function archive_eligible_needs_paidoff_and_final(): void
    {
        $book = $this->book('terbit'); // final
        $order = $book->orderDetails->first()->order;
        $this->assertFalse($book->fresh()->archiveEligible()); // belum lunas

        Payment::create(['order_id' => $order->id, 'payment_type' => 'pelunasan', 'amount' => 100000, 'status' => 'paid', 'paid_at' => now()]);
        $this->assertTrue($book->fresh()->archiveEligible()); // lunas + final

        $notFinal = $this->book('editing');
        $o = $notFinal->orderDetails->first()->order;
        Payment::create(['order_id' => $o->id, 'payment_type' => 'pelunasan', 'amount' => 100000, 'status' => 'paid', 'paid_at' => now()]);
        $this->assertFalse($notFinal->fresh()->archiveEligible()); // lunas tapi belum final
    }
}
```

- [ ] **Step 2: Jalankan — GAGAL** (`Order::isLunas`/`archiveEligible` belum ada).
Run: `php artisan test --env=testing tests/Unit/TitleArchiveEligibleTest.php`
Expected: FAIL — undefined method.

- [ ] **Step 3: Migrasi `2026_07_03_000007_create_tb_title_archives_table.php`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_title_archives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('title_id')->unique()->constrained('tb_titles')->cascadeOnDelete();
            $table->string('status')->default('draft'); // draft | diajukan | disetujui | ditolak
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_note')->nullable();
            $table->text('reject_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('tb_title_archives'); }
};
```

- [ ] **Step 4: Migrasi `2026_07_03_000008_create_tb_title_archive_artifacts_table.php`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_title_archive_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('title_id')->constrained('tb_titles')->cascadeOnDelete();
            $table->string('key')->nullable();
            $table->string('label');
            $table->string('type')->default('text'); // file | link | text
            $table->text('value')->nullable();
            $table->string('file_name')->nullable();
            $table->foreignId('pic_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->boolean('is_custom')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('tb_title_archive_artifacts'); }
};
```

- [ ] **Step 5: Model `app/Models/TitleArchive.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TitleArchive extends Model
{
    protected $table = 'tb_title_archives';

    protected $fillable = ['title_id', 'status', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'approval_note', 'reject_note'];

    protected $casts = ['submitted_at' => 'datetime', 'approved_at' => 'datetime'];

    const STATUSES = ['draft' => 'Draft', 'diajukan' => 'Diajukan', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak'];

    const BOOK_ARTIFACTS = [
        'isbn'            => ['label' => 'No. ISBN',                   'type' => 'text'],
        'barcode_file'    => ['label' => 'File Barcode',              'type' => 'file'],
        'publish_link'    => ['label' => 'Link Buku Publish',         'type' => 'link'],
        'scholar_link'    => ['label' => 'Link Scholar',              'type' => 'link'],
        'hki_file'        => ['label' => 'File HKI',                  'type' => 'file'],
        'final_book_file' => ['label' => 'File Buku Final (ber-ISBN)', 'type' => 'file'],
    ];
    const ARTICLE_ARTIFACTS = [
        'loa'          => ['label' => 'LoA',             'type' => 'file'],
        'publish_link' => ['label' => 'Link Publish',    'type' => 'link'],
        'final_naskah' => ['label' => 'Naskah Final',    'type' => 'file'],
        'apc_bukti'    => ['label' => 'Bukti Bayar APC', 'type' => 'file'],
    ];

    public static function artifactsFor(string $jenis): array
    {
        return $jenis === 'buku' ? self::BOOK_ARTIFACTS : self::ARTICLE_ARTIFACTS;
    }

    public function statusLabel(): string { return self::STATUSES[$this->status] ?? $this->status; }
    public function title() { return $this->belongsTo(Title::class); }
    public function submitter() { return $this->belongsTo(User::class, 'submitted_by'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }
}
```

- [ ] **Step 6: Model `app/Models/TitleArchiveArtifact.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TitleArchiveArtifact extends Model
{
    protected $table = 'tb_title_archive_artifacts';

    protected $fillable = ['title_id', 'key', 'label', 'type', 'value', 'file_name', 'pic_user_id', 'note', 'is_custom', 'position'];

    protected $casts = ['is_custom' => 'boolean'];

    public function title() { return $this->belongsTo(Title::class); }
    public function pic() { return $this->belongsTo(User::class, 'pic_user_id'); }
}
```

- [ ] **Step 7: `app/Models/Order.php` — `isLunas()`** (tambah method di kelas):

```php
    public function isLunas(): bool
    {
        if ($this->invoices()->where('status', 'lunas')->exists()) {
            return true;
        }
        $paid = (int) $this->payments()->where('status', 'paid')->sum('amount');
        $cost = (int) optional($this->orderDetail)->cost_amount;
        return $paid >= $cost;
    }
```

- [ ] **Step 8: `app/Models/Title.php` — relasi + helper** (tambah setelah `docChecklist()` atau relasi lain):

```php
    public function archive()
    {
        return $this->hasOne(TitleArchive::class);
    }

    public function archiveArtifacts()
    {
        return $this->hasMany(TitleArchiveArtifact::class)->orderBy('position');
    }

    public function isPaidOff(): bool
    {
        $orders = $this->orderDetails->map->order->filter()->unique('id');
        return $orders->isNotEmpty() && $orders->every(fn ($o) => $o->isLunas());
    }

    public function manuscriptIsFinal(): bool
    {
        return TitleProgress::isFinal((string) $this->manuscriptStatus());
    }

    public function archiveEligible(): bool
    {
        return $this->isPaidOff() && $this->manuscriptIsFinal();
    }
```

- [ ] **Step 9: Jalankan — PASS**
Run: `php artisan test --env=testing tests/Unit/TitleArchiveEligibleTest.php`
Expected: 2 passed.

- [ ] **Step 10: Commit**

```bash
git add database/migrations/2026_07_03_000007_create_tb_title_archives_table.php database/migrations/2026_07_03_000008_create_tb_title_archive_artifacts_table.php app/Models/TitleArchive.php app/Models/TitleArchiveArtifact.php app/Models/Title.php app/Models/Order.php tests/Unit/TitleArchiveEligibleTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(archive): tabel arsip+artefak + model + kelayakan (Order::isLunas, archiveEligible)

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 2: TitleArchivalService + Notifier + unit test service

**Files:** `app/Services/TitleArchivalService.php`; `app/Services/Notifier.php`; `tests/Unit/TitleArchivalServiceTest.php`

- [ ] **Step 1: Unit test service (gagal dulu)** — `tests/Unit/TitleArchivalServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Payment;
use App\Models\TitleProgress;
use App\Models\TitleArchive;
use App\Models\TitleArchiveArtifact;
use App\Services\TitleArchivalService;
use App\Services\GoogleDriveService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Mockery;

class TitleArchivalServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['manager', 'superadmin'] as $r) { Role::create(['name' => $r, 'guard_name' => 'web']); }
    }

    private function service(): TitleArchivalService
    {
        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('uploadFile')->andReturn(['id' => 'x', 'name' => 'f', 'url' => 'http://drive/f.pdf']);
        return new TitleArchivalService($drive);
    }

    private function eligibleBook(): Title
    {
        $book = Title::create(['title' => 'Buku ' . uniqid(), 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $owner = User::factory()->create();
        $order = Order::create(['code_order' => 'ORD-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
        OrderDetail::create(['order_id' => $order->id, 'title_id' => $book->id, 'type' => 'bk_mandiri', 'title' => $book->title, 'slug' => 'b-' . uniqid(), 'chapters' => 1, 'cost_amount' => 100000, 'naskah_type' => 'mandiri', 'publication_type' => 'regular']);
        TitleProgress::create(['order_detail_id' => $order->orderDetail->id, 'status' => 'terbit', 'assigned_role' => 'superadmin', 'started_at' => now()]);
        Payment::create(['order_id' => $order->id, 'payment_type' => 'pelunasan', 'amount' => 100000, 'status' => 'paid', 'paid_at' => now()]);
        return $book->fresh();
    }

    /** @test */
    public function save_artifacts_upserts_fixed_custom_and_uploads(): void
    {
        $book = $this->eligibleBook();
        $actor = User::factory()->create();
        $this->service()->saveArtifacts($book, [
            'isbn'         => ['value' => '978-1', 'pic_user_id' => $actor->id, 'note' => 'ok'],
            'barcode_file' => ['file' => UploadedFile::fake()->create('bc.pdf', 5), 'pic_user_id' => $actor->id],
        ], [
            ['label' => 'Sertifikat', 'type' => 'link', 'value' => 'http://x', 'pic_user_id' => $actor->id],
        ], $actor);

        $isbn = TitleArchiveArtifact::where('title_id', $book->id)->where('key', 'isbn')->first();
        $this->assertSame('978-1', $isbn->value);
        $this->assertSame($actor->id, $isbn->pic_user_id);
        $barcode = TitleArchiveArtifact::where('title_id', $book->id)->where('key', 'barcode_file')->first();
        $this->assertSame('http://drive/f.pdf', $barcode->value);
        $this->assertSame(1, TitleArchiveArtifact::where('title_id', $book->id)->where('is_custom', true)->count());
    }

    /** @test */
    public function submit_requires_eligibility_then_sets_diajukan(): void
    {
        $notEligible = Title::create(['title' => 'X', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $this->expectException(ValidationException::class);
        $this->service()->submit($notEligible, User::factory()->create());
    }

    /** @test */
    public function submit_ok_and_approve_reject(): void
    {
        $book = $this->eligibleBook();
        $actor = User::factory()->create();
        $archive = $this->service()->submit($book, $actor);
        $this->assertSame('diajukan', $archive->status);
        $this->assertSame($actor->id, $archive->submitted_by);

        $sa = User::factory()->create();
        $this->service()->approve($book, $sa, 'lengkap');
        $this->assertSame('disetujui', $book->archive()->first()->status);
        $this->assertSame('lengkap', $book->archive()->first()->approval_note);

        $this->service()->reject($book, $sa, 'kurang');
        $this->assertSame('ditolak', $book->archive()->first()->status);
    }
}
```

- [ ] **Step 2: Jalankan — GAGAL** (service belum ada).
Run: `php artisan test --env=testing tests/Unit/TitleArchivalServiceTest.php`
Expected: FAIL — class tidak ada.

- [ ] **Step 3: Buat `app/Services/TitleArchivalService.php`**

```php
<?php

namespace App\Services;

use App\Models\JournalSubmission;
use App\Models\Title;
use App\Models\TitleArchive;
use App\Models\TitleArchiveArtifact;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class TitleArchivalService
{
    public function __construct(private GoogleDriveService $drive) {}

    /** Daftar artefak baku (dengan prefill dari data existing) untuk render form. */
    public function defaultArtifacts(Title $title): array
    {
        $existing = $title->archiveArtifacts->keyBy('key');
        $submission = JournalSubmission::where('title_id', $title->id)->latest()->first();
        $prefill = [
            'isbn'         => optional($title->bookIsbn)->no_isbn,
            'loa'          => optional($submission)->loa_url,
            'publish_link' => optional($submission)->link_publish,
            'apc_bukti'    => optional($submission)->bukti_bayar_url,
        ];

        $out = [];
        foreach (TitleArchive::artifactsFor($title->jenis) as $key => $def) {
            $row = $existing->get($key);
            $out[] = [
                'key'       => $key,
                'label'     => $def['label'],
                'type'      => $def['type'],
                'value'     => $row->value ?? ($prefill[$key] ?? null),
                'file_name' => $row->file_name ?? null,
                'pic_user_id' => $row->pic_user_id ?? null,
                'note'      => $row->note ?? null,
            ];
        }
        return $out;
    }

    public function saveArtifacts(Title $title, array $fixed, array $custom, User $actor): void
    {
        foreach (TitleArchive::artifactsFor($title->jenis) as $key => $def) {
            $item = $fixed[$key] ?? [];
            $attrs = [
                'label'       => $def['label'],
                'type'        => $def['type'],
                'pic_user_id' => ($item['pic_user_id'] ?? '') ?: null,
                'note'        => $item['note'] ?? null,
                'is_custom'   => false,
            ];
            if ($def['type'] === 'file') {
                if (! empty($item['file'])) {
                    $attrs['value']     = $this->drive->uploadFile($item['file'], null, false)['url'] ?? null;
                    $attrs['file_name'] = $item['file']->getClientOriginalName();
                }
                // tanpa file baru: value/file_name tak diikutkan → dipertahankan
            } else {
                $attrs['value'] = ($item['value'] ?? '') ?: null;
            }
            TitleArchiveArtifact::updateOrCreate(['title_id' => $title->id, 'key' => $key], $attrs);
        }

        // "Lainnya" (custom): ganti seluruh set.
        TitleArchiveArtifact::where('title_id', $title->id)->where('is_custom', true)->delete();
        $pos = 0;
        foreach ($custom as $c) {
            $label = trim((string) ($c['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            TitleArchiveArtifact::create([
                'title_id'    => $title->id,
                'key'         => null,
                'label'       => $label,
                'type'        => in_array($c['type'] ?? '', ['link', 'text'], true) ? $c['type'] : 'text',
                'value'       => ($c['value'] ?? '') ?: null,
                'pic_user_id' => ($c['pic_user_id'] ?? '') ?: null,
                'note'        => $c['note'] ?? null,
                'is_custom'   => true,
                'position'    => $pos++,
            ]);
        }
    }

    public function submit(Title $title, User $actor): TitleArchive
    {
        if (! $title->archiveEligible()) {
            throw ValidationException::withMessages(['archive' => 'Belum bisa diarsipkan: pastikan pembayaran lunas dan manuskrip final (terbit/publish).']);
        }
        $archive = TitleArchive::updateOrCreate(
            ['title_id' => $title->id],
            ['status' => 'diajukan', 'submitted_by' => $actor->id, 'submitted_at' => now()]
        );
        app(Notifier::class)->titleArchiveSubmitted($archive, $actor);
        return $archive;
    }

    public function approve(Title $title, User $actor, ?string $note): TitleArchive
    {
        return TitleArchive::updateOrCreate(
            ['title_id' => $title->id],
            ['status' => 'disetujui', 'approved_by' => $actor->id, 'approved_at' => now(), 'approval_note' => $note]
        );
    }

    public function reject(Title $title, User $actor, string $note): TitleArchive
    {
        return TitleArchive::updateOrCreate(
            ['title_id' => $title->id],
            ['status' => 'ditolak', 'reject_note' => $note]
        );
    }
}
```

- [ ] **Step 4: `app/Services/Notifier.php` — `titleArchiveSubmitted`** (tambah method setelah `titleInfoUpdated`; tambah `use App\Models\TitleArchive;` di atas bila perlu — atau ketik type hint FQN):

```php
    public function titleArchiveSubmitted(\App\Models\TitleArchive $archive, User $actor): void
    {
        $archive->loadMissing('title');
        $this->send($this->roleUsers(['superadmin', 'manager'], $actor), [
            'category' => 'title',
            'title'    => 'Judul diajukan ke arsip',
            'message'  => trim((optional($archive->title)->code ? $archive->title->code . ' — ' : '') . optional($archive->title)->title),
            'url'      => route('archive.show', $archive->title_id),
            'icon'     => 'archive',
        ]);
    }
```

- [ ] **Step 5: Jalankan — PASS**
Run: `php artisan test --env=testing tests/Unit/TitleArchivalServiceTest.php`
Expected: 3 passed.

- [ ] **Step 6: Commit**

```bash
git add app/Services/TitleArchivalService.php app/Services/Notifier.php tests/Unit/TitleArchivalServiceTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(archive): TitleArchivalService (artefak/submit/approve/reject) + Notifier arsip

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 3: TitleArchiveController + rute + feature test

**Files:** `app/Http/Controllers/Pages/TitleArchiveController.php`; `routes/web.php`; `tests/Feature/TitleArchiveTest.php`

- [ ] **Step 1: Feature test (gagal dulu)** — `tests/Feature/TitleArchiveTest.php`:

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
use App\Models\TitleArchiveArtifact;
use App\Services\GoogleDriveService;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class TitleArchiveTest extends TestCase
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

    private function eligibleBook(): Title
    {
        $book = Title::create(['title' => 'Buku Arsip ' . uniqid(), 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $owner = $this->user('production');
        $order = Order::create(['code_order' => 'ORD-' . uniqid(), 'user_id' => $owner->id, 'status' => 'pending', 'ordered_at' => now()]);
        OrderDetail::create(['order_id' => $order->id, 'title_id' => $book->id, 'type' => 'bk_mandiri', 'title' => $book->title, 'slug' => 'b-' . uniqid(), 'chapters' => 1, 'cost_amount' => 100000, 'naskah_type' => 'mandiri', 'publication_type' => 'regular']);
        TitleProgress::create(['order_detail_id' => $order->orderDetail->id, 'status' => 'terbit', 'assigned_role' => 'superadmin', 'started_at' => now()]);
        Payment::create(['order_id' => $order->id, 'payment_type' => 'pelunasan', 'amount' => 100000, 'status' => 'paid', 'paid_at' => now()]);
        return $book->fresh();
    }

    /** @test */
    public function admin_saves_artifacts_with_pic(): void
    {
        $book = $this->eligibleBook();
        $this->mock(GoogleDriveService::class, fn ($m) => $m->shouldReceive('uploadFile')->andReturn(['url' => 'http://drive/f.pdf', 'id' => 'x', 'name' => 'f']));
        $pic = $this->user('production');

        $this->actingAs($this->user('admin'))->put(route('archive.artifacts', $book->id), [
            'fixed' => [
                'isbn'         => ['value' => '978-1', 'pic_user_id' => $pic->id],
                'barcode_file' => ['file' => UploadedFile::fake()->create('bc.pdf', 5)],
            ],
            'custom' => [['label' => 'Sertifikat', 'type' => 'link', 'value' => 'http://x']],
        ])->assertRedirect(route('archive.show', $book->id));

        $this->assertSame('978-1', TitleArchiveArtifact::where('title_id', $book->id)->where('key', 'isbn')->first()->value);
        $this->assertSame(1, TitleArchiveArtifact::where('title_id', $book->id)->where('is_custom', true)->count());
    }

    /** @test */
    public function submit_rejected_when_not_eligible(): void
    {
        $book = Title::create(['title' => 'Belum', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $this->actingAs($this->user('admin'))->post(route('archive.submit', $book->id))->assertRedirect();
        $this->assertNull(TitleArchive::where('title_id', $book->id)->first());
    }

    /** @test */
    public function submit_sets_diajukan_then_superadmin_approves(): void
    {
        $book = $this->eligibleBook();
        $this->actingAs($this->user('admin'))->post(route('archive.submit', $book->id))->assertRedirect();
        $this->assertSame('diajukan', TitleArchive::where('title_id', $book->id)->first()->status);

        $this->actingAs($this->user('superadmin'))->post(route('archive.approve', $book->id), ['approval_note' => 'ok'])->assertRedirect();
        $this->assertSame('disetujui', TitleArchive::where('title_id', $book->id)->first()->status);
    }

    /** @test */
    public function admin_cannot_approve(): void
    {
        $book = $this->eligibleBook();
        $this->actingAs($this->user('admin'))->post(route('archive.approve', $book->id), ['approval_note' => 'x'])->assertForbidden();
    }

    /** @test */
    public function index_lists_approved(): void
    {
        $book = $this->eligibleBook();
        TitleArchive::create(['title_id' => $book->id, 'status' => 'disetujui', 'approved_at' => now()]);
        $this->actingAs($this->user('manager'))->get(route('archive.index'))
            ->assertOk()->assertSee($book->title);
    }
}
```

- [ ] **Step 2: Jalankan — GAGAL** (`Route [archive.artifacts] not defined`).
Run: `php artisan test --env=testing tests/Feature/TitleArchiveTest.php`
Expected: FAIL.

- [ ] **Step 3: Buat `app/Http/Controllers/Pages/TitleArchiveController.php`**

```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\Title;
use App\Models\User;
use App\Services\TitleArchivalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class TitleArchiveController extends Controller
{
    public function __construct(private TitleArchivalService $service) {}

    private function canManage(): bool { return Auth::user()->hasAnyRole(['superadmin', 'manager', 'admin', 'production']); }
    private function canApprove(): bool { return Auth::user()->hasAnyRole(['superadmin', 'manager']); }

    public function index()
    {
        abort_unless($this->canManage(), 403);
        $approved = Title::whereHas('archive', fn ($q) => $q->where('status', 'disetujui'))
            ->with('archive.approver')->latest()->get();
        $pending = $this->canApprove()
            ? Title::whereHas('archive', fn ($q) => $q->where('status', 'diajukan'))->with('archive.submitter')->latest()->get()
            : collect();

        return view('archive.index', ['approved' => $approved, 'pending' => $pending, 'canApprove' => $this->canApprove()]);
    }

    public function show(int $id)
    {
        abort_unless($this->canManage(), 403);
        $title = Title::with([
            'chapters', 'scope', 'bookIsbn', 'archive.approver', 'archive.submitter',
            'archiveArtifacts.pic',
            'orderDetails.order.user', 'orderDetails.order.invoices', 'orderDetails.order.payments', 'orderDetails.order.orderDetail', 'orderDetails.titleProgress',
        ])->findOrFail($id);

        return view('archive.show', [
            'title'      => $title,
            'artifacts'  => $this->service->defaultArtifacts($title),
            'customArtifacts' => $title->archiveArtifacts->where('is_custom', true)->values(),
            'eligible'   => $title->archiveEligible(),
            'isPaidOff'  => $title->isPaidOff(),
            'isFinal'    => $title->manuscriptIsFinal(),
            'canManage'  => $this->canManage(),
            'canApprove' => $this->canApprove(),
            'staff'      => User::whereHas('roles', fn ($q) => $q->whereIn('name', ['superadmin', 'manager', 'admin', 'production', 'marketing']))->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function saveArtifacts(Request $request, int $id)
    {
        abort_unless($this->canManage(), 403);
        $title = Title::findOrFail($id);
        $fixed = (array) $request->input('fixed', []);
        foreach (\App\Models\TitleArchive::artifactsFor($title->jenis) as $key => $def) {
            if ($def['type'] === 'file' && $request->hasFile("fixed.$key.file")) {
                $fixed[$key]['file'] = $request->file("fixed.$key.file");
            }
        }
        $this->service->saveArtifacts($title, $fixed, (array) $request->input('custom', []), Auth::user());

        return redirect()->route('archive.show', $id)->with('success', 'Artefak penyelesaian disimpan.');
    }

    public function submit(int $id)
    {
        abort_unless($this->canManage(), 403);
        $title = Title::findOrFail($id);
        try {
            $this->service->submit($title, Auth::user());
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first() ?? 'Belum bisa diarsipkan.');
        }
        return redirect()->route('archive.show', $id)->with('success', 'Judul diajukan ke arsip.');
    }

    public function approve(Request $request, int $id)
    {
        abort_unless($this->canApprove(), 403);
        $data = $request->validate(['approval_note' => 'nullable|string']);
        $this->service->approve(Title::findOrFail($id), Auth::user(), $data['approval_note'] ?? null);

        return redirect()->route('archive.show', $id)->with('success', 'Judul disetujui masuk arsip.');
    }

    public function reject(Request $request, int $id)
    {
        abort_unless($this->canApprove(), 403);
        $data = $request->validate(['reject_note' => 'required|string']);
        $this->service->reject(Title::findOrFail($id), Auth::user(), $data['reject_note']);

        return redirect()->route('archive.show', $id)->with('success', 'Pengajuan arsip ditolak.');
    }
}
```

- [ ] **Step 4: Rute di `routes/web.php`** — import dekat controller Pages lain:

```php
use App\Http\Controllers\Pages\TitleArchiveController;
```

Sisipkan setelah blok rute `doc-req.*`/`title.doc.*` (dalam grup auth):

```php
    // Arsip Judul selesai
    Route::get('management/archive', [TitleArchiveController::class, 'index'])->name('archive.index');
    Route::get('management/archive/{id}', [TitleArchiveController::class, 'show'])->name('archive.show')->whereNumber('id');
    Route::middleware('role:superadmin|manager|admin|production')->group(function () {
        Route::put('management/archive/{id}/artifacts', [TitleArchiveController::class, 'saveArtifacts'])->name('archive.artifacts')->whereNumber('id');
        Route::post('management/archive/{id}/submit', [TitleArchiveController::class, 'submit'])->name('archive.submit')->whereNumber('id');
    });
    Route::middleware('role:superadmin|manager')->group(function () {
        Route::post('management/archive/{id}/approve', [TitleArchiveController::class, 'approve'])->name('archive.approve')->whereNumber('id');
        Route::post('management/archive/{id}/reject', [TitleArchiveController::class, 'reject'])->name('archive.reject')->whereNumber('id');
    });
```

> `index`/`show` tak diberi role-middleware karena controller sudah `abort_unless canManage()`.

- [ ] **Step 5: Buat view minimal `resources/views/archive/index.blade.php`** (agar route bisa dirender; lengkap di Task 4):

```blade
@extends('layouts.master')
@section('title', 'Arsip Judul - SiMAPA')
@section('content')
<h5 class="mb-3">Arsip Judul</h5>
<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <ul class="list-unstyled mb-0">
        @foreach($approved as $t)
            <li><a href="{{ route('archive.show', $t->id) }}">{{ $t->title }}</a></li>
        @endforeach
    </ul>
</div></div></div></div>
@endsection
```

> `archive.show` juga butuh view minimal agar test tak error saat redirect di-follow? Test pakai `assertRedirect` (tak follow), jadi cukup `index`. **Namun** feature test `index_lists_approved` merender index. Buat juga stub `archive/show.blade.php` agar tak fatal bila diakses:

```blade
@extends('layouts.master')
@section('title', 'Detail Arsip - SiMAPA')
@section('content')
<h5>{{ $title->title }}</h5>
@endsection
```

- [ ] **Step 6: Jalankan — PASS**
Run: `php artisan test --env=testing tests/Feature/TitleArchiveTest.php`
Expected: 5 passed.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Pages/TitleArchiveController.php routes/web.php resources/views/archive/index.blade.php resources/views/archive/show.blade.php tests/Feature/TitleArchiveTest.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(archive): TitleArchiveController + rute archive.* + view stub

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 4: View konsolidasi lengkap + sidebar repurpose

**Files:** `resources/views/archive/index.blade.php`, `resources/views/archive/show.blade.php`, `resources/views/layouts/sidebar.blade.php`

- [ ] **Step 1: `resources/views/archive/index.blade.php` (lengkap, DataTables 2 tabel)**

```blade
@extends('layouts.master')
@section('title', 'Arsip Judul - SiMAPA')

@push('plugin-styles')
<link href="{{ asset('assets/libs/datatables.net-bs4/css/dataTables.bootstrap4.min.css') }}" rel="stylesheet" />
@endpush

@section('content')
<h5 class="mb-3">Arsip Judul</h5>

@if($canApprove && $pending->isNotEmpty())
<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card border-warning"><div class="card-body">
    <h6 class="card-title">Menunggu Persetujuan ({{ $pending->count() }})</h6>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr><th>Kode</th><th>Judul</th><th>Jenis</th><th>Diajukan</th><th>Aksi</th></tr></thead>
            <tbody>
                @foreach($pending as $t)
                    <tr>
                        <td>{{ $t->code ?? '—' }}</td>
                        <td>{{ $t->title }}</td>
                        <td>{{ ucfirst($t->jenis) }}</td>
                        <td>{{ $t->archive->submitter?->name ?? '—' }} · {{ optional($t->archive->submitted_at)->format('d M Y') }}</td>
                        <td><a href="{{ route('archive.show', $t->id) }}" class="btn btn-xs btn-warning">Tinjau</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div></div></div></div>
@endif

<div class="row"><div class="col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <h6 class="card-title">Judul Selesai (Arsip)</h6>
    <div class="table-responsive">
        <table class="table table-hover datatable" style="width:100%">
            <thead><tr><th>Kode</th><th>Judul</th><th>Jenis</th><th>Disetujui</th><th>Approver</th><th>Aksi</th></tr></thead>
            <tbody>
                @foreach($approved as $t)
                    <tr>
                        <td>{{ $t->code ?? '—' }}</td>
                        <td>{{ $t->title }}</td>
                        <td>{{ ucfirst($t->jenis) }}</td>
                        <td>{{ optional($t->archive->approved_at)->format('d M Y') ?? '—' }}</td>
                        <td>{{ $t->archive->approver?->name ?? '—' }}</td>
                        <td><a href="{{ route('archive.show', $t->id) }}" class="btn btn-xs btn-outline-primary">Lihat</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div></div></div></div>
@endsection

@push('plugin-scripts')
<script src="{{ asset('assets/libs/datatables.net/js/jquery.dataTables.min.js') }}"></script>
<script src="{{ asset('assets/libs/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>$(function () { $('.datatable').DataTable({ pageLength: 10, order: [], language: { emptyTable: 'Belum ada judul di arsip.' } }); });</script>
@endpush
```

- [ ] **Step 2: `resources/views/archive/show.blade.php` (lengkap, konsolidasi)**

```blade
@extends('layouts.master')
@section('title', 'Detail Arsip - SiMAPA')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0">@if($title->code)<span class="badge bg-dark me-1">{{ $title->code }}</span>@endif {{ $title->title }}</h5>
        <small class="text-muted">{{ ucfirst($title->jenis) }} · {{ ucfirst($title->tipe_naskah) }} · {{ $title->scope?->scope ?? '—' }}
            @php $st = optional($title->archive)->status ?? 'draft'; @endphp
            · <span class="badge {{ $st === 'disetujui' ? 'bg-success' : ($st === 'diajukan' ? 'bg-warning text-dark' : ($st === 'ditolak' ? 'bg-danger' : 'bg-secondary')) }}">{{ \App\Models\TitleArchive::STATUSES[$st] ?? $st }}</span>
        </small>
    </div>
    <a href="{{ route('archive.index') }}" class="btn btn-sm btn-outline-secondary">Kembali</a>
</div>

{{-- Kelayakan + aksi Ajukan --}}
<div class="row"><div class="col-md-9 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <h6 class="card-title">Kelayakan Arsip</h6>
    <span class="badge {{ $isPaidOff ? 'bg-success' : 'bg-secondary' }}">Pembayaran {{ $isPaidOff ? 'Lunas' : 'Belum Lunas' }}</span>
    <span class="badge {{ $isFinal ? 'bg-success' : 'bg-secondary' }}">Manuskrip {{ $isFinal ? 'Final' : 'Belum Final' }}</span>
    @if($canManage && in_array($st, ['draft', 'ditolak'], true))
        <form method="POST" action="{{ route('archive.submit', $title->id) }}" class="d-inline ms-2">@csrf
            <button class="btn btn-sm btn-primary" {{ $eligible ? '' : 'disabled' }}>Ajukan ke Arsip</button>
        </form>
        @unless($eligible)<small class="text-muted d-block mt-1">Bisa diajukan setelah pembayaran lunas dan manuskrip final.</small>@endunless
    @endif
    @if($st === 'ditolak' && optional($title->archive)->reject_note)
        <div class="alert alert-danger py-2 mt-2 mb-0">Ditolak: {{ $title->archive->reject_note }}</div>
    @endif
</div></div></div></div>

{{-- Info Order --}}
<div class="row"><div class="col-md-9 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <h6 class="card-title">Info Order</h6>
    <div class="table-responsive"><table class="table table-sm">
        <thead><tr><th>Kode Order</th><th>Marketing</th><th>Tanggal</th><th>Biaya</th><th>Pembayaran</th></tr></thead>
        <tbody>
        @forelse($title->orderDetails as $od)
            <tr>
                <td>{{ $od->order?->code_order ?? '—' }}</td>
                <td>{{ $od->order?->user?->name ?? '—' }}</td>
                <td>{{ optional($od->order?->ordered_at)->format('d M Y') ?? '—' }}</td>
                <td>Rp {{ number_format((int) $od->cost_amount, 0, ',', '.') }}</td>
                <td>@if($od->order && $od->order->isLunas())<span class="badge bg-success">Lunas</span>@else<span class="badge bg-secondary">Belum</span>@endif</td>
            </tr>
        @empty
            <tr><td colspan="5" class="text-muted">Belum ada order.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div></div></div></div>

{{-- Info Manuskrip --}}
<div class="row"><div class="col-md-9 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <h6 class="card-title">Info Manuskrip</h6>
    <p class="mb-1">Status: <span class="badge {{ $isFinal ? 'bg-success' : 'bg-info' }}">{{ $title->manuscriptStatusLabel() ?? 'Belum ada order' }}</span></p>
    @if($title->jenis === 'buku' && $title->chapters->isNotEmpty())
        <ol class="mb-0 small">@foreach($title->chapters as $ch)<li>{{ $ch->judul }}</li>@endforeach</ol>
    @endif
</div></div></div></div>

{{-- Artefak Penyelesaian --}}
<div class="row"><div class="col-md-9 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <h6 class="card-title">Artefak Penyelesaian</h6>
    @if($canManage)
    <form method="POST" action="{{ route('archive.artifacts', $title->id) }}" enctype="multipart/form-data">
        @csrf @method('PUT')
        @foreach($artifacts as $a)
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
                    <div class="col-md-4">
                        <select name="fixed[{{ $a['key'] }}][pic_user_id]" class="form-select form-select-sm">
                            <option value="">— PIC —</option>
                            @foreach($staff as $u)<option value="{{ $u->id }}" {{ (int) $a['pic_user_id'] === $u->id ? 'selected' : '' }}>{{ $u->name }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-md-3"><input type="text" name="fixed[{{ $a['key'] }}][note]" value="{{ $a['note'] }}" class="form-control form-control-sm" placeholder="Catatan"></div>
                </div>
            </div>
        @endforeach

        <div class="mt-2"><span class="fw-semibold small">Lainnya</span></div>
        <div id="customList">
            @foreach($customArtifacts as $c)
                <div class="row g-2 mb-1" data-custom-row>
                    <div class="col-md-3"><input name="custom[][label]" value="{{ $c->label }}" class="form-control form-control-sm" placeholder="Label"></div>
                    <div class="col-md-2"><select name="custom[][type]" class="form-select form-select-sm"><option value="link" {{ $c->type === 'link' ? 'selected' : '' }}>Link</option><option value="text" {{ $c->type === 'text' ? 'selected' : '' }}>Teks</option></select></div>
                    <div class="col-md-3"><input name="custom[][value]" value="{{ $c->value }}" class="form-control form-control-sm" placeholder="Nilai"></div>
                    <div class="col-md-3"><select name="custom[][pic_user_id]" class="form-select form-select-sm"><option value="">— PIC —</option>@foreach($staff as $u)<option value="{{ $u->id }}" {{ $c->pic_user_id === $u->id ? 'selected' : '' }}>{{ $u->name }}</option>@endforeach</select></div>
                    <div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger" data-remove-custom>×</button></div>
                </div>
            @endforeach
        </div>
        <template id="customTpl">
            <div class="row g-2 mb-1" data-custom-row>
                <div class="col-md-3"><input name="custom[][label]" class="form-control form-control-sm" placeholder="Label"></div>
                <div class="col-md-2"><select name="custom[][type]" class="form-select form-select-sm"><option value="link">Link</option><option value="text">Teks</option></select></div>
                <div class="col-md-3"><input name="custom[][value]" class="form-control form-control-sm" placeholder="Nilai"></div>
                <div class="col-md-3"><select name="custom[][pic_user_id]" class="form-select form-select-sm"><option value="">— PIC —</option>@foreach($staff as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select></div>
                <div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger" data-remove-custom>×</button></div>
            </div>
        </template>
        <button type="button" class="btn btn-xs btn-outline-secondary" id="addCustom">+ Lainnya</button>
        <div class="mt-2"><button type="submit" class="btn btn-sm btn-primary">Simpan Artefak</button></div>
    </form>
    @else
        <dl class="row mb-0">
            @foreach($artifacts as $a)
                <dt class="col-sm-4 small text-muted">{{ $a['label'] }}</dt>
                <dd class="col-sm-8">@if($a['type'] === 'file' && $a['value'])<a href="{{ $a['value'] }}" target="_blank" rel="noopener">📎 {{ $a['file_name'] ?: 'file' }}</a>@elseif($a['value'])@if($a['type'] === 'link')<a href="{{ $a['value'] }}" target="_blank" rel="noopener">{{ $a['value'] }}</a>@else{{ $a['value'] }}@endif @else — @endif</dd>
            @endforeach
        </dl>
    @endif
</div></div></div></div>

{{-- Persetujuan --}}
@if($canApprove && $st === 'diajukan')
<div class="row"><div class="col-md-9 col-12 grid-margin stretch-card"><div class="card border-primary"><div class="card-body">
    <h6 class="card-title">Persetujuan Arsip</h6>
    <form method="POST" action="{{ route('archive.approve', $title->id) }}" class="mb-2">@csrf
        <textarea name="approval_note" class="form-control form-control-sm mb-2" rows="2" placeholder="Informasi/bukti selesai (opsional)"></textarea>
        <button class="btn btn-sm btn-success">Approve — Masuk Arsip</button>
    </form>
    <form method="POST" action="{{ route('archive.reject', $title->id) }}">@csrf
        <textarea name="reject_note" class="form-control form-control-sm mb-2" rows="2" placeholder="Alasan penolakan" required></textarea>
        <button class="btn btn-sm btn-outline-danger">Tolak</button>
    </form>
</div></div></div></div>
@elseif($st === 'disetujui')
<div class="row"><div class="col-md-9 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <h6 class="card-title">Disetujui</h6>
    <p class="mb-0 small text-muted">Oleh {{ optional($title->archive->approver)->name ?? '—' }} · {{ optional($title->archive->approved_at)->format('d M Y H:i') }}
        @if($title->archive->approval_note)<br>Catatan: {{ $title->archive->approval_note }}@endif
    </p>
</div></div></div></div>
@endif
@endsection

@push('custom-scripts')
<script>
$(function () {
    var list = document.getElementById('customList');
    var tpl = document.getElementById('customTpl');
    var add = document.getElementById('addCustom');
    if (add && tpl && list) add.addEventListener('click', function () {
        var node = tpl.content.cloneNode(true);
        list.appendChild(node);
    });
    if (list) list.addEventListener('click', function (e) {
        var b = e.target.closest('[data-remove-custom]');
        if (b) b.closest('[data-custom-row]').remove();
    });
});
</script>
@endpush
```

- [ ] **Step 3: Repurpose menu di `resources/views/layouts/sidebar.blade.php`** — ubah link "Arsip Judul" (baris ~78) dari `route('order.book.indexJudul')` menjadi `route('archive.index')` dan `active_class(['management/archive', 'management/archive/*'])`:

```blade
                <li class="nav-item {{ active_class(['management/archive', 'management/archive/*']) }}">
                    <a href="{{ route('archive.index') }}" class="nav-link">
                        <i class="link-icon" data-feather="archive"></i>
                        <span class="link-title">Arsip Judul</span>
                    </a>
                </li>
```

- [ ] **Step 4: Jalankan test + view:cache**
Run: `php artisan test --env=testing tests/Feature/TitleArchiveTest.php`
Expected: 5 passed.
Run: `php artisan view:cache && php artisan view:clear`
Expected: sukses tanpa error.

- [ ] **Step 5: Commit**

```bash
git add resources/views/archive/index.blade.php resources/views/archive/show.blade.php resources/views/layouts/sidebar.blade.php
git commit --author="WellkitDev <rahmatpurnomo808@gmail.com>" -m "$(cat <<'EOF'
feat(archive): view Arsip (index + detail konsolidasi) + repurpose menu sidebar

Co-authored-by: Mira <admin@avidpedia.com>
EOF
)"
```

---

### Task 5: Migrasi dev + verifikasi menyeluruh

- [ ] **Step 1: Migrasi DB dev**
Run: `php artisan migrate`
Expected: `2026_07_03_000007…` & `…000008…` `DONE`.

- [ ] **Step 2: Seluruh suite**
Run: `php artisan test --env=testing`
Expected: semua PASS (baseline 413 + 10 test baru = 423 passed).

- [ ] **Step 3: Kompilasi view bersih**
Run: `php artisan view:cache && php artisan view:clear`
Expected: sukses tanpa error.

---

## Self-Review (penulis plan)

**1. Spec coverage:**
- §1/§2 model+artefak → Task 1 (migrasi/model/konstanta). ✓
- §3 kelayakan (lunas+final) → Task 1 (Order::isLunas, archiveEligible) + unit test. ✓
- §4 service (defaultArtifacts/saveArtifacts/submit/approve/reject) + Notifier → Task 2 + unit test. ✓
- §5 controller+rute → Task 3 + feature test (save, submit reject/ok, approve, 403, index). ✓
- §6 view (index + show konsolidasi + artefak form + PIC + approve) → Task 4. ✓ Sidebar repurpose → Task 4. ✓
- §7 test → Task 1/2/3/4. ✓
- Roles (view/manage 4-role; approve superadmin/manager) → rute Task 3 + abort_unless. ✓

**2. Placeholder scan:** tak ada TBD/TODO; kode nyata di tiap step. (View stub Task 3 sengaja minimal, disempurnakan Task 4 — langkah TDD, bukan placeholder.)

**3. Type/nama konsistensi:** tabel `tb_title_archives`/`tb_title_archive_artifacts`; model `TitleArchive`(STATUSES, BOOK/ARTICLE_ARTIFACTS, artifactsFor)/`TitleArchiveArtifact`. Service `TitleArchivalService::defaultArtifacts/saveArtifacts/submit/approve/reject` konsisten controller↔test. Rute `archive.index/show/artifacts/submit/approve/reject` konsisten controller↔view↔test↔sidebar. Helper `Order::isLunas`, `Title::isPaidOff/manuscriptIsFinal/archiveEligible/archive/archiveArtifacts` konsisten. `Notifier::titleArchiveSubmitted` dipakai service. Variabel view (`$artifacts,$customArtifacts,$eligible,$isPaidOff,$isFinal,$canManage,$canApprove,$staff,$approved,$pending`) dikirim controller↔dipakai blade.

Migrasi baru → **wajib `php artisan migrate` dev** (Task 5). Test via `.env.testing`, Drive di-mock.
