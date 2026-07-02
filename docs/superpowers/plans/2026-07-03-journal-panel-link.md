# Journal Epic Fase C (Panel ↔ Direktori Jurnal) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Repeater "Opsi Jurnal Lain" di panel publikasi judul bisa memilih jurnal dari `tb_journals` (select2); saat dipilih, snapshot nama/link/apc dari Journal + simpan `journal_id`; tampilan menautkan ke detail jurnal. Tetap dukung teks bebas.

**Architecture:** `TitleJournalOption.journal_id` (nullable FK). `TitleService::updateInfo` snapshot dari `Journal` bila `journal_id` diisi. `TitleController@show` kirim `$journals`. Panel repeater (`titles/show.blade.php`) tambah select journal + `<template>` + select2 init.

**Tech Stack:** Laravel 11, Eloquent, Blade + select2 (bundled).

**Spec:** `docs/superpowers/specs/2026-07-03-journal-panel-link-design.md`

**Catatan env:** Tests `.env.testing` + `RefreshDatabase`; mock `App\Services\GoogleDriveService`. DB mati → `Start-Process -FilePath "C:\xampp\mysql\bin\mysqld.exe" -ArgumentList '--defaults-file=C:\xampp\mysql\bin\my.ini' -WindowStyle Hidden` (PowerShell). Commit: `git add <path>` + `Co-authored-by: Mira <admin@avidpedia.com>`, TANPA "Claude"/"Anthropic". Migrasi terakhir `2026_07_02_000013`; baru = `2026_07_03_000001`. Setelah selesai: `php artisan migrate` di dev.

**Fakta:**
- `App\Models\Journal` (tb_journals): `nama`, `link`, `apc_reguler`. `App\Models\TitleJournalOption` (tb_title_journal_options): fillable title_id/nama_jurnal/link/apc/urutan; belongsTo `title()`.
- `TitleService::updateInfo` loop opsi jurnal saat ini:
  ```php
  $title->journalOptions()->delete();
  $i = 0;
  foreach ($journalOptions as $opt) {
      $nama = trim((string) ($opt['nama_jurnal'] ?? ''));
      if ($nama === '') { continue; }
      $title->journalOptions()->create([
          'nama_jurnal' => $nama, 'link' => $opt['link'] ?? null, 'apc' => $opt['apc'] ?? null, 'urutan' => $i++,
      ]);
  }
  ```
- `TitleController@show` eager-loads `[... 'journalOptions', 'logs.changedBy']` dan mengirim `title/canManage/isApprover/ordersCount/authorsCount/canViewInfo/canEditInfo`. `updateInfo` memvalidasi `journal_options.*.nama_jurnal|link|apc`.
- `titles/show.blade.php`: read-only "Opsi Jurnal Lain" (`@forelse($title->journalOptions as $opt) <div class="small mb-1">• {{ $opt->nama_jurnal }}@if($opt->link)…@endif @if($opt->apc)…@endif</div> …`); form repeater `#joList` (rows `data-jo-row` dgn input `journal_options[i][nama_jurnal|link|apc]` + `#joAdd` button + JS `innerHTML` untuk baris baru).

---

## Task 1: Migration + model + service + controller (TDD)

**Files:**
- Create: `database/migrations/2026_07_03_000001_add_journal_id_to_tb_title_journal_options.php`
- Modify: `app/Models/TitleJournalOption.php`, `app/Services/TitleService.php`, `app/Http/Controllers/Pages/TitleController.php`
- Test: `tests/Feature/JournalPanelLinkTest.php`

- [ ] **Step 1: Migration**

Create `database/migrations/2026_07_03_000001_add_journal_id_to_tb_title_journal_options.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_title_journal_options', function (Blueprint $table) {
            $table->foreignId('journal_id')->nullable()->after('title_id')->constrained('tb_journals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tb_title_journal_options', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_id');
        });
    }
};
```

- [ ] **Step 2: Model**

In `app/Models/TitleJournalOption.php`, add `'journal_id'` to `$fillable` and add the relation:
```php
    public function journal()
    {
        return $this->belongsTo(Journal::class);
    }
```

