# Judul: Kode Unik + Panel Informasi Publikasi — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Kode unik otomatis per judul (search/tracking, tampil di direktori + select2 order) + panel Informasi Publikasi profile-detail di halaman detail judul, dengan log perubahan & notifikasi lonceng ke superadmin.

**Architecture:** Kolom `code` + field publikasi di `tb_titles`; tabel `tb_title_journal_options` (repeater) & `tb_title_logs`. `TitleCodeService` (generator akronim + keunikan). `TitleService::updateInfo` (update + log + notif). Panel inline di `titles/show`. View: superadmin/manager/admin/production; edit: superadmin/manager/admin.

**Tech Stack:** Laravel 11, Eloquent, Spatie roles, database notifications (tabel `notifications`, `App\Notifications\DatabaseNotification` payload = category/title/message/url/icon), Blade + flatpickr/select2 (bundled).

**Spec:** `docs/superpowers/specs/2026-07-02-title-code-publication-info-design.md`

**Catatan env:** Tests `.env.testing` + `RefreshDatabase`; mock `GoogleDriveService`. DB mati → `Start-Process -FilePath "C:\xampp\mysql\bin\mysqld.exe" -ArgumentList '--defaults-file=C:\xampp\mysql\bin\my.ini' -WindowStyle Hidden` (PowerShell), tunggu ~6 dtk. Migrasi terakhir: `2026_07_02_000005`. Setelah selesai: `php artisan migrate` di dev. Commit: `git add <path eksplisit>` + `Co-authored-by: Mira <admin@avidpedia.com>`, TANPA "Claude"/"Anthropic", jangan `git add .`.

---

## Task 1: Migrations (schema) + models

**Files:**
- Create: `database/migrations/2026_07_02_000006_add_code_and_publication_to_tb_titles.php`, `..._000007_create_tb_title_journal_options_table.php`, `..._000008_create_tb_title_logs_table.php`
- Create: `app/Models/TitleJournalOption.php`, `app/Models/TitleLog.php`
- Modify: `app/Models/Title.php`

- [ ] **Step 1: Migration — tb_titles columns**

Create `database/migrations/2026_07_02_000006_add_code_and_publication_to_tb_titles.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_titles', function (Blueprint $table) {
            $table->string('code', 16)->nullable()->unique()->after('title');
            $table->date('target_terbit')->nullable()->after('reject_note');
            $table->string('jurnal_target')->nullable()->after('target_terbit');
            $table->string('jurnal_link')->nullable()->after('jurnal_target');
            $table->string('template_link')->nullable()->after('jurnal_link');
            $table->string('apc_info')->nullable()->after('template_link');
            $table->text('catatan_publikasi')->nullable()->after('apc_info');
        });
    }

    public function down(): void
    {
        Schema::table('tb_titles', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'target_terbit', 'jurnal_target', 'jurnal_link', 'template_link', 'apc_info', 'catatan_publikasi']);
        });
    }
};
```

- [ ] **Step 2: Migration — tb_title_journal_options**

Create `database/migrations/2026_07_02_000007_create_tb_title_journal_options_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_title_journal_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('title_id')->constrained('tb_titles')->cascadeOnDelete();
            $table->string('nama_jurnal');
            $table->string('link')->nullable();
            $table->string('apc')->nullable();
            $table->unsignedInteger('urutan')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_title_journal_options');
    }
};
```

- [ ] **Step 3: Migration — tb_title_logs**

Create `database/migrations/2026_07_02_000008_create_tb_title_logs_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_title_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('title_id')->constrained('tb_titles')->cascadeOnDelete();
            $table->string('event', 32);
            $table->text('note')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_title_logs');
    }
};
```

- [ ] **Step 4: Model TitleJournalOption**

Create `app/Models/TitleJournalOption.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TitleJournalOption extends Model
{
    use HasFactory;

    protected $table = 'tb_title_journal_options';

    protected $fillable = ['title_id', 'nama_jurnal', 'link', 'apc', 'urutan'];

    public function title()
    {
        return $this->belongsTo(Title::class);
    }
}
```

- [ ] **Step 5: Model TitleLog**

Create `app/Models/TitleLog.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TitleLog extends Model
{
    use HasFactory;

    protected $table = 'tb_title_logs';

    public $timestamps = false;

    protected $fillable = ['title_id', 'event', 'note', 'changed_by', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function title()
    {
        return $this->belongsTo(Title::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
```

- [ ] **Step 6: Title — fillable + relations**

In `app/Models/Title.php`, extend `$fillable` to add the new columns:

```php
    protected $fillable = [
        'title', 'code', 'jenis', 'indeksasi', 'tipe_naskah', 'scope_id', 'assigned_to', 'status', 'asal', 'slug',
        'created_by', 'approved_by', 'approved_at', 'reject_note',
        'target_terbit', 'jurnal_target', 'jurnal_link', 'template_link', 'apc_info', 'catatan_publikasi',
    ];
```

