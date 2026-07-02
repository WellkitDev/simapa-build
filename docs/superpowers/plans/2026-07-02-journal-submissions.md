# Direktori Jurnal Fase B (Tracking Submit Artikel) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Catat artikel yang di-submit/terbit ke jurnal: entitas `JournalSubmission` (tgl submit/terbit, OJS akun+password terenkripsi, LoA & bukti bayar ke Google Drive, link publish, status) + popup modal (tambah/edit/detail) + daftar di detail jurnal. Kelola superadmin/manager/admin; staf lihat read-only.

**Architecture:** `JournalSubmission` (per Journal, menaut Title artikel). `JournalSubmissionController` (store/update/destroy) inject `GoogleDriveService`. `JournalController@show` memuat submissions + daftar artikel. Detail jurnal: tabel + 1 modal create (shared) + modal edit/detail per baris (server-rendered, password di-guard `canManage`).

**Tech Stack:** Laravel 11, Eloquent (`encrypted` cast), Google Drive upload, Blade + Bootstrap modal + SweetAlert2.

**Spec:** `docs/superpowers/specs/2026-07-02-journal-submissions-design.md`

**Catatan env:** Tests `.env.testing` + `RefreshDatabase`; mock `App\Services\GoogleDriveService`; `Illuminate\Http\UploadedFile::fake()`. DB mati → `Start-Process -FilePath "C:\xampp\mysql\bin\mysqld.exe" -ArgumentList '--defaults-file=C:\xampp\mysql\bin\my.ini' -WindowStyle Hidden` (PowerShell). Commit: `git add <path>` + `Co-authored-by: Mira <admin@avidpedia.com>`, TANPA "Claude"/"Anthropic". Migrasi terakhir `2026_07_02_000012`; baru = `2026_07_02_000013`. Setelah selesai: `php artisan migrate` di dev.

**Fakta:** `GoogleDriveService::uploadFile($file, ?string $folderId=null, bool $makePublic=true): ?array` → `['id','name','url']` atau `null`. `App\Models\Journal` (tb_journals). `App\Models\Title` (jenis artikel|buku, status disetujui). `resources/views/journals/show.blade.php` diakhiri blok placeholder (`<hr class="my-3"><h6 class="card-title">Artikel di Jurnal Ini</h6><p ...>…fase berikutnya…</p>`) di dalam kartu. Master punya SweetAlert2 global (`form[data-confirm]`).

---

## Task 1: Migration + `JournalSubmission` model + relation

**Files:**
- Create: `database/migrations/2026_07_02_000013_create_tb_journal_submissions_table.php`, `app/Models/JournalSubmission.php`
- Modify: `app/Models/Journal.php`

- [ ] **Step 1: Migration**

Create `database/migrations/2026_07_02_000013_create_tb_journal_submissions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_journal_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')->constrained('tb_journals')->cascadeOnDelete();
            $table->foreignId('title_id')->nullable()->constrained('tb_titles')->nullOnDelete();
            $table->date('tgl_submit')->nullable();
            $table->date('tgl_terbit')->nullable();
            $table->string('ojs_akun')->nullable();
            $table->text('ojs_password')->nullable();
            $table->string('loa_url')->nullable();
            $table->string('bukti_bayar_url')->nullable();
            $table->string('link_publish')->nullable();
            $table->string('status', 16)->default('submitted');
            $table->text('catatan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_journal_submissions');
    }
};
```

- [ ] **Step 2: Model `JournalSubmission`**

Create `app/Models/JournalSubmission.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JournalSubmission extends Model
{
    use HasFactory;

    protected $table = 'tb_journal_submissions';

    public const STATUSES = ['submitted', 'loa', 'published'];

    protected $fillable = [
        'journal_id', 'title_id', 'tgl_submit', 'tgl_terbit', 'ojs_akun', 'ojs_password',
        'loa_url', 'bukti_bayar_url', 'link_publish', 'status', 'catatan', 'created_by',
    ];

    protected $casts = [
        'ojs_password' => 'encrypted',
        'tgl_submit'   => 'date',
        'tgl_terbit'   => 'date',
    ];

    public function journal()
    {
        return $this->belongsTo(Journal::class);
    }

    public function title()
    {
        return $this->belongsTo(Title::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function statusLabel(): string
    {
        return ['submitted' => 'Submitted', 'loa' => 'LoA', 'published' => 'Published'][$this->status] ?? ucfirst($this->status);
    }
}
```