- [ ] **Step 3: Write failing test** — create `tests/Feature/JournalPanelLinkTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Journal;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class JournalPanelLinkTest extends TestCase
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
        return Title::create(['title' => 'Judul Panel', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
    }

    /** @test */
    public function journal_option_from_directory_snapshots_journal_fields(): void
    {
        $mgr = $this->user('manager');
        $journal = Journal::create(['nama' => 'Jurnal Direktori', 'link' => 'https://jd.test', 'apc_reguler' => 'Rp 2.000.000', 'created_by' => $mgr->id]);
        $title = $this->title();

        $this->actingAs($mgr)->put(route('title.info.update', $title->id), [
            'journal_options' => [['journal_id' => $journal->id]],
        ])->assertRedirect();

        $opt = $title->journalOptions()->first();
        $this->assertNotNull($opt);
        $this->assertSame($journal->id, $opt->journal_id);
        $this->assertSame('Jurnal Direktori', $opt->nama_jurnal);
        $this->assertSame('https://jd.test', $opt->link);
        $this->assertSame('Rp 2.000.000', $opt->apc);
    }

    /** @test */
    public function free_text_journal_option_still_works(): void
    {
        $mgr = $this->user('manager');
        $title = $this->title();

        $this->actingAs($mgr)->put(route('title.info.update', $title->id), [
            'journal_options' => [['nama_jurnal' => 'Jurnal Manual', 'link' => 'https://m.test', 'apc' => 'Gratis']],
        ])->assertRedirect();

        $opt = $title->journalOptions()->first();
        $this->assertNull($opt->journal_id);
        $this->assertSame('Jurnal Manual', $opt->nama_jurnal);
    }
}
```

- [ ] **Step 4: Run, confirm FAIL**

Run: `php artisan test --filter=JournalPanelLinkTest`
Expected: FAIL (journal_id not saved / column absent handled but snapshot missing).

- [ ] **Step 5: `TitleService::updateInfo` — snapshot from Journal**

Replace the journal-options loop body inside the `DB::transaction`:
```php
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
```
with:
```php
            $title->journalOptions()->delete();
            $i = 0;
            foreach ($journalOptions as $opt) {
                $journalId = ! empty($opt['journal_id']) ? (int) $opt['journal_id'] : null;
                $journal   = $journalId ? \App\Models\Journal::find($journalId) : null;
                $nama      = $journal ? $journal->nama : trim((string) ($opt['nama_jurnal'] ?? ''));
                if ($nama === '') {
                    continue;
                }
                $title->journalOptions()->create([
                    'journal_id'  => $journal?->id,
                    'nama_jurnal' => $nama,
                    'link'        => $journal ? $journal->link : ($opt['link'] ?? null),
                    'apc'         => $journal ? $journal->apc_reguler : ($opt['apc'] ?? null),
                    'urutan'      => $i++,
                ]);
            }
```

- [ ] **Step 6: `TitleController` — show `$journals` + validate journal_id**

In `app/Http/Controllers/Pages/TitleController.php`:
- Add `use App\Models\Journal;` near the imports.
- In `show()`, add `'journalOptions.journal'` to the eager-load array (change `'journalOptions'` → `'journalOptions.journal'`), and add `'journals' => Journal::orderBy('nama')->get()` to the array passed to `view('titles.show', [...])`.
- In `updateInfo()` validation, add the rule `'journal_options.*.journal_id' => 'nullable|integer|exists:tb_journals,id',` (keep existing journal_options.*.nama_jurnal/link/apc rules).

- [ ] **Step 7: Run, confirm PASS**

Run: `php artisan test --filter=JournalPanelLinkTest`
Expected: PASS (2 tests).

- [ ] **Step 8: Commit**