Change `$casts` to include the date:

```php
    protected $casts = ['approved_at' => 'datetime', 'target_terbit' => 'date'];
```

Add these relations (after `orderDetails()`):

```php
    public function journalOptions()
    {
        return $this->hasMany(TitleJournalOption::class)->orderBy('urutan');
    }

    public function logs()
    {
        return $this->hasMany(TitleLog::class)->latest('created_at');
    }
```

- [ ] **Step 7: Verify migrations healthy**

Run: `php artisan test --filter=TitleServiceTest`
Expected: PASS (RefreshDatabase applies the 3 migrations cleanly).

- [ ] **Step 8: Commit**

```
git add database/migrations/2026_07_02_000006_add_code_and_publication_to_tb_titles.php database/migrations/2026_07_02_000007_create_tb_title_journal_options_table.php database/migrations/2026_07_02_000008_create_tb_title_logs_table.php app/Models/TitleJournalOption.php app/Models/TitleLog.php app/Models/Title.php
git commit -m "feat(title-info): code + publication columns, journal options + logs tables/models

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 2: `TitleCodeService` (TDD)

**Files:**
- Create: `app/Services/TitleCodeService.php`
- Test: `tests/Unit/TitleCodeServiceTest.php`

- [ ] **Step 1: Write failing test** — create `tests/Unit/TitleCodeServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Title;
use App\Services\TitleCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TitleCodeServiceTest extends TestCase
{
    use RefreshDatabase;

    private TitleCodeService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new TitleCodeService();
    }

    /** @test */
    public function it_builds_code_from_first_four_words(): void
    {
        $code = $this->svc->generate('Blockchain dalam Fintech Syariah: Transparansi Akad untuk UMKM Halal');
        $this->assertSame('BDFS', $code);
    }

    /** @test */
    public function single_word_title_falls_back_to_letters(): void
    {
        $this->assertSame('FINT', $this->svc->generate('Fintech'));
    }

    /** @test */
    public function symbol_only_title_uses_fallback(): void
    {
        $this->assertSame('JDL', $this->svc->generate('—  :  —'));
    }

    /** @test */
    public function collision_appends_number(): void
    {
        Title::create(['title' => 'X', 'code' => 'BDFS', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $this->assertSame('BDFS2', $this->svc->generate('Blockchain dalam Fintech Syariah'));
    }

    /** @test */
    public function ignore_id_excludes_self_when_regenerating(): void
    {
        $t = Title::create(['title' => 'Blockchain dalam Fintech Syariah', 'code' => 'BDFS', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $this->assertSame('BDFS', $this->svc->generate('Blockchain dalam Fintech Syariah', $t->id));
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=TitleCodeServiceTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement** — create `app/Services/TitleCodeService.php`:

```php
<?php

namespace App\Services;

use App\Models\Title;

class TitleCodeService
{
    private const FALLBACK = 'JDL';

    /** Kode dari inisial 4 kata pertama judul; jamin unik (sufiks angka). */
    public function generate(string $title, ?int $ignoreId = null): string
    {
        $base = $this->baseFrom($title);
        $code = $base;
        $i = 2;
        while ($this->taken($code, $ignoreId)) {
            $code = $base . $i;
            $i++;
        }

        return $code;
    }

    private function baseFrom(string $title): string
    {
        // Buang tanda baca/simbol → sisakan huruf/angka + spasi.
        $clean = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $title) ?? '';
        $words = preg_split('/\s+/u', trim($clean), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) >= 2) {
            $letters = '';
            foreach (array_slice($words, 0, 4) as $w) {
                $letters .= mb_strtoupper(mb_substr($w, 0, 1));
            }

            return $letters !== '' ? $letters : self::FALLBACK;
        }

        $single = $words[0] ?? '';
        if ($single === '') {
            return self::FALLBACK;
        }

        return mb_strtoupper(mb_substr($single, 0, 4));
    }

    private function taken(string $code, ?int $ignoreId): bool
    {
        return Title::where('code', $code)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }
}
```

- [ ] **Step 4: Run, confirm PASS**

Run: `php artisan test --filter=TitleCodeServiceTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```
git add app/Services/TitleCodeService.php tests/Unit/TitleCodeServiceTest.php
git commit -m "feat(title-info): TitleCodeService (initials + uniqueness)

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 3: Wire code generation into creation + backfill

**Files:**
- Modify: `app/Services/TitleService.php` (create + resolveForOrder set code)
- Create: `database/migrations/2026_07_02_000009_backfill_title_codes.php`
- Test: `tests/Unit/TitleServiceTest.php` (add code assertions)

- [ ] **Step 1: Add failing tests** — append inside `tests/Unit/TitleServiceTest.php` (before final `}`):

```php
    /** @test */
    public function create_assigns_a_code(): void
    {
        $prod = $this->user('production');
        $title = $this->svc->create(['title' => 'Blockchain dalam Fintech Syariah', 'jenis' => 'buku', 'tipe_naskah' => 'mandiri'], [], $prod);
        $this->assertSame('BDFS', $title->fresh()->code);
    }

    /** @test */
    public function resolve_for_order_assigns_a_code(): void
    {
        $mkt = $this->user('marketing');
        $title = $this->svc->resolveForOrder('Analisis Data Besar Nasional', ['jenis' => 'artikel', 'order_type' => 'at_mandiri'], $mkt);
        $this->assertSame('ADBN', $title->code);
    }
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter="TitleServiceTest::create_assigns_a_code|TitleServiceTest::resolve_for_order_assigns_a_code"`
Expected: FAIL (code null).

- [ ] **Step 3: TitleService::create — set code**

In `app/Services/TitleService.php`, `create()` currently does `$title->update(['slug' => Str::slug($title->title) . '-' . $title->id]);`. Change it to also set the code:

```php
        $title->update([
            'slug' => Str::slug($title->title) . '-' . $title->id,
            'code' => app(TitleCodeService::class)->generate($title->title, $title->id),
        ]);
```

- [ ] **Step 4: TitleService::resolveForOrder — set code**

In `resolveForOrder()`, the `Title::create([...])` for a new title: add `'code'` to the array (computed before insert; no id needed since it's a fresh title):

```php
        return Title::create([
            'title'       => (string) $value,
            'code'        => app(TitleCodeService::class)->generate((string) $value),
            'jenis'       => $ctx['jenis'],
            'tipe_naskah' => str_contains($ctx['order_type'] ?? '', 'kolab') ? 'kolaborasi' : 'mandiri',
            'scope_id'    => $ctx['scope_id'] ?? null,
            'indeksasi'   => $ctx['indeksasi'] ?? null,
            'status'      => 'disetujui',
            'asal'        => 'order',
            'created_by'  => $actor->id,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ]);
```

(Add `use App\Services\TitleCodeService;`? No — same namespace `App\Services`, so reference as `TitleCodeService` directly; it's already in `App\Services`. Use unqualified `TitleCodeService`.)

- [ ] **Step 5: Backfill migration**

Create `database/migrations/2026_07_02_000009_backfill_title_codes.php`:

```php
<?php

use App\Models\Title;
use App\Services\TitleCodeService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $svc = new TitleCodeService();
        Title::whereNull('code')->orderBy('id')->get()->each(function (Title $t) use ($svc) {
            $t->update(['code' => $svc->generate($t->title, $t->id)]);
        });
    }

    public function down(): void
    {
        // no-op: kode dibiarkan saat rollback
    }
};
```

- [ ] **Step 6: Run, confirm PASS**

Run: `php artisan test --filter=TitleServiceTest`
Expected: PASS all.

- [ ] **Step 7: Commit**

```
git add app/Services/TitleService.php database/migrations/2026_07_02_000009_backfill_title_codes.php tests/Unit/TitleServiceTest.php
git commit -m "feat(title-info): assign code on create + resolveForOrder + backfill

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 4: `TitleService::updateInfo` + `Notifier::titleInfoUpdated` (TDD)

**Files:**
- Modify: `app/Services/TitleService.php` (updateInfo), `app/Services/Notifier.php` (titleInfoUpdated)
- Test: `tests/Unit/TitleServiceTest.php` (updateInfo unit)

- [ ] **Step 1: Add failing test** — append inside `tests/Unit/TitleServiceTest.php`:

```php
    /** @test */
    public function update_info_saves_fields_options_and_writes_log(): void
    {
        $mgr = $this->user('manager');
        $title = $this->svc->create(['title' => 'Judul Publikasi', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri'], [], $mgr);

        $this->svc->updateInfo($title, [
            'code' => '', // kosong → regen
            'target_terbit' => '2026-09-01',
            'jurnal_target' => 'Jurnal A',
            'jurnal_link' => 'https://a.test',
            'template_link' => 'https://a.test/tpl',
            'apc_info' => 'Rp 3.000.000',
            'catatan_publikasi' => 'catatan',
        ], [
            ['nama_jurnal' => 'Jurnal Alt', 'link' => 'https://alt.test', 'apc' => 'gratis'],
            ['nama_jurnal' => ''], // diabaikan
        ], $mgr);

        $title->refresh();
        $this->assertSame('Jurnal A', $title->jurnal_target);
        $this->assertSame('2026-09-01', $title->target_terbit->toDateString());
        $this->assertNotEmpty($title->code); // regen
        $this->assertSame(1, $title->journalOptions()->count());
        $this->assertSame('Jurnal Alt', $title->journalOptions()->first()->nama_jurnal);
        $this->assertSame(1, $title->logs()->count());
        $this->assertSame('info_updated', $title->logs()->first()->event);
    }
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter="TitleServiceTest::update_info_saves_fields_options_and_writes_log"`
Expected: FAIL — method missing.

- [ ] **Step 3: Implement `updateInfo`** — add to `app/Services/TitleService.php` (after `resolveForOrder`). Add `use App\Models\TitleLog;` and `use Illuminate\Support\Facades\DB;` at the top if not present.

```php
    /**
     * Perbarui informasi publikasi judul + opsi jurnal, tulis log ringkasan perubahan,
     * dan beri tahu superadmin. $data: code?, target_terbit?, jurnal_target?, jurnal_link?,
     * template_link?, apc_info?, catatan_publikasi?. $journalOptions: array of [nama_jurnal, link?, apc?].
     */
    public function updateInfo(Title $title, array $data, array $journalOptions, User $actor): void
    {
        // Kode: kosong → regenerasi dari judul.
        $newCode = trim((string) ($data['code'] ?? ''));
        $code = $newCode !== '' ? $newCode : app(TitleCodeService::class)->generate($title->title, $title->id);

        $labels = [
            'code' => 'Kode', 'target_terbit' => 'Target terbit', 'jurnal_target' => 'Jurnal target',
            'jurnal_link' => 'Link jurnal', 'template_link' => 'Template', 'apc_info' => 'APC',
            'catatan_publikasi' => 'Catatan',
        ];
        $next = [
            'code'              => $code,
            'target_terbit'     => $data['target_terbit'] ?: null,
            'jurnal_target'     => $data['jurnal_target'] ?? null,
            'jurnal_link'       => $data['jurnal_link'] ?? null,
            'template_link'     => $data['template_link'] ?? null,
            'apc_info'          => $data['apc_info'] ?? null,
            'catatan_publikasi' => $data['catatan_publikasi'] ?? null,
        ];

        $changed = [];
        foreach ($labels as $field => $label) {
            $old = $field === 'target_terbit'
                ? (string) (optional($title->target_terbit)->toDateString() ?? '')
                : (string) ($title->$field ?? '');
            $new = (string) ($next[$field] ?? '');
            if ($old !== $new) {
                $changed[] = $label;
            }
        }

        // Snapshot opsi jurnal sebelum, untuk deteksi perubahan.
        $before = $title->journalOptions()->orderBy('urutan')->get()
            ->map(fn ($o) => $o->nama_jurnal . '|' . $o->link . '|' . $o->apc)->implode(';;');

        DB::transaction(function () use ($title, $next, $journalOptions) {
            $title->update($next);

            $title->journalOptions()->delete();
            $i = 0;
            foreach ($journalOptions as $opt) {
                $nama = trim((string) ($opt['nama_jurnal'] ?? ''));
                if ($nama === '') {
                    continue;
                }
                $title->journalOptions()->create([
                    'nama_jurnal' => $nama,
                    'link'        => $opt['link'] ?? null,
                    'apc'         => $opt['apc'] ?? null,
                    'urutan'      => $i++,
                ]);
            }
        });

        $after = $title->journalOptions()->orderBy('urutan')->get()
            ->map(fn ($o) => $o->nama_jurnal . '|' . $o->link . '|' . $o->apc)->implode(';;');
        if ($before !== $after) {
            $changed[] = 'Opsi jurnal';
        }

        $note = $changed ? implode(', ', array_unique($changed)) . ' diperbarui' : 'Info publikasi disimpan';

        TitleLog::create([
            'title_id'   => $title->id,
            'event'      => 'info_updated',
            'note'       => $note,
            'changed_by' => $actor->id,
            'created_at' => now(),
        ]);

        app(Notifier::class)->titleInfoUpdated($title->fresh(), $actor);
    }
```

> Note: change detection is only for the log summary note (cosmetic); `target_terbit` old value is a Carbon (date cast) normalized to a `Y-m-d` string before comparison.

- [ ] **Step 4: Notifier::titleInfoUpdated**

In `app/Services/Notifier.php`, add `use App\Models\Title;` near the other model imports, and this method (after `naskahNeedsReview`):

```php
    public function titleInfoUpdated(Title $title, User $actor): void
    {
        $this->send($this->roleUsers(['superadmin'], $actor), [
            'category' => 'title',
            'title'    => 'Info publikasi judul diperbarui',
            'message'  => trim(($title->code ? $title->code . ' — ' : '') . $title->title),
            'url'      => route('title.show', $title->id),
            'icon'     => 'edit',
        ]);
    }
```

- [ ] **Step 5: Run, confirm PASS**

Run: `php artisan test --filter=TitleServiceTest`
Expected: PASS all.

- [ ] **Step 6: Commit**

```
git add app/Services/TitleService.php app/Services/Notifier.php tests/Unit/TitleServiceTest.php
git commit -m "feat(title-info): TitleService::updateInfo + log + superadmin notify

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 5: `TitleController@updateInfo` + route + show panel data (TDD)

**Files:**
- Modify: `app/Http/Controllers/Pages/TitleController.php` (updateInfo + show), `routes/web.php`
- Test: `tests/Feature/TitlePublicationInfoTest.php`

- [ ] **Step 1: Write failing test** — create `tests/Feature/TitlePublicationInfoTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class TitlePublicationInfoTest extends TestCase
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

    private function title(): Title
    {
        return Title::create(['title' => 'Judul Publikasi', 'code' => 'JDPB', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
    }

    /** @test */
    public function manager_updates_info_logs_and_notifies_superadmin(): void
    {
        $sa = $this->user('superadmin');
        $mgr = $this->user('manager');
        $title = $this->title();

        $this->actingAs($mgr)->put(route('title.info.update', $title->id), [
            'code' => 'JDPB',
            'target_terbit' => '2026-10-01',
            'jurnal_target' => 'Jurnal Riset',
            'template_link' => 'https://j.test/tpl',
            'journal_options' => [['nama_jurnal' => 'Alt J', 'link' => 'https://alt.test', 'apc' => 'gratis']],
        ])->assertRedirect();

        $title->refresh();
        $this->assertSame('Jurnal Riset', $title->jurnal_target);
        $this->assertSame(1, $title->journalOptions()->count());
        $this->assertSame(1, $title->logs()->count());
        $this->assertSame(1, $sa->notifications()->count());
    }

    /** @test */
    public function production_can_view_panel_but_cannot_update(): void
    {
        $prod = $this->user('production');
        $title = $this->title();

        $this->actingAs($prod)->get(route('title.show', $title->id))->assertOk()->assertSee('Informasi Publikasi');
        $this->actingAs($prod)->put(route('title.info.update', $title->id), ['jurnal_target' => 'X'])->assertForbidden();
    }

    /** @test */
    public function marketing_does_not_see_panel(): void
    {
        $title = $this->title();
        $this->actingAs($this->user('marketing'))->get(route('title.show', $title->id))
            ->assertOk()->assertDontSee('Informasi Publikasi');
    }

    /** @test */
    public function duplicate_code_is_rejected(): void
    {
        Title::create(['title' => 'Lain', 'code' => 'DUP', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        $title = $this->title();

        $this->actingAs($this->user('superadmin'))
            ->put(route('title.info.update', $title->id), ['code' => 'DUP'])
            ->assertSessionHasErrors('code');
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=TitlePublicationInfoTest`
Expected: FAIL — route `title.info.update` undefined.

- [ ] **Step 3: Controller — updateInfo + show data**

In `app/Http/Controllers/Pages/TitleController.php`, add `use Illuminate\Validation\Rule;` at the top. Add this method (e.g. after `reject()` or near `update`):

```php
    public function updateInfo(Request $request, int $id)
    {
        abort_unless(Auth::user()->hasAnyRole(['superadmin', 'manager', 'admin']), 403);
        $title = Title::findOrFail($id);

        $data = $request->validate([
            'code'                       => ['nullable', 'string', 'max:16', Rule::unique('tb_titles', 'code')->ignore($title->id)],
            'target_terbit'              => 'nullable|date',
            'jurnal_target'              => 'nullable|string|max:255',
            'jurnal_link'                => 'nullable|string|max:255',
            'template_link'              => 'nullable|string|max:255',
            'apc_info'                   => 'nullable|string|max:255',
            'catatan_publikasi'          => 'nullable|string',
            'journal_options'            => 'nullable|array',
            'journal_options.*.nama_jurnal' => 'nullable|string|max:255',
            'journal_options.*.link'     => 'nullable|string|max:255',
            'journal_options.*.apc'      => 'nullable|string|max:255',
        ]);

        $this->service->updateInfo($title, $data, $request->input('journal_options', []), Auth::user());

        return redirect()->route('title.show', $title->id)->with('success', 'Informasi publikasi diperbarui.');
    }
```

In `show()`, change the eager-load to also load publication relations, compute the panel flags, and pass them. Replace the current `show()` body's load + view call with:

```php
        $title = Title::with(['chapters', 'creator', 'approver', 'scope', 'assignedMarketing', 'orderDetails.order.user', 'journalOptions', 'logs.changedBy'])->findOrFail($id);
        abort_if(! $this->canManage() && ! $title->isApproved(), 403);
        abort_if(! $this->canManage() && $title->assigned_to && $title->assigned_to !== Auth::id(), 403);

        $ordersCount = $title->orderDetails->count();
        $authorsCount = \App\Models\OrderDetail::where('title_id', $title->id)
            ->withCount('authors')->get()->sum('authors_count');

        return view('titles.show', [
            'title' => $title,
            'canManage' => $this->canManage(),
            'isApprover' => $this->isApprover(),
            'ordersCount' => $ordersCount,
            'authorsCount' => $authorsCount,
            'canViewInfo' => Auth::user()->hasAnyRole(['superadmin', 'manager', 'admin', 'production']),
            'canEditInfo' => Auth::user()->hasAnyRole(['superadmin', 'manager', 'admin']),
        ]);
```

- [ ] **Step 4: Route**

In `routes/web.php`, add inside the authenticated group a role-gated route for info update. Place it near the other `titles/...` routes, in a NEW middleware group (or add to an existing `role:superadmin|manager|admin` group if present):

```php
    Route::middleware('role:superadmin|manager|admin')->group(function () {
        Route::put('titles/{id}/info', [TitleController::class, 'updateInfo'])->name('title.info.update')->whereNumber('id');
    });
```

(Ensure this is registered BEFORE or independently of the `titles/{id}` show route; `whereNumber` keeps it unambiguous.)

- [ ] **Step 5: Run — expect PASS except the two `assertSee('Informasi Publikasi')` (view not built yet)**

Run: `php artisan test --filter=TitlePublicationInfoTest`
Expected: `manager_updates_...` and `duplicate_code...` PASS; the two panel-visibility tests may FAIL until Task 6 adds the panel markup. That's expected — proceed to Task 6, then re-run.

- [ ] **Step 6: Commit**

```
git add app/Http/Controllers/Pages/TitleController.php routes/web.php tests/Feature/TitlePublicationInfoTest.php
git commit -m "feat(title-info): updateInfo endpoint + show panel data + route

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 6: Views — panel, code column, order select2 labels

**Files:**
- Modify: `resources/views/titles/show.blade.php`, `resources/views/titles/index.blade.php`
- Modify: `resources/views/orders/book/create.blade.php`, `resources/views/orders/edit.blade.php`, `resources/views/orders/journal/create.blade.php`, `resources/views/orders/journal/edit.blade.php`

- [ ] **Step 1: index — Kode column**

In `resources/views/titles/index.blade.php`, add a header cell at the START of the `<thead>` row (before `<th>Judul</th>`):
```blade
<th>Kode</th>
```
and a body cell at the start of each row (before `<td>{{ $t->title }}</td>`):
```blade
                        <td><span class="badge bg-dark">{{ $t->code ?? '—' }}</span></td>
```

- [ ] **Step 2: show — code badge in header**

In `resources/views/titles/show.blade.php`, change the header `<h5 ...>{{ $title->title }}</h5>` to prefix the code:
```blade
        <h5 class="mb-0">@if($title->code)<span class="badge bg-dark align-middle me-1">{{ $title->code }}</span>@endif {{ $title->title }}</h5>
```

- [ ] **Step 3: show — publication panel (second card)**

In `resources/views/titles/show.blade.php`, immediately AFTER the closing `</div></div></div></div>` of the existing single card (the line before `@endsection`), insert the panel. It renders only when `$canViewInfo`:

```blade
@if($canViewInfo)
<div class="row"><div class="col-md-8 col-12 grid-margin stretch-card"><div class="card"><div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="card-title mb-0">Informasi Publikasi</h6>
        @if($canEditInfo)
            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#infoForm">Edit Informasi</button>
        @endif
    </div>

    <dl class="row mb-2">
        <dt class="col-sm-4 text-muted small">Kode</dt><dd class="col-sm-8">{{ $title->code ?? '—' }}</dd>
        <dt class="col-sm-4 text-muted small">Target Terbit</dt><dd class="col-sm-8">{{ optional($title->target_terbit)->format('d M Y') ?? '—' }}</dd>
        <dt class="col-sm-4 text-muted small">Jurnal Target</dt><dd class="col-sm-8">{{ $title->jurnal_target ?? '—' }}@if($title->jurnal_link) · <a href="{{ $title->jurnal_link }}" target="_blank" rel="noopener">link</a>@endif</dd>
        <dt class="col-sm-4 text-muted small">Template Artikel</dt><dd class="col-sm-8">@if($title->template_link)<a href="{{ $title->template_link }}" target="_blank" rel="noopener">{{ $title->template_link }}</a>@else — @endif</dd>
        <dt class="col-sm-4 text-muted small">APC</dt><dd class="col-sm-8">{{ $title->apc_info ?? '—' }}</dd>
        <dt class="col-sm-4 text-muted small">Catatan</dt><dd class="col-sm-8">{{ $title->catatan_publikasi ?? '—' }}</dd>
    </dl>

    <h6 class="text-muted small mt-2">Opsi Jurnal Lain</h6>
    @forelse($title->journalOptions as $opt)
        <div class="small mb-1">• {{ $opt->nama_jurnal }}@if($opt->link) · <a href="{{ $opt->link }}" target="_blank" rel="noopener">link</a>@endif @if($opt->apc)· APC: {{ $opt->apc }}@endif</div>
    @empty
        <div class="small text-muted mb-1">Belum ada opsi jurnal.</div>
    @endforelse

    @if($canEditInfo)
    <div class="collapse mt-3" id="infoForm">
        <form method="POST" action="{{ route('title.info.update', $title->id) }}">
            @csrf @method('PUT')
            <div class="row">
                <div class="col-md-4 mb-2">
                    <label class="form-label">Kode</label>
                    <input type="text" name="code" class="form-control @error('code') is-invalid @enderror" value="{{ old('code', $title->code) }}" maxlength="16">
                    @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <small class="text-muted">Kosongkan untuk buat ulang dari judul.</small>
                </div>
                <div class="col-md-4 mb-2">
                    <label class="form-label">Target Terbit</label>
                    <input type="text" name="target_terbit" class="form-control flatpickr-date" value="{{ old('target_terbit', optional($title->target_terbit)->format('Y-m-d')) }}" placeholder="YYYY-MM-DD">
                </div>
                <div class="col-md-4 mb-2">
                    <label class="form-label">APC</label>
                    <input type="text" name="apc_info" class="form-control" value="{{ old('apc_info', $title->apc_info) }}">
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-2">
                    <label class="form-label">Jurnal Target</label>
                    <input type="text" name="jurnal_target" class="form-control" value="{{ old('jurnal_target', $title->jurnal_target) }}">
                </div>
                <div class="col-md-6 mb-2">
                    <label class="form-label">Link Jurnal</label>
                    <input type="text" name="jurnal_link" class="form-control" value="{{ old('jurnal_link', $title->jurnal_link) }}">
                </div>
            </div>
            <div class="mb-2">
                <label class="form-label">Link Template Artikel</label>
                <input type="text" name="template_link" class="form-control" value="{{ old('template_link', $title->template_link) }}">
            </div>
            <div class="mb-2">
                <label class="form-label">Catatan</label>
                <textarea name="catatan_publikasi" class="form-control" rows="2">{{ old('catatan_publikasi', $title->catatan_publikasi) }}</textarea>
            </div>

            <label class="form-label">Opsi Jurnal Lain</label>
            <div id="joList">
                @foreach($title->journalOptions as $i => $opt)
                    <div class="row g-1 mb-1" data-jo-row>
                        <div class="col-md-5"><input type="text" name="journal_options[{{ $i }}][nama_jurnal]" class="form-control form-control-sm" value="{{ $opt->nama_jurnal }}" placeholder="Nama jurnal"></div>
                        <div class="col-md-4"><input type="text" name="journal_options[{{ $i }}][link]" class="form-control form-control-sm" value="{{ $opt->link }}" placeholder="Link"></div>
                        <div class="col-md-2"><input type="text" name="journal_options[{{ $i }}][apc]" class="form-control form-control-sm" value="{{ $opt->apc }}" placeholder="APC"></div>
                        <div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger w-100" data-jo-remove>×</button></div>
                    </div>
                @endforeach
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary mb-3" id="joAdd">+ Opsi Jurnal</button>

            <div><button type="submit" class="btn btn-sm btn-primary">Simpan Informasi</button></div>
        </form>
    </div>
    @endif

    @if($title->logs->isNotEmpty())
        <h6 class="text-muted small mt-3">Riwayat Perubahan</h6>
        <ul class="list-unstyled small mb-0">
            @foreach($title->logs->take(10) as $log)
                <li class="mb-1">• {{ $log->note }} — <span class="text-muted">{{ $log->changedBy?->name ?? '—' }}, {{ optional($log->created_at)->format('d M Y H:i') }}</span></li>
            @endforeach
        </ul>
    @endif
</div></div></div></div>
@endif
```

- [ ] **Step 4: show — repeater + flatpickr script**

At the end of `resources/views/titles/show.blade.php` (after `@endsection`), add a scripts stack (the master supports `@push('plugin-scripts')`/`@push('custom-scripts')`; flatpickr asset is bundled at `assets/plugins/flatpickr/flatpickr.min.js` with CSS `assets/plugins/flatpickr/flatpickr.min.css`):

```blade
@push('plugin-styles')
<link href="{{ asset('assets/plugins/flatpickr/flatpickr.min.css') }}" rel="stylesheet" />
@endpush
@push('plugin-scripts')
<script src="{{ asset('assets/plugins/flatpickr/flatpickr.min.js') }}"></script>
@endpush
@push('custom-scripts')
<script>
$(function () {
    if (window.flatpickr) { flatpickr('.flatpickr-date', { dateFormat: 'Y-m-d' }); }
    var list = document.getElementById('joList');
    var addBtn = document.getElementById('joAdd');
    var idx = list ? list.querySelectorAll('[data-jo-row]').length : 0;
    if (addBtn) addBtn.addEventListener('click', function () {
        var row = document.createElement('div');
        row.className = 'row g-1 mb-1';
        row.setAttribute('data-jo-row', '');
        row.innerHTML = '<div class="col-md-5"><input type="text" name="journal_options[' + idx + '][nama_jurnal]" class="form-control form-control-sm" placeholder="Nama jurnal"></div>'
            + '<div class="col-md-4"><input type="text" name="journal_options[' + idx + '][link]" class="form-control form-control-sm" placeholder="Link"></div>'
            + '<div class="col-md-2"><input type="text" name="journal_options[' + idx + '][apc]" class="form-control form-control-sm" placeholder="APC"></div>'
            + '<div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger w-100" data-jo-remove>×</button></div>';
        list.appendChild(row); idx++;
    });
    if (list) list.addEventListener('click', function (e) {
        var b = e.target.closest('[data-jo-remove]');
        if (b) b.closest('[data-jo-row]').remove();
    });
});
</script>
@endpush
```

Verify flatpickr asset path exists: `Get-ChildItem public/assets/plugins/flatpickr` — if the path differs, use whatever the app already uses for flatpickr (grep an existing view that uses `flatpickr`). If flatpickr is not bundled, fall back to `<input type="date" ...>` for `target_terbit` and drop the flatpickr script.

- [ ] **Step 5: order select2 — code prefix in option label (4 views)**

In each of `orders/book/create.blade.php`, `orders/edit.blade.php`, `orders/journal/create.blade.php`, `orders/journal/edit.blade.php`, the title `<select ... name="title_id">` has options like `>{{ $t->title }}</option>`. Change the option **text** to prefix the code (keep the `value` unchanged):
```blade
>{{ $t->code ? $t->code . ' — ' : '' }}{{ $t->title }}</option>
```
Do NOT change the option `value`, the `data-*` attributes, or anything else — only the visible text between `>` and `</option>`.

- [ ] **Step 6: Compile + run**

Run: `php artisan view:cache` (clean) then `php artisan view:clear`.
Run: `php artisan test --filter="TitlePublicationInfoTest|TitleControllerTest|TitlePagesTest|TitleOrderLinkTest|OrderJournalEditTest"`
Expected: PASS all (incl. the two panel-visibility tests now).

- [ ] **Step 7: Commit**

```
git add resources/views/titles/show.blade.php resources/views/titles/index.blade.php resources/views/orders/book/create.blade.php resources/views/orders/edit.blade.php resources/views/orders/journal/create.blade.php resources/views/orders/journal/edit.blade.php
git commit -m "feat(title-info): publication panel + code column + order select2 code labels

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 7: Full verification + migrate dev

**Files:** none (verification only)

- [ ] **Step 1: Whole suite**

Run: `php artisan test`
Expected: PASS all (311 sebelumnya + TitleCodeServiceTest (5) + 3 unit (code×2 + updateInfo) + TitlePublicationInfoTest (4) = ~323).

- [ ] **Step 2: Compile Blade**

Run: `php artisan view:cache` (no error) → `php artisan view:clear`.

- [ ] **Step 3: Migrate dev DB**

Run: `php artisan migrate --force` (adds code+publication columns, 2 tables, backfills codes for existing titles). See [[migrate-dev-db-after-new-migration]].

- [ ] **Step 4: Smoke (opsional)**

Login manager → detail judul → panel "Informasi Publikasi" → Edit Informasi → isi target terbit/jurnal/template/opsi jurnal → Simpan → cek Riwayat Perubahan bertambah + superadmin dapat lonceng. Login production → melihat panel (read-only, tak ada tombol Edit). Login marketing → tak ada panel. Direktori & select2 order menampilkan kode.

---

## Catatan & Risiko

- Kode = inisial 4 kata pertama; fallback huruf judul / `JDL`; unik via sufiks angka. Override manual tervalidasi unik. Backfill idempotent (hanya `null`).
- Race dua judul base sama pada request paralel: unique index melindungi (worst case 500 langka). Diterima.
- Panel publikasi lepas dari lifecycle approval; dilihat production untuk template, diubah superadmin/manager/admin.
- Perubahan select2 order hanya teks tampilan (prefix kode); `value`/`data-*` tetap → resolusi `title_id` utuh.
- Flatpickr: bila path aset berbeda/tak ada, fallback `<input type="date">`.