- [ ] **Step 3: `Journal::submissions()`**

In `app/Models/Journal.php`, add (after the existing `creator()` method):

```php
    public function submissions()
    {
        return $this->hasMany(JournalSubmission::class)->latest();
    }
```

- [ ] **Step 4: Verify migration healthy**

Run: `php artisan test --filter=JournalControllerTest`
Expected: PASS (RefreshDatabase applies the new migration cleanly).

- [ ] **Step 5: Commit**

```
git add database/migrations/2026_07_02_000013_create_tb_journal_submissions_table.php app/Models/JournalSubmission.php app/Models/Journal.php
git commit -m "feat(journal-sub): tb_journal_submissions + model + relation (encrypted ojs pw)

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 2: `JournalSubmissionController` + routes + feature tests (TDD)

**Files:**
- Create: `app/Http/Controllers/Pages/JournalSubmissionController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/JournalSubmissionTest.php`

- [ ] **Step 1: Write the failing test** — create `tests/Feature/JournalSubmissionTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Journal;
use App\Models\JournalSubmission;
use App\Services\GoogleDriveService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class JournalSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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

    private function journal(): Journal
    {
        return Journal::create(['nama' => 'Jurnal Sub', 'created_by' => $this->user('manager')->id]);
    }

    /** @test */
    public function manager_adds_submission_with_encrypted_password(): void
    {
        $this->mock(GoogleDriveService::class); // no upload in this test
        $j = $this->journal();
        $title = Title::create(['title' => 'Artikel A', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);

        $this->actingAs($this->user('manager'))->post(route('journal.submission.store', $j->id), [
            'title_id' => $title->id, 'tgl_submit' => '2026-08-01', 'status' => 'submitted',
            'ojs_akun' => 'akun1', 'ojs_password' => 'rahasia123', 'catatan' => 'c',
        ])->assertRedirect();

        $sub = JournalSubmission::first();
        $this->assertSame($title->id, $sub->title_id);
        $this->assertSame('submitted', $sub->status);
        // password terenkripsi: nilai mentah di DB bukan plaintext, decrypt via model cocok
        $raw = DB::table('tb_journal_submissions')->where('id', $sub->id)->value('ojs_password');
        $this->assertNotSame('rahasia123', $raw);
        $this->assertSame('rahasia123', $sub->ojs_password);
    }

    /** @test */
    public function loa_file_uploads_to_drive(): void
    {
        $this->mock(GoogleDriveService::class, function ($m) {
            $m->shouldReceive('uploadFile')->andReturn(['id' => 'x', 'name' => 'loa.pdf', 'url' => 'https://drive.test/loa']);
        });
        $j = $this->journal();

        $this->actingAs($this->user('manager'))->post(route('journal.submission.store', $j->id), [
            'status' => 'submitted',
            'loa' => UploadedFile::fake()->create('loa.pdf', 40, 'application/pdf'),
        ])->assertRedirect();

        $this->assertSame('https://drive.test/loa', JournalSubmission::first()->loa_url);
    }

    /** @test */
    public function update_changes_status_and_keeps_password_when_blank(): void
    {
        $this->mock(GoogleDriveService::class);
        $j = $this->journal();
        $sub = JournalSubmission::create(['journal_id' => $j->id, 'status' => 'submitted', 'ojs_password' => 'lama']);

        $this->actingAs($this->user('manager'))->put(route('journal.submission.update', $sub->id), [
            'status' => 'published', 'tgl_terbit' => '2026-09-01', 'ojs_password' => '',
        ])->assertRedirect();

        $sub->refresh();
        $this->assertSame('published', $sub->status);
        $this->assertSame('2026-09-01', $sub->tgl_terbit->toDateString());
        $this->assertSame('lama', $sub->ojs_password); // password kosong → dipertahankan
    }

    /** @test */
    public function marketing_cannot_add_submission(): void
    {
        $j = $this->journal();
        $this->actingAs($this->user('marketing'))
            ->post(route('journal.submission.store', $j->id), ['status' => 'submitted'])
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=JournalSubmissionTest`
Expected: FAIL — route `journal.submission.store` undefined.

- [ ] **Step 3: Controller** — create `app/Http/Controllers/Pages/JournalSubmissionController.php`:

```php
<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\Journal;
use App\Models\JournalSubmission;
use App\Services\GoogleDriveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class JournalSubmissionController extends Controller
{
    public function __construct(private GoogleDriveService $drive) {}

    private function canManage(): bool
    {
        return Auth::user()->hasAnyRole(['superadmin', 'manager', 'admin']);
    }

    public function store(Request $request, int $journal)
    {
        abort_unless($this->canManage(), 403);
        $j = Journal::findOrFail($journal);
        $data = $this->prepare($request);
        $data['journal_id'] = $j->id;
        $data['created_by'] = Auth::id();
        if (($data['ojs_password'] ?? '') === '') {
            unset($data['ojs_password']);
        }
        JournalSubmission::create($data);

        return redirect()->route('journal.show', $j->id)->with('success', 'Submission artikel ditambahkan.');
    }

    public function update(Request $request, int $id)
    {
        abort_unless($this->canManage(), 403);
        $sub = JournalSubmission::findOrFail($id);
        $data = $this->prepare($request);
        if (($data['ojs_password'] ?? '') === '') {
            unset($data['ojs_password']); // kosong → pertahankan lama
        }
        // `prepare()` hanya menaruh loa_url/bukti_bayar_url bila ada unggahan baru,
        // jadi url lama tak tertimpa saat tak ada file baru.
        $sub->update($data);

        return redirect()->route('journal.show', $sub->journal_id)->with('success', 'Submission diperbarui.');
    }

    public function destroy(int $id)
    {
        abort_unless($this->canManage(), 403);
        $sub = JournalSubmission::findOrFail($id);
        $jid = $sub->journal_id;
        $sub->delete();

        return redirect()->route('journal.show', $jid)->with('success', 'Submission dihapus.');
    }

    /** Validasi + normalisasi: hapus key file, ganti dengan *_url bila ada unggahan baru. */
    private function prepare(Request $request): array
    {
        $data = $request->validate([
            'title_id'     => 'nullable|integer|exists:tb_titles,id',
            'tgl_submit'   => 'nullable|date',
            'tgl_terbit'   => 'nullable|date',
            'ojs_akun'     => 'nullable|string|max:255',
            'ojs_password' => 'nullable|string|max:255',
            'link_publish' => 'nullable|string|max:255',
            'status'       => 'required|in:submitted,loa,published',
            'catatan'      => 'nullable|string',
            'loa'          => 'nullable|file|max:5120',
            'bukti_bayar'  => 'nullable|file|max:5120',
        ]);

        // File → Drive → url (hanya bila diunggah).
        if ($request->hasFile('loa')) {
            $data['loa_url'] = $this->drive->uploadFile($request->file('loa'), null, true)['url'] ?? null;
        }
        if ($request->hasFile('bukti_bayar')) {
            $data['bukti_bayar_url'] = $this->drive->uploadFile($request->file('bukti_bayar'), null, true)['url'] ?? null;
        }
        unset($data['loa'], $data['bukti_bayar']);

        return $data;
    }
}
```

- [ ] **Step 4: Routes** — in `routes/web.php`, add `use App\Http\Controllers\Pages\JournalSubmissionController;` near the other Pages imports. Inside the SAME `role:superadmin|manager|admin` group that holds the journal CRUD routes (or a sibling group with that role), add:

```php
        Route::post('journals/{journal}/submissions', [JournalSubmissionController::class, 'store'])->name('journal.submission.store')->whereNumber('journal');
        Route::put('journals/submissions/{id}', [JournalSubmissionController::class, 'update'])->name('journal.submission.update')->whereNumber('id');
        Route::delete('journals/submissions/{id}', [JournalSubmissionController::class, 'destroy'])->name('journal.submission.destroy')->whereNumber('id');
```
(These are POST/PUT/DELETE and use literal `submissions` segments — no conflict with `GET journals/{id}`.) Run `php artisan route:list --name=journal.submission` to confirm 3 routes with the role middleware.

- [ ] **Step 5: Run, confirm PASS**

Run: `php artisan test --filter=JournalSubmissionTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```
git add app/Http/Controllers/Pages/JournalSubmissionController.php routes/web.php tests/Feature/JournalSubmissionTest.php
git commit -m "feat(journal-sub): submission controller (Drive upload, encrypted pw) + routes

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 3: Journal detail — submissions table + modals

**Files:**
- Modify: `app/Http/Controllers/Pages/JournalController.php` (show: load submissions + articles)
- Modify: `resources/views/journals/show.blade.php`
- Test: `tests/Feature/JournalSubmissionPagesTest.php`

- [ ] **Step 1: Write the failing test** — create `tests/Feature/JournalSubmissionPagesTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Title;
use App\Models\Journal;
use App\Models\JournalSubmission;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class JournalSubmissionPagesTest extends TestCase
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

    /** @test */
    public function detail_lists_submissions_and_manager_sees_add_button(): void
    {
        $mgr = $this->user('manager');
        $j = Journal::create(['nama' => 'Jurnal Z', 'created_by' => $mgr->id]);
        $title = Title::create(['title' => 'Artikel Tercatat', 'jenis' => 'artikel', 'tipe_naskah' => 'mandiri', 'status' => 'disetujui']);
        JournalSubmission::create(['journal_id' => $j->id, 'title_id' => $title->id, 'status' => 'loa']);

        $this->actingAs($mgr)->get(route('journal.show', $j->id))
            ->assertOk()->assertSee('Artikel Tercatat')->assertSee('Tambah Artikel Submit');
    }

    /** @test */
    public function marketing_sees_list_without_manage_buttons(): void
    {
        $mgr = $this->user('manager');
        $j = Journal::create(['nama' => 'Jurnal Z', 'created_by' => $mgr->id]);
        JournalSubmission::create(['journal_id' => $j->id, 'status' => 'submitted']);

        $this->actingAs($this->user('marketing'))->get(route('journal.show', $j->id))
            ->assertOk()->assertDontSee('Tambah Artikel Submit');
    }
}
```

- [ ] **Step 2: Run, confirm FAIL**

Run: `php artisan test --filter=JournalSubmissionPagesTest`
Expected: FAIL (show lacks submissions section).

- [ ] **Step 3: `JournalController@show` — load submissions + articles**

In `app/Http/Controllers/Pages/JournalController.php`, add `use App\Models\Title;` near the imports. Change `show()` to:

```php
    public function show(int $id)
    {
        return view('journals.show', [
            'journal' => Journal::with(['scope', 'creator', 'submissions.title'])->findOrFail($id),
            'canManage' => $this->canManage(),
            'articles' => Title::where('jenis', 'artikel')->where('status', 'disetujui')->orderBy('title')->get(),
        ]);
    }
```

- [ ] **Step 4: `resources/views/journals/show.blade.php` — replace the placeholder block**

Replace the placeholder:
```blade
    <hr class="my-3">
    <h6 class="card-title">Artikel di Jurnal Ini</h6>
    <p class="text-muted small mb-0">Daftar artikel yang di-submit/terbit ke jurnal ini akan hadir di fase berikutnya (tracking submit artikel).</p>
</div></div></div></div>
@endsection
```
with:
```blade
    <hr class="my-3">
    @php $sb = ['submitted' => 'bg-secondary', 'loa' => 'bg-info', 'published' => 'bg-success']; @endphp
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="card-title mb-0">Artikel di Jurnal Ini</h6>
        @if($canManage)
            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#subCreate">+ Tambah Artikel Submit</button>
        @endif
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover">
            <thead><tr><th>Judul</th><th>Submit</th><th>Terbit</th><th>Status</th>@if($canManage)<th>Aksi</th>@endif</tr></thead>
            <tbody>
                @forelse($journal->submissions as $s)
                    <tr>
                        <td>{{ $s->title?->title ?? '—' }}</td>
                        <td>{{ optional($s->tgl_submit)->format('d M Y') ?? '—' }}</td>
                        <td>{{ optional($s->tgl_terbit)->format('d M Y') ?? '—' }}</td>
                        <td><span class="badge {{ $sb[$s->status] ?? 'bg-secondary' }}">{{ $s->statusLabel() }}</span></td>
                        @if($canManage)
                            <td>
                                <button type="button" class="btn btn-xs btn-outline-primary" data-bs-toggle="modal" data-bs-target="#subDetail{{ $s->id }}">Detail</button>
                                <button type="button" class="btn btn-xs btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#subEdit{{ $s->id }}">Edit</button>
                                <form action="{{ route('journal.submission.destroy', $s->id) }}" method="POST" class="d-inline m-0" data-confirm="Hapus submission ini?">@csrf @method('DELETE')<button class="btn btn-xs btn-outline-danger">Hapus</button></form>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $canManage ? 5 : 4 }}" class="text-muted text-center py-3">Belum ada submission.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div></div></div></div>