```
git add database/migrations/2026_07_03_000001_add_journal_id_to_tb_title_journal_options.php app/Models/TitleJournalOption.php app/Services/TitleService.php app/Http/Controllers/Pages/TitleController.php tests/Feature/JournalPanelLinkTest.php
git commit -m "feat(journal-link): title journal option snapshots directory journal (journal_id)

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 2: Panel view — journal select in repeater + display link

**Files:**
- Modify: `resources/views/titles/show.blade.php`
- Test: `tests/Feature/JournalPanelLinkTest.php` (add display test)

- [ ] **Step 1: Add display test** — append inside `JournalPanelLinkTest`:

```php
    /** @test */
    public function detail_links_directory_journal_option(): void
    {
        $mgr = $this->user('manager');
        $journal = Journal::create(['nama' => 'Jurnal Tautan', 'created_by' => $mgr->id]);
        $title = $this->title();
        $title->journalOptions()->create(['journal_id' => $journal->id, 'nama_jurnal' => 'Jurnal Tautan', 'urutan' => 0]);

        $this->actingAs($mgr)->get(route('title.show', $title->id))
            ->assertOk()->assertSee(route('journal.show', $journal->id))->assertSee('Jurnal Tautan');
    }
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter="JournalPanelLinkTest::detail_links_directory_journal_option"`
Expected: FAIL (read-only display doesn't link yet).

- [ ] **Step 3: Read-only display — link directory options**

In `resources/views/titles/show.blade.php`, the read-only "Opsi Jurnal Lain" list. Change the `@forelse($title->journalOptions as $opt)` row so a directory-linked option renders its name as a link + badge. Replace:
```blade
        <div class="small mb-1">• {{ $opt->nama_jurnal }}@if($opt->link) · <a href="{{ $opt->link }}" target="_blank" rel="noopener">link</a>@endif @if($opt->apc)· APC: {{ $opt->apc }}@endif</div>
```
with:
```blade
        <div class="small mb-1">•
            @if($opt->journal_id && $opt->journal)
                <a href="{{ route('journal.show', $opt->journal_id) }}">{{ $opt->nama_jurnal }}</a> <span class="badge bg-light text-dark border" style="font-size:9px">direktori</span>
            @else
                {{ $opt->nama_jurnal }}
            @endif
            @if($opt->link) · <a href="{{ $opt->link }}" target="_blank" rel="noopener">link</a>@endif @if($opt->apc)· APC: {{ $opt->apc }}@endif</div>
```

- [ ] **Step 4: Form repeater — journal select + template**

Replace the existing `#joList` block (the `<div id="joList">…@foreach…@endforeach…</div>` and the `#joAdd` button that follow the `<label class="form-label">Opsi Jurnal Lain</label>`) with a version where each row has a journal `<select class="select2-journal">` above the free-text inputs, and add a `<template id="joRowTpl">` for new rows. Concretely, replace from `<div id="joList">` through the `<button ... id="joAdd">+ Opsi Jurnal</button>` with:

```blade
            <div id="joList">
                @foreach($title->journalOptions as $i => $opt)
                    <div class="border rounded p-2 mb-1" data-jo-row>
                        <div class="row g-1">
                            <div class="col-md-11">
                                <select name="journal_options[{{ $i }}][journal_id]" class="form-select form-select-sm select2-journal">
                                    <option value="">— pilih dari direktori (opsional) —</option>
                                    @foreach($journals as $j)
                                        <option value="{{ $j->id }}" {{ $opt->journal_id == $j->id ? 'selected' : '' }}>{{ $j->nama }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger w-100" data-jo-remove>×</button></div>
                        </div>
                        <div class="row g-1 mt-1">
                            <div class="col-md-5"><input type="text" name="journal_options[{{ $i }}][nama_jurnal]" class="form-control form-control-sm" value="{{ $opt->nama_jurnal }}" placeholder="Nama jurnal (manual)"></div>
                            <div class="col-md-4"><input type="text" name="journal_options[{{ $i }}][link]" class="form-control form-control-sm" value="{{ $opt->link }}" placeholder="Link"></div>
                            <div class="col-md-3"><input type="text" name="journal_options[{{ $i }}][apc]" class="form-control form-control-sm" value="{{ $opt->apc }}" placeholder="APC"></div>
                        </div>
                    </div>
                @endforeach
            </div>
            <template id="joRowTpl">
                <div class="border rounded p-2 mb-1" data-jo-row>
                    <div class="row g-1">
                        <div class="col-md-11">
                            <select name="journal_options[__IDX__][journal_id]" class="form-select form-select-sm select2-journal">
                                <option value="">— pilih dari direktori (opsional) —</option>
                                @foreach($journals as $j)
                                    <option value="{{ $j->id }}">{{ $j->nama }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger w-100" data-jo-remove>×</button></div>
                    </div>
                    <div class="row g-1 mt-1">
                        <div class="col-md-5"><input type="text" name="journal_options[__IDX__][nama_jurnal]" class="form-control form-control-sm" placeholder="Nama jurnal (manual)"></div>
                        <div class="col-md-4"><input type="text" name="journal_options[__IDX__][link]" class="form-control form-control-sm" placeholder="Link"></div>
                        <div class="col-md-3"><input type="text" name="journal_options[__IDX__][apc]" class="form-control form-control-sm" placeholder="APC"></div>
                    </div>
                </div>
            </template>
            <small class="text-muted d-block mb-2">Pilih jurnal dari direktori (nama/link/APC otomatis), atau isi manual bila belum terdaftar.</small>
            <button type="button" class="btn btn-sm btn-outline-primary mb-3" id="joAdd">+ Opsi Jurnal</button>
```

- [ ] **Step 5: JS — select2 init + template-based add**

In the `@push('custom-scripts')` script of `titles/show.blade.php`, find the repeater JS (`var list = document.getElementById('joList'); var addBtn = document.getElementById('joAdd'); var idx = …; if (addBtn) addBtn.addEventListener('click', function () { ... row.innerHTML = '...'; list.appendChild(row); idx++; });`). Replace that add-handler + add select2 init. Replace the block:
```javascript
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
```
with:
```javascript
    var list = document.getElementById('joList');
    var addBtn = document.getElementById('joAdd');
    var tpl = document.getElementById('joRowTpl');
    var idx = list ? list.querySelectorAll('[data-jo-row]').length : 0;

    function initJournalSelect(scope) {
        if (!window.jQuery || !jQuery.fn.select2) return;
        jQuery(scope).find('.select2-journal').each(function () {
            if (!jQuery(this).hasClass('select2-hidden-accessible')) {
                jQuery(this).select2({ width: '100%', placeholder: 'Cari jurnal…', allowClear: true });
            }
        });
    }
    if (list) initJournalSelect(list);

    if (addBtn && tpl) addBtn.addEventListener('click', function () {
        var html = tpl.innerHTML.replace(/__IDX__/g, idx);
        var wrap = document.createElement('div');
        wrap.innerHTML = html.trim();
        var row = wrap.firstElementChild;
        list.appendChild(row);
        initJournalSelect(row);
        idx++;
    });
```
(The `data-jo-remove` click handler that follows stays unchanged.)

- [ ] **Step 6: Compile + run**

Run: `php artisan view:cache` (clean) then `php artisan view:clear`.
Run: `php artisan test --filter="JournalPanelLinkTest|TitlePublicationInfoTest|TitlePagesTest"`
Expected: PASS all.

- [ ] **Step 7: Commit**

```
git add resources/views/titles/show.blade.php tests/Feature/JournalPanelLinkTest.php
git commit -m "feat(journal-link): panel repeater picks directory journal (select2) + detail link

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 3: Full verification + migrate dev

**Files:** none.

- [ ] **Step 1: Whole suite**

Run: `php artisan test`
Expected: PASS all (362 + JournalPanelLinkTest (3) = ~365).

- [ ] **Step 2: Compile Blade**

Run: `php artisan view:cache` (no error) → `php artisan view:clear`.

- [ ] **Step 3: Migrate dev DB**

Run: `php artisan migrate --force` (kolom `journal_id`). See [[migrate-dev-db-after-new-migration]].

- [ ] **Step 4: Smoke (opsional)**

Login manager → detail judul → Edit Informasi → di "Opsi Jurnal Lain", pilih jurnal dari direktori (select2) atau isi manual → Simpan → panel menampilkan opsi (yang dari direktori = link ke detail jurnal + badge "direktori").

---

## Catatan & Risiko

- Hybrid: `journal_id` opsional; bila diisi, nama/link/apc disnapshot dari Journal (tetap tampil bila jurnal kelak dihapus, `nullOnDelete`).
- select2 pada baris repeater dinamis: init via `<template>` clone + `initJournalSelect` (panel di collapse, bukan modal → tanpa dropdownParent).
- Opsi teks lama tak dimigrasi otomatis; pengelola dapat memilih ulang dari direktori saat edit berikutnya.
- Penutup epik jurnal (A direktori, B submission, C panel-link).