@if($canManage)
    {{-- Modal Create --}}
    <div class="modal fade" id="subCreate" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content">
        <form method="POST" action="{{ route('journal.submission.store', $journal->id) }}" enctype="multipart/form-data">
            @csrf
            <div class="modal-header"><h6 class="modal-title">Tambah Artikel Submit</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                @include('journals.partials.submission-fields', ['s' => null])
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">Batal</button><button type="submit" class="btn btn-sm btn-primary">Simpan</button></div>
        </form>
    </div></div></div>

    {{-- Modal Edit + Detail per baris --}}
    @foreach($journal->submissions as $s)
        <div class="modal fade" id="subEdit{{ $s->id }}" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content">
            <form method="POST" action="{{ route('journal.submission.update', $s->id) }}" enctype="multipart/form-data">
                @csrf @method('PUT')
                <div class="modal-header"><h6 class="modal-title">Edit Submission</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    @include('journals.partials.submission-fields', ['s' => $s])
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">Batal</button><button type="submit" class="btn btn-sm btn-primary">Simpan</button></div>
            </form>
        </div></div></div>

        <div class="modal fade" id="subDetail{{ $s->id }}" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
            <div class="modal-header"><h6 class="modal-title">Detail Submission</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body small">
                <dl class="row mb-0">
                    <dt class="col-4 text-muted">Judul</dt><dd class="col-8">{{ $s->title?->title ?? '—' }}</dd>
                    <dt class="col-4 text-muted">Submit</dt><dd class="col-8">{{ optional($s->tgl_submit)->format('d M Y') ?? '—' }}</dd>
                    <dt class="col-4 text-muted">Terbit</dt><dd class="col-8">{{ optional($s->tgl_terbit)->format('d M Y') ?? '—' }}</dd>
                    <dt class="col-4 text-muted">Status</dt><dd class="col-8">{{ $s->statusLabel() }}</dd>
                    <dt class="col-4 text-muted">OJS Akun</dt><dd class="col-8">{{ $s->ojs_akun ?: '—' }}</dd>
                    <dt class="col-4 text-muted">OJS Password</dt><dd class="col-8">{{ $s->ojs_password ?: '—' }}</dd>
                    <dt class="col-4 text-muted">LoA</dt><dd class="col-8">@if($s->loa_url)<a href="{{ $s->loa_url }}" target="_blank" rel="noopener">buka</a>@else—@endif</dd>
                    <dt class="col-4 text-muted">Bukti Bayar</dt><dd class="col-8">@if($s->bukti_bayar_url)<a href="{{ $s->bukti_bayar_url }}" target="_blank" rel="noopener">buka</a>@else—@endif</dd>
                    <dt class="col-4 text-muted">Link Publish</dt><dd class="col-8">@if($s->link_publish)<a href="{{ $s->link_publish }}" target="_blank" rel="noopener">{{ $s->link_publish }}</a>@else—@endif</dd>
                    <dt class="col-4 text-muted">Catatan</dt><dd class="col-8">{{ $s->catatan ?: '—' }}</dd>
                </dl>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-sm btn-light" data-bs-dismiss="modal">Tutup</button></div>
        </div></div></div>
    @endforeach
@endif
@endsection
```

- [ ] **Step 5: Shared modal fields partial** — create `resources/views/journals/partials/submission-fields.blade.php`:

```blade
{{-- $s = JournalSubmission|null. Password TIDAK dipopulasi (isi hanya bila mengganti). --}}
<div class="row">
    <div class="col-md-8 mb-2">
        <label class="form-label">Judul Artikel</label>
        <select name="title_id" class="form-select">
            <option value="">— tak tertaut —</option>
            @foreach($articles as $a)
                <option value="{{ $a->id }}" {{ optional($s)->title_id == $a->id ? 'selected' : '' }}>{{ $a->title }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-4 mb-2">
        <label class="form-label">Status <span class="text-danger">*</span></label>
        <select name="status" class="form-select">
            @foreach(['submitted' => 'Submitted', 'loa' => 'LoA', 'published' => 'Published'] as $val => $lab)
                <option value="{{ $val }}" {{ (optional($s)->status ?? 'submitted') === $val ? 'selected' : '' }}>{{ $lab }}</option>
            @endforeach
        </select>
    </div>
</div>
<div class="row">
    <div class="col-md-6 mb-2"><label class="form-label">Tgl Submit</label><input type="date" name="tgl_submit" class="form-control" value="{{ optional(optional($s)->tgl_submit)->format('Y-m-d') }}"></div>
    <div class="col-md-6 mb-2"><label class="form-label">Tgl Terbit</label><input type="date" name="tgl_terbit" class="form-control" value="{{ optional(optional($s)->tgl_terbit)->format('Y-m-d') }}"></div>
</div>
<div class="row">
    <div class="col-md-6 mb-2"><label class="form-label">OJS Akun</label><input type="text" name="ojs_akun" class="form-control" value="{{ optional($s)->ojs_akun }}"></div>
    <div class="col-md-6 mb-2"><label class="form-label">OJS Password</label><input type="text" name="ojs_password" class="form-control" placeholder="{{ $s ? 'kosongkan bila tak diubah' : '' }}"></div>
</div>
<div class="row">
    <div class="col-md-6 mb-2"><label class="form-label">File LoA (pdf/gambar)</label><input type="file" name="loa" class="form-control">@if(optional($s)->loa_url)<small><a href="{{ $s->loa_url }}" target="_blank" rel="noopener">LoA saat ini</a></small>@endif</div>
    <div class="col-md-6 mb-2"><label class="form-label">Bukti Bayar</label><input type="file" name="bukti_bayar" class="form-control">@if(optional($s)->bukti_bayar_url)<small><a href="{{ $s->bukti_bayar_url }}" target="_blank" rel="noopener">Bukti saat ini</a></small>@endif</div>
</div>
<div class="mb-2"><label class="form-label">Link Artikel Publish</label><input type="text" name="link_publish" class="form-control" value="{{ optional($s)->link_publish }}"></div>
<div class="mb-0"><label class="form-label">Catatan</label><textarea name="catatan" class="form-control" rows="2">{{ optional($s)->catatan }}</textarea></div>
```

- [ ] **Step 6: Compile + run**

Run: `php artisan view:cache` (clean) then `php artisan view:clear`.
Run: `php artisan test --filter="JournalSubmissionPagesTest|JournalSubmissionTest|JournalPagesTest|JournalControllerTest"`
Expected: PASS all.

- [ ] **Step 7: Commit**

```
git add app/Http/Controllers/Pages/JournalController.php resources/views/journals/show.blade.php resources/views/journals/partials/submission-fields.blade.php tests/Feature/JournalSubmissionPagesTest.php
git commit -m "feat(journal-sub): detail submissions table + create/edit/detail modals

Co-authored-by: Mira <admin@avidpedia.com>"
```

---

## Task 4: Full verification + migrate dev

**Files:** none.

- [ ] **Step 1: Whole suite**

Run: `php artisan test`
Expected: PASS all (356 + JournalSubmissionTest (4) + JournalSubmissionPagesTest (2) = ~362).

- [ ] **Step 2: Compile Blade**

Run: `php artisan view:cache` (no error) → `php artisan view:clear`.

- [ ] **Step 3: Migrate dev DB**

Run: `php artisan migrate --force` (buat tabel `tb_journal_submissions`). See [[migrate-dev-db-after-new-migration]].

- [ ] **Step 4: Smoke (opsional)**

Login manager → Direktori Jurnal → detail jurnal → "+ Tambah Artikel Submit" → isi (pilih artikel, tanggal, OJS, upload LoA/bukti, status) → simpan → muncul di tabel; Detail (lihat OJS + link LoA); Edit (password kosong = tetap); Hapus (SweetAlert2). Login marketing → lihat daftar, tanpa tombol kelola.

---

## Catatan & Risiko

- `ojs_password` `encrypted` cast → tak plaintext di DB; ditampilkan hanya di modal detail/edit yang di-guard `canManage`; TIDAK dimuat ke `data-*` (modal edit membiarkan field password kosong).
- Modal edit/detail per baris (server-render) → tak ada populasi JS, tak ada kebocoran kredensial ke DOM global. Banyak markup bila submission banyak (dapat dioptimasi kelak).
- Upload LoA/bukti via `GoogleDriveService::uploadFile`; gagal upload → `*_url` null (submit tetap tersimpan). Hapus file Drive saat destroy di luar scope.
- Submission menaut Title artikel sebagai referensi; tak menggerakkan `TitleProgress` (auto-sync = fase lanjutan). Integrasi panel publikasi judul = Fase C.
